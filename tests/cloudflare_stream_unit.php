<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Http/Response.php';
require_once __DIR__ . '/../app/Services/LiveMeetingProvider.php';
require_once __DIR__ . '/../app/Services/VideoUploadProvider.php';
require_once __DIR__ . '/../app/Services/CloudflareWebhookService.php';
require_once __DIR__ . '/../app/Services/SessionMessagingService.php';
require_once __DIR__ . '/../app/Services/ContentService.php';

echo "=== اختبارات وحدة تكامل Cloudflare Stream ونظام البث المباشر ===\n";

$passedTests = 0;

function it(string $description, callable $fn): void {
    global $passedTests;
    try {
        $fn();
        $passedTests++;
        echo "  PASS: {$description}\n";
    } catch (Throwable $e) {
        echo "  FAIL: {$description}\n";
        echo "        " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")\n";
        exit(1);
    }
}

// 1. اختبار تطبيع customerCode في CloudflareStreamProvider
it("تطبيع Customer Code من الروابط والنطاقات المختلفة", function () {
    putenv("STREAM_PROVIDER=cloudflare_stream");
    putenv("CLOUDFLARE_ACCOUNT_ID=test_account_12345");
    putenv("CLOUDFLARE_API_TOKEN=test_token_secret_12345");
    
    // Test with customer- prefix and domain
    putenv("CLOUDFLARE_STREAM_CUSTOMER_CODE=customer-m47xabc123.cloudflarestream.com");
    $provider = new CloudflareStreamProvider();
    $reflection = new ReflectionClass($provider);
    $prop = $reflection->getProperty("customerCode");
    $prop->setAccessible(true);
    assert($prop->getValue($provider) === "m47xabc123", "يجب استخراج الكود الصافي دون بادئة أو لاحقة");

    // Test with plain code
    putenv("CLOUDFLARE_STREAM_CUSTOMER_CODE=m47xabc123");
    $provider2 = new CloudflareStreamProvider();
    assert($prop->getValue($provider2) === "m47xabc123", "يجب قبول الكود الصافي مباشرة");
});

// 2. اختبار رفض العمل عند غياب بيانات الاعتماد
it("رفض تشغيل Cloudflare Stream إذا كانت الإعدادات مفقودة", function () {
    putenv("CLOUDFLARE_ACCOUNT_ID=");
    putenv("CLOUDFLARE_API_TOKEN=");
    putenv("CLOUDFLARE_STREAM_CUSTOMER_CODE=");
    $failed = false;
    try {
        new CloudflareStreamProvider();
    } catch (ApiException $e) {
        $failed = ($e->status === 503 && $e->apiCode === 'broadcast_provider_not_configured');
    }
    assert($failed, "يجب رمي استثناء 503 broadcast_provider_not_configured");
});

// 3. اختبار التحقق الأمني من توقيع Webhook (HMAC-SHA256)
it("التحقق من صحة توقيع Cloudflare Stream Webhook", function () {
    $secret = "webhook_test_secret_key_999888";
    putenv("CLOUDFLARE_STREAM_WEBHOOK_SECRET={$secret}");

    $payload = json_encode([
        "uid" => "mock_video_uid_12345",
        "status" => ["state" => "ready"],
        "duration" => 125.5,
        "size" => 10485760
    ], JSON_THROW_ON_ERROR);

    $now = time();
    $signedPayload = $now . "." . $payload;
    $validSignature = hash_hmac("sha256", $signedPayload, $secret);
    $validHeader = "time={$now},sig1={$validSignature}";

    // فحص رفض التوقيع الخاطئ
    $rejected = false;
    try {
        handle_cloudflare_stream_webhook($payload, "time={$now},sig1=wrong_signature_here");
    } catch (ApiException $e) {
        $rejected = ($e->status === 401 && $e->apiCode === 'invalid_signature');
    }
    assert($rejected, "يجب رفض التوقيع غير المطابق برمز 401");

    // فحص رفض التوقيع منتهي الصلاحية (أكثر من 5 دقائق)
    $expiredTime = $now - 400;
    $expiredSig = hash_hmac("sha256", $expiredTime . "." . $payload, $secret);
    $expiredRejected = false;
    try {
        handle_cloudflare_stream_webhook($payload, "time={$expiredTime},sig1={$expiredSig}");
    } catch (ApiException $e) {
        $expiredRejected = ($e->status === 401 && $e->apiCode === 'expired_signature');
    }
    assert($expiredRejected, "يجب رفض الطلب منتهي الصلاحية برمز 401");

    // فحص قبول التوقيع السليم
    $result = handle_cloudflare_stream_webhook($payload, $validHeader);
    assert($result['status'] === 'processed', "يجب قبول التوقيع السليم ومعالجة الحدث");
    assert($result['uid'] === 'mock_video_uid_12345', "يجب استخراج معرف الفيديو الصحيح");
    assert($result['processing_state'] === 'ready', "يجب تحديد حالة المعالجة جاهز");
});

