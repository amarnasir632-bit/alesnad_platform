<?php
declare(strict_types=1);

function subscription_positive_id(mixed $value, string $field): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new ApiException(422, 'validation_failed', "{$field} يجب أن يكون عددًا صحيحًا موجبًا.");
    return $id;
}

function subscription_text(mixed $value, string $field, int $max, bool $required = true): ?string
{
    if ($value === null && !$required) return null;
    if (!is_string($value)) throw new ApiException(422, 'validation_failed', "{$field} يجب أن يكون نصًا.");
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ($length === false || ($required && $value === '') || $length > $max) throw new ApiException(422, 'validation_failed', "{$field} مطلوب وبحد أقصى {$max} محرفًا.");
    return $value;
}

function subscription_timestamp(mixed $value, string $field): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) throw new ApiException(422, 'validation_failed', "{$field} يجب أن يكون تاريخ ISO-8601 مع منطقة زمنية.");
    try {
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) throw new RuntimeException('Invalid timestamp.');
        return $date->format(DateTimeInterface::ATOM);
    }
    catch (Throwable) { throw new ApiException(422, 'validation_failed', "{$field} غير صالح."); }
}

function subscription_money(mixed $value, string $field, bool $required): ?string
{
    if ($value === null && !$required) return null;
    if (!is_string($value) && !is_int($value) && !is_float($value)) throw new ApiException(422, 'validation_failed', "{$field} يجب أن يكون مبلغًا رقميًا.");
    $raw = trim((string) $value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $raw)) throw new ApiException(422, 'validation_failed', "{$field} يجب أن يكون مبلغًا غير سالب حتى منزلتين عشريتين.");
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    return $whole . '.' . str_pad($fraction, 2, '0');
}

function subscription_currency(mixed $value, string $field, bool $required): ?string
{
    if ($value === null && !$required) return null;
    if (!is_string($value) || !preg_match('/^[A-Za-z]{3}$/D', trim($value))) throw new ApiException(422, 'validation_failed', "{$field} يجب أن يكون رمز عملة من ثلاثة أحرف.");
    return strtoupper(trim($value));
}

