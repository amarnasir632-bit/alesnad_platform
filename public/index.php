<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self' data:; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'register') {
        $fullName = post_string('full_name');
        $email = strtolower(post_string('email'));
        $phone = post_string('phone');
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        $old = ['full_name' => $fullName, 'email' => $email, 'phone' => $phone];

        $nameLength = preg_match_all('/./us', $fullName, $nameMatches);
        if ($nameLength === false || $nameLength < 2 || $nameLength > 150) {
            $errors[] = 'أدخل الاسم كاملًا (من حرفين إلى 150 حرفاً).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'أدخل بريدًا إلكترونيًا صحيحًا.';
        }
        if ($phone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $phone)) {
            $errors[] = 'أدخل رقم هاتف صحيحًا أو اتركه فارغًا.';
        }
        if (strlen($password) < 12) {
            $errors[] = 'يجب ألا تقل كلمة المرور عن 12 حرفًا.';
        }
        if (!hash_equals($password, $confirmation)) {
            $errors[] = 'تأكيد كلمة المرور غير مطابق.';
        }

        if (!$errors) {
            try {
                register_student($fullName, $email, $phone, $password);
                flash('success', 'تم إنشاء حسابك. يتطلب المحتوى المدفوع اشتراكًا نشطًا.');
                redirect(url('home'));
            } catch (PDOException $error) {
                if ($error->getCode() === '23505') {
                    $errors[] = 'هذا البريد الإلكتروني مسجل بالفعل.';
                } else {
                    error_log('Registration database error SQLSTATE ' . $error->getCode());
                    $errors[] = 'تعذر إنشاء الحساب الآن. حاول مرة أخرى لاحقًا.';
                }
            } catch (Throwable $error) {
                error_log('Registration error: ' . $error->getMessage());
                $errors[] = 'تعذر إنشاء الحساب الآن. تأكد من إعداد قاعدة البيانات.';
            }
        }
        $page = 'register';
    } elseif ($action === 'login') {
        $email = strtolower(post_string('email'));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $errors[] = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
        } else {
            try {
                if (authenticate_user($email, $password)) {
                    flash('success', 'مرحباً بعودتك.');
                    redirect(url('home'));
                }
                $errors[] = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
            } catch (Throwable $error) {
                error_log('Login database error: ' . $error->getMessage());
                $errors[] = 'تعذر تسجيل الدخول الآن. حاول مرة أخرى لاحقًا.';
            }
        }
        $old = ['email' => $email];
        $page = 'login';
    } elseif ($action === 'logout') {
        logout_user();
        session_start();
        flash('success', 'تم تسجيل الخروج.');
        redirect(url('home'));
    } else {
        http_response_code(400);
        exit('طلب غير معروف.');
    }
}

$title = 'منصة الإسناد الأكاديمي';
$contentView = dirname(__DIR__) . '/app/Views/home.php';
$data = [];

try {
    if ($page === 'subjects' || $page === 'home') {
        $data['subjects'] = list_class_subjects($page === 'home' ? 3 : 50);
        if ($page === 'subjects') {
            $title = 'المواد الدراسية';
            $contentView = dirname(__DIR__) . '/app/Views/subjects.php';
        }
    } elseif ($page === 'subject') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $data['subject'] = $id ? find_class_subject($id) : null;
        if (!$data['subject']) {
            http_response_code(404);
            $title = 'الصفحة غير موجودة';
            $contentView = dirname(__DIR__) . '/app/Views/not_found.php';
        } else {
            $title = $data['subject']['subject_name'] . ' | المواد الدراسية';
            $contentView = dirname(__DIR__) . '/app/Views/subject.php';
        }
    } elseif ($page === 'register') {
        $title = 'إنشاء حساب';
        $contentView = dirname(__DIR__) . '/app/Views/register.php';
    } elseif ($page === 'login') {
        $title = 'تسجيل الدخول';
        $contentView = dirname(__DIR__) . '/app/Views/login.php';
    } else {
        http_response_code(404);
        $title = 'الصفحة غير موجودة';
        $contentView = dirname(__DIR__) . '/app/Views/not_found.php';
    }
} catch (Throwable $error) {
    error_log('Page database error: ' . $error->getMessage());
    http_response_code(503);
    $title = 'الخدمة غير متاحة';
    $contentView = dirname(__DIR__) . '/app/Views/unavailable.php';
}

$flashes = consume_flashes();
require dirname(__DIR__) . '/app/Views/layout.php';
