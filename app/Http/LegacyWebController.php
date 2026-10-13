<?php
declare(strict_types=1);

function handle_web_request(): void
{
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
    $errors = [];
    $old = [];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'register') {
            $fullName = post_string('full_name');
            $email = strtolower(post_string('email'));
            $phone = post_string('phone');
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
            $old = ['full_name' => $fullName, 'email' => $email, 'phone' => $phone];
            $nameLength = preg_match_all('/./us', $fullName);
            if ($nameLength === false || $nameLength < 2 || $nameLength > 150) $errors[] = 'أدخل الاسم كاملًا (من حرفين إلى 150 حرفاً).';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'أدخل بريدًا إلكترونيًا صحيحًا.';
            if ($phone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $phone)) $errors[] = 'أدخل رقم هاتف صحيحًا أو اتركه فارغًا.';
            if (($passwordError = password_validation_error($password)) !== null) $errors[] = $passwordError;
            if (!hash_equals($password, $confirmation)) $errors[] = 'تأكيد كلمة المرور غير مطابق.';
            if (!$errors) {
                try {
                    register_student($fullName, $email, $phone, $password);
                    flash('success', 'تم إنشاء حسابك. يتطلب المحتوى المدفوع اشتراكًا نشطًا.');
                    redirect(url('home'));
                } catch (PDOException $error) {
                    if ($error->getCode() === '23505') $errors[] = 'هذا البريد الإلكتروني مسجل بالفعل.';
                    else { app_log_error('registration_database_failed', $error); $errors[] = 'تعذر إنشاء الحساب الآن. حاول مرة أخرى لاحقًا.'; }
                } catch (Throwable $error) {
                    app_log_error('registration_failed', $error);
                    $errors[] = 'تعذر إنشاء الحساب الآن. تأكد من إعداد قاعدة البيانات.';
                }
            }
            $page = 'register';
        } elseif ($action === 'login') {
            $email = strtolower(post_string('email'));
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') $errors[] = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
            else {
                try {
                    if (authenticate_user($email, $password)) {
                        flash('success', 'مرحباً بعودتك.');
                        redirect(url('home'));
                    }
                    $errors[] = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
                } catch (Throwable $error) {
                    app_log_error('login_database_failed', $error);
                    $errors[] = 'تعذر تسجيل الدخول الآن. حاول مرة أخرى لاحقًا.';
                }
            }
            $old = ['email' => $email];
            $page = 'login';
        } elseif ($action === 'logout') {
            $user = refresh_authenticated_user();
            if ($user !== null) audit_log('auth.logout', 'user', (int) $user['id']);
            logout_user();
            session_start();
            session_regenerate_id(true);
            flash('success', 'تم تسجيل الخروج.');
            redirect(url('home'));
        } else {
            http_response_code(400);
            exit('طلب غير معروف.');
        }
    }

    $title = 'منصة الإسناد الأكاديمي';
    $contentView = PROJECT_ROOT . '/app/Views/home.php';
    $data = [];
    try {
        if ($page === 'subjects' || $page === 'home') {
            $data['subjects'] = list_class_subjects($page === 'home' ? 3 : 50);
            if ($page === 'subjects') { $title = 'المواد الدراسية'; $contentView = PROJECT_ROOT . '/app/Views/subjects.php'; }
        } elseif ($page === 'subject') {
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            $data['subject'] = $id ? find_class_subject($id) : null;
            if (!$data['subject']) { http_response_code(404); $title = 'الصفحة غير موجودة'; $contentView = PROJECT_ROOT . '/app/Views/not_found.php'; }
            else { $title = $data['subject']['subject_name'] . ' | المواد الدراسية'; $contentView = PROJECT_ROOT . '/app/Views/subject.php'; }
        } elseif ($page === 'register') { $title = 'إنشاء حساب'; $contentView = PROJECT_ROOT . '/app/Views/register.php'; }
        elseif ($page === 'login') { $title = 'تسجيل الدخول'; $contentView = PROJECT_ROOT . '/app/Views/login.php'; }
        else { http_response_code(404); $title = 'الصفحة غير موجودة'; $contentView = PROJECT_ROOT . '/app/Views/not_found.php'; }
    } catch (Throwable $error) {
        app_log_error('page_database_failed', $error);
        http_response_code(503); $title = 'الخدمة غير متاحة'; $contentView = PROJECT_ROOT . '/app/Views/unavailable.php';
    }
    extract($data, EXTR_SKIP);
    $flashes = consume_flashes();
    require PROJECT_ROOT . '/app/Views/layout.php';
}