// 4. اختبار تكامل دورة حياة طلبات التحدث (Voice Requests State Machine)
it("التحقق من دورة حياة طلبات المشاركة الصوتية (PENDING -> APPROVED -> REVOKED)", function () {
    $db = database_connection();
    
    // البحث عن حصة تجريبية أو إنشاؤها
    $session = $db->query("SELECT id, teacher_id FROM live_sessions LIMIT 1")->fetch();
    $student = $db->query("SELECT id, user_id FROM students LIMIT 1")->fetch();

    if ($session && $student) {
        $sessionId = (int) $session['id'];
        $studentId = (int) $student['id'];

        // إدراج طلب معلق
        $stmt = $db->prepare("
            INSERT INTO session_voice_requests (session_id, student_id, status)
            VALUES (:session_id, :student_id, 'PENDING')
            ON CONFLICT (session_id, student_id)
            DO UPDATE SET status = 'PENDING', updated_at = CURRENT_TIMESTAMP
            RETURNING id, status
        ");
        $stmt->execute(['session_id' => $sessionId, 'student_id' => $studentId]);
        $req = $stmt->fetch();
        assert($req['status'] === 'PENDING', "يجب أن تبدأ المشاركة الصوتية بحالة معلقة PENDING");

        // اعتماد الطلب من الأستاذ
        $upStmt = $db->prepare("
            UPDATE session_voice_requests
            SET status = 'APPROVED', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id RETURNING status
        ");
        $upStmt->execute(['id' => $req['id']]);
        assert($upStmt->fetchColumn() === 'APPROVED', "يجب أن تتحول الحالة إلى APPROVED عند موافقة الأستاذ");

        // كتم المايك
        $upStmt->execute(['id' => $req['id']]);
        $revStmt = $db->prepare("
            UPDATE session_voice_requests
            SET status = 'REVOKED', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id RETURNING status
        ");
        $revStmt->execute(['id' => $req['id']]);
        assert($revStmt->fetchColumn() === 'REVOKED', "يجب أن تتحول الحالة إلى REVOKED عند كتم المايك");
    }
});

// 5. التحقق من وجود الحقول الجديدة في PostgreSQL (المخطط 015)
it("التحقق من أعمدة المخطط 015 في PostgreSQL", function () {
    $db = database_connection();

    // فحص عمود lesson_id في live_sessions
    $col1 = $db->query("
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'live_sessions' AND column_name = 'lesson_id'
    ")->fetchColumn();
    assert($col1 !== false, "عمود lesson_id يجب أن يكون موجوداً في جدول live_sessions");

    // فحص عمود video_processing_status في learning_resources
    $col2 = $db->query("
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'learning_resources' AND column_name = 'video_processing_status'
    ")->fetchColumn();
    assert($col2 !== false, "عمود video_processing_status يجب أن يكون موجوداً في جدول learning_resources");

    // فحص جدول session_voice_requests
    $table1 = $db->query("
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'session_voice_requests'
    ")->fetchColumn();
    assert($table1 !== false, "جدول session_voice_requests يجب أن يكون منشأً بنجاح");
});

echo "\nتم اجتياز جميع اختبارات الوحدة بنجاح ({$passedTests} اختبارات)!\n";