/** Receives a user id; expiry is reconciled before this access decision. */
function has_active_subscription(int $userId): bool
{
    $db = database_connection();
    $studentQuery = $db->prepare('SELECT id FROM students WHERE user_id = :user_id');
    $studentQuery->execute(['user_id' => $userId]);
    $studentId = $studentQuery->fetchColumn();
    if ($studentId === false) return false;

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $expired = $db->prepare("UPDATE subscriptions SET status = 'EXPIRED' WHERE student_id = :student_id AND status = 'ACTIVE' AND ends_at <= CURRENT_TIMESTAMP RETURNING id");
        $expired->execute(['student_id' => (int) $studentId]);
        foreach ($expired->fetchAll(PDO::FETCH_COLUMN) as $subscriptionId) {
            audit_log('subscription.expired', 'subscription', (int) $subscriptionId, ['student_id' => (int) $studentId], $userId);
        }
        $active = $db->prepare("SELECT EXISTS (SELECT 1 FROM subscriptions WHERE student_id = :student_id AND status = 'ACTIVE' AND starts_at <= CURRENT_TIMESTAMP AND ends_at > CURRENT_TIMESTAMP)");
        $active->execute(['student_id' => (int) $studentId]);
        $result = database_boolean($active->fetchColumn());
        if ($ownsTransaction) $db->commit();
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function has_manual_content_access(int $userId): bool
{
    $statement = database_connection()->prepare(
        'SELECT EXISTS (SELECT 1 FROM manual_content_access mca JOIN students st ON st.id = mca.student_id WHERE st.user_id = :user_id AND mca.revoked_at IS NULL)'
    );
    $statement->execute(['user_id' => $userId]);
    return database_boolean($statement->fetchColumn());
}

function subscription_expire_all(?int $actorId = null): int
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $expired = $db->prepare("UPDATE subscriptions SET status = 'EXPIRED' WHERE status = 'ACTIVE' AND ends_at <= CURRENT_TIMESTAMP RETURNING id, student_id");
        $expired->execute();
        $rows = $expired->fetchAll();
        foreach ($rows as $row) audit_log('subscription.expired', 'subscription', (int) $row['id'], ['student_id' => (int) $row['student_id']], $actorId);
        if ($rows !== []) audit_log('subscription.expiration_batch_completed', 'subscription', null, ['expired_count' => count($rows)], $actorId);
        $db->commit();
        return count($rows);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function subscription_plan_list(bool $includeInactive = false): array
{
    $sql = 'SELECT id, code, name, description, duration_days, price, currency, is_active, created_at FROM subscription_plans';
    if (!$includeInactive) $sql .= ' WHERE is_active = TRUE';
    $sql .= ' ORDER BY name, id';
    $stmt = database_connection()->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

function subscription_plan_input(array $input, ?array $existing = null): array
{
    $code = array_key_exists('code', $input) ? strtoupper(subscription_text($input['code'], 'code', 50)) : ($existing['code'] ?? null);
    if ($code !== null && !preg_match('/^[A-Z][A-Z0-9_-]{1,49}$/D', $code)) throw new ApiException(422, 'validation_failed', 'code يبدأ بحرف ويتكون من أحرف لاتينية وأرقام وشرطة فقط.');
    $name = array_key_exists('name', $input) ? subscription_text($input['name'], 'name', 120) : ($existing['name'] ?? null);
    if ($name === null) throw new ApiException(422, 'validation_failed', 'name مطلوب.');
    $description = array_key_exists('description', $input) ? subscription_text($input['description'], 'description', 5000, false) : ($existing['description'] ?? null);
    $duration = array_key_exists('duration_days', $input) ? filter_var($input['duration_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 32767]]) : ($existing['duration_days'] ?? false);
    if ($duration === false || $duration === null) throw new ApiException(422, 'validation_failed', 'duration_days مطلوب ويجب أن يكون موجبًا.');
    $price = array_key_exists('price', $input) ? subscription_money($input['price'], 'price', false) : ($existing['price'] ?? null);
    $currency = array_key_exists('currency', $input) ? subscription_currency($input['currency'], 'currency', false) : ($existing['currency'] ?? null);
    if (($price === null) !== ($currency === null)) throw new ApiException(422, 'plan_price_currency_pair_required', 'أدخل السعر والعملة معًا، أو اتركهما دون تحديد.');
    $active = array_key_exists('is_active', $input)
        ? (is_bool($input['is_active']) ? $input['is_active'] : throw new ApiException(422, 'validation_failed', 'is_active يجب أن تكون قيمة منطقية.'))
        : ($existing ? database_boolean($existing['is_active']) : false);
    return ['code' => $code, 'name' => $name, 'description' => $description, 'duration_days' => (int) $duration, 'price' => $price, 'currency' => $currency, 'is_active' => $active];
}

function subscription_create_plan(array $input, int $actorId): array
{
    $allowed = ['code', 'name', 'description', 'duration_days', 'price', 'currency', 'is_active', '_csrf'];
    foreach ($input as $key => $_) if (!in_array($key, $allowed, true)) throw new ApiException(422, 'validation_failed', "الحقل {$key} غير قابل للإدخال.");
    $data = subscription_plan_input($input);
    $db = database_connection();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO subscription_plans (code, name, description, duration_days, price, currency, is_active) VALUES (:code, :name, :description, :duration_days, :price, :currency, :is_active) RETURNING id, code, name, description, duration_days, price, currency, is_active, created_at');
        $stmt->execute($data);
        $row = $stmt->fetch();
        audit_log('subscription_plan.created', 'subscription_plan', (int) $row['id'], ['code' => $row['code']], $actorId);
        $db->commit();
        return $row;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function subscription_update_plan(int $planId, array $input, int $actorId): array
{
    $allowed = ['code', 'name', 'description', 'duration_days', 'price', 'currency', 'is_active', '_csrf'];
    foreach ($input as $key => $_) if (!in_array($key, $allowed, true)) throw new ApiException(422, 'validation_failed', "الحقل {$key} غير قابل للتعديل.");
    unset($input['_csrf']);
    if ($input === []) throw new ApiException(422, 'validation_failed', 'أرسل حقلًا واحدًا على الأقل.');
    $db = database_connection();
    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT * FROM subscription_plans WHERE id = :id FOR UPDATE');
        $select->execute(['id' => $planId]);
        $existing = $select->fetch();
        if (!$existing) throw new ApiException(404, 'not_found', 'الخطة غير موجودة.');
        $data = subscription_plan_input($input, $existing);
        $stmt = $db->prepare('UPDATE subscription_plans SET code = :code, name = :name, description = :description, duration_days = :duration_days, price = :price, currency = :currency, is_active = :is_active WHERE id = :id RETURNING id, code, name, description, duration_days, price, currency, is_active, created_at');
        $stmt->execute($data + ['id' => $planId]);
        $row = $stmt->fetch();
        audit_log('subscription_plan.updated', 'subscription_plan', $planId, ['fields' => array_keys($input)], $actorId);
        $db->commit();
        return $row;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function subscription_list_own(int $userId): array
{
    $student = database_connection()->prepare('SELECT id FROM students WHERE user_id = :user_id');
    $student->execute(['user_id' => $userId]);
    $studentId = $student->fetchColumn();
    if ($studentId === false) throw new ApiException(403, 'student_profile_required', 'يلزم ملف طالب لعرض الاشتراكات.');
    has_active_subscription($userId);
    $stmt = database_connection()->prepare('SELECT sub.id, sub.plan_id, p.name AS plan_name, sub.starts_at, sub.ends_at, sub.amount_paid, sub.currency, sub.status, sub.created_at FROM subscriptions sub JOIN subscription_plans p ON p.id = sub.plan_id WHERE sub.student_id = :student_id ORDER BY sub.created_at DESC, sub.id DESC');
    $stmt->execute(['student_id' => (int) $studentId]);
    return $stmt->fetchAll();
}

function subscription_list_admin(array $filters = [], ?int $actorId = null): array
{
    subscription_expire_all($actorId);
    $where = [];
    $params = [];
    if (isset($filters['student_id'])) { $where[] = 'sub.student_id = :student_id'; $params['student_id'] = $filters['student_id']; }
    if (isset($filters['status'])) { $where[] = 'sub.status = :status'; $params['status'] = $filters['status']; }
    $sql = 'SELECT sub.id, sub.student_id, u.id AS user_id, u.full_name AS student_name, u.email AS student_email, sub.plan_id, p.name AS plan_name, sub.starts_at, sub.ends_at, sub.amount_paid, sub.currency, sub.status, sub.created_at FROM subscriptions sub JOIN students st ON st.id = sub.student_id JOIN users u ON u.id = st.user_id JOIN subscription_plans p ON p.id = sub.plan_id';
    if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY sub.created_at DESC, sub.id DESC LIMIT :limit OFFSET :offset';
    $stmt = database_connection()->prepare($sql);
    foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
    $stmt->bindValue(':limit', max(1, min(100, $filters['limit'] ?? 50)), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $filters['offset'] ?? 0), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function subscription_create_manual(array $input, int $actorId): array
{
    $allowed = ['student_id', 'plan_id', 'starts_at', 'ends_at', 'amount_paid', 'currency', 'status', '_csrf'];
    foreach ($input as $key => $_) if (!in_array($key, $allowed, true)) throw new ApiException(422, 'validation_failed', "الحقل {$key} غير قابل للإدخال.");
    $studentId = subscription_positive_id($input['student_id'] ?? null, 'student_id');
    $planId = subscription_positive_id($input['plan_id'] ?? null, 'plan_id');
    $startsAt = subscription_timestamp($input['starts_at'] ?? null, 'starts_at');
    $amount = subscription_money($input['amount_paid'] ?? null, 'amount_paid', true);
    $currency = subscription_currency($input['currency'] ?? null, 'currency', true);
    $status = is_string($input['status'] ?? null) ? strtoupper(trim($input['status'])) : '';
    if (!in_array($status, ['PENDING', 'ACTIVE'], true)) throw new ApiException(422, 'validation_failed', 'اختر حالة PENDING أو ACTIVE عند الإنشاء.');
    $db = database_connection();
    $db->beginTransaction();
    try {
        $student = $db->prepare('SELECT st.id FROM students st JOIN users u ON u.id = st.user_id WHERE st.id = :student_id AND u.is_active = TRUE FOR UPDATE OF st');
        $student->execute(['student_id' => $studentId]);
        if ($student->fetchColumn() === false) throw new ApiException(422, 'invalid_student', 'الطالب غير موجود أو حسابه غير نشط.');
        $plan = $db->prepare('SELECT id, duration_days FROM subscription_plans WHERE id = :plan_id AND is_active = TRUE FOR SHARE');
        $plan->execute(['plan_id' => $planId]);
        $planRow = $plan->fetch();
        if (!$planRow) throw new ApiException(422, 'inactive_plan', 'الخطة غير موجودة أو غير نشطة.');
        if (array_key_exists('ends_at', $input) && $input['ends_at'] !== null && $input['ends_at'] !== '') $endsAt = subscription_timestamp($input['ends_at'], 'ends_at');
        else $endsAt = (new DateTimeImmutable($startsAt))->modify('+' . (int) $planRow['duration_days'] . ' days')->format(DateTimeInterface::ATOM);
        if (new DateTimeImmutable($endsAt) <= new DateTimeImmutable($startsAt)) throw new ApiException(422, 'invalid_subscription_period', 'نهاية الاشتراك يجب أن تكون بعد بدايته.');
        $stmt = $db->prepare('INSERT INTO subscriptions (student_id, plan_id, starts_at, ends_at, amount_paid, currency, status) VALUES (:student_id, :plan_id, :starts_at, :ends_at, :amount_paid, :currency, :status) RETURNING id, student_id, plan_id, starts_at, ends_at, amount_paid, currency, status, created_at');
        $stmt->execute(['student_id' => $studentId, 'plan_id' => $planId, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'amount_paid' => $amount, 'currency' => $currency, 'status' => $status]);
        $row = $stmt->fetch();
        audit_log('subscription.created_manually', 'subscription', (int) $row['id'], ['student_id' => $studentId, 'plan_id' => $planId, 'status' => $status], $actorId);
        $db->commit();
        return $row;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function subscription_update_manual(int $subscriptionId, array $input, int $actorId): array
{
    unset($input['_csrf']);
    $allowed = ['status', 'starts_at', 'ends_at', 'amount_paid', 'currency'];
    foreach ($input as $key => $_) if (!in_array($key, $allowed, true)) throw new ApiException(422, 'validation_failed', "الحقل {$key} غير قابل للتعديل.");
    if ($input === []) throw new ApiException(422, 'validation_failed', 'أرسل حقلًا واحدًا على الأقل.');
    $db = database_connection();
    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT * FROM subscriptions WHERE id = :id FOR UPDATE');
        $select->execute(['id' => $subscriptionId]);
        $old = $select->fetch();
        if (!$old) throw new ApiException(404, 'not_found', 'الاشتراك غير موجود.');
        $status = $old['status'];
        if (array_key_exists('status', $input)) {
            $status = is_string($input['status']) ? strtoupper(trim($input['status'])) : '';
            if (!in_array($status, ['PENDING', 'ACTIVE', 'EXPIRED', 'CANCELLED', 'REFUNDED'], true)) throw new ApiException(422, 'validation_failed', 'حالة الاشتراك غير صالحة.');
        }
        $startsAt = array_key_exists('starts_at', $input) ? subscription_timestamp($input['starts_at'], 'starts_at') : $old['starts_at'];
        $endsAt = array_key_exists('ends_at', $input) ? subscription_timestamp($input['ends_at'], 'ends_at') : $old['ends_at'];
        if (new DateTimeImmutable($endsAt) <= new DateTimeImmutable($startsAt)) throw new ApiException(422, 'invalid_subscription_period', 'نهاية الاشتراك يجب أن تكون بعد بدايته.');
        $amount = array_key_exists('amount_paid', $input) ? subscription_money($input['amount_paid'], 'amount_paid', true) : $old['amount_paid'];
        $currency = array_key_exists('currency', $input) ? subscription_currency($input['currency'], 'currency', true) : $old['currency'];
        $stmt = $db->prepare('UPDATE subscriptions SET status = :status, starts_at = :starts_at, ends_at = :ends_at, amount_paid = :amount_paid, currency = :currency WHERE id = :id RETURNING id, student_id, plan_id, starts_at, ends_at, amount_paid, currency, status, created_at');
        $stmt->execute(['status' => $status, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'amount_paid' => $amount, 'currency' => $currency, 'id' => $subscriptionId]);
        $row = $stmt->fetch();
        audit_log('subscription.updated_manually', 'subscription', $subscriptionId, ['fields' => array_keys($input), 'status' => $status], $actorId);
        $db->commit();
        return $row;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
