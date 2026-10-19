<?php
declare(strict_types=1);

function handle_web_request(): void
{
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self' data:; script-src 'self'; connect-src 'self' https://upload.videodelivery.net https://storage.googleapis.com; frame-src https://*.cloudflarestream.com https://player.mux.com; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $actor = current_user();
    if ($actor === null && !in_array($page, ['login', 'register', 'forgot_password', 'reset_password', 'complete_admission', 'accept_invitation'], true)) {
        if ($page === 'home') {
            $page = 'login';
        } else {
            redirect(url('login'));
        }
    } elseif ($actor !== null && in_array($page, ['login', 'register'], true)) {
        redirect(url('home'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'register') {
        $fullName = post_string('full_name');
        $email = strtolower(post_string('email'));
        $phone = post_string('phone');
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        $old = ['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'class_id' => $classId === false ? '' : (string) $classId];

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
        if ($classId === false || $classId < 1) $errors[] = 'اختر صفك الدراسي.';
        if (($passwordError = password_validation_error($password)) !== null) $errors[] = $passwordError;
        if (!hash_equals($password, $confirmation)) {
            $errors[] = 'تأكيد كلمة المرور غير مطابق.';
        }

        if (!$errors) {
            try {
                register_student($fullName, $email, $phone, $password, (int) $classId);
                flash('success', 'تم استلام طلب التسجيل. سيبقى الحساب غير متاح للدخول حتى تراجعه إدارة التسجيل والقبول.');
                redirect(url('login'));
            } catch (InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            } catch (PDOException $error) {
                if ($error->getCode() === '23505') {
                    $errors[] = 'هذا البريد الإلكتروني مسجل بالفعل.';
                } else {
                    app_log_error('legacy_web_database_failed', $error);
                    $errors[] = 'تعذر إنشاء الحساب الآن. حاول مرة أخرى لاحقًا.';
                }
            } catch (Throwable $error) {
                app_log_error('legacy_web_action_failed', $error);
                $errors[] = 'تعذر إنشاء الحساب الآن. تأكد من إعداد قاعدة البيانات.';
            }
        }
        $page = 'register';
    } elseif ($action === 'request_password_reset') {
        $email = strtolower(post_string('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'أدخل بريدًا إلكترونيًا صحيحًا.');
        } else {
            try {
                request_password_reset($email);
            } catch (Throwable $error) {
                app_log_error('password_reset_email_failed', $error);
            }
            flash('success', 'إذا كان البريد مرتبطًا بحساب، فستصلك رسالة برابط استعادة صالح لمدة محدودة.');
        }
        redirect(url('login'));
    } elseif ($action === 'reset_password') {
        $token = post_string('token');
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        try {
            if (!hash_equals($password, $confirmation)) throw new InvalidArgumentException('تأكيد كلمة المرور غير مطابق.');
            consume_password_reset($token, $password);
            flash('success', 'تم تغيير كلمة المرور. يمكنك تسجيل الدخول الآن.');
            redirect(url('login'));
        } catch (InvalidArgumentException $error) {
            $errors[] = $error->getMessage();
            $page = 'reset_password';
            $data['token'] = $token;
            $data['reset_token_valid'] = password_reset_token_is_valid($token);
        } catch (Throwable $error) {
            app_log_error('password_reset_completion_failed', $error);
            $errors[] = 'تعذر تغيير كلمة المرور الآن. حاول مرة أخرى.';
            $page = 'reset_password';
            $data['token'] = $token;
            $data['reset_token_valid'] = password_reset_token_is_valid($token);
        }
    } elseif ($action === 'complete_admission') {
        $token = post_string('token');
        try {
            complete_student_admission($token, [
                'full_name' => post_string('full_name'),
                'phone' => post_string('phone'),
                'class_id' => $_POST['class_id'] ?? null,
            ]);
            flash('success', 'تم إرسال البيانات المحدّثة لمراجعة التسجيل والقبول.');
            redirect(url('login'));
        } catch (InvalidArgumentException $error) {
            $errors[] = $error->getMessage();
            $page = 'complete_admission';
            $data['token'] = $token;
            $data['admission_token_valid'] = admission_completion_is_valid($token);
        } catch (Throwable $error) {
            app_log_error('admission_completion_failed', $error);
            $errors[] = 'تعذر استكمال الطلب الآن. حاول مرة أخرى.';
            $page = 'complete_admission';
            $data['token'] = $token;
            $data['admission_token_valid'] = admission_completion_is_valid($token);
        }
    } elseif ($action === 'review_admission') {
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($studentId === false) {
            flash('error', 'رقم طلب التسجيل غير صالح.');
        } else {
            $actor = require_permission('admissions.manage');
            try {
                $year = ($_POST['admission_year'] ?? '') === '' ? null : filter_var($_POST['admission_year'], FILTER_VALIDATE_INT);
                if ($year === false) throw new InvalidArgumentException('سنة القبول غير صالحة.');
                $classId = ($_POST['class_id'] ?? '') === '' ? null : filter_var($_POST['class_id'], FILTER_VALIDATE_INT);
                if ($classId === false) throw new InvalidArgumentException('الصف الدراسي غير صالح.');
                review_student_admission((int) $studentId, (int) $actor['id'], [
                    'status' => post_string('status'),
                    'reason' => post_string('reason'),
                    'class_id' => $classId,
                    'admission_year' => $year,
                    'academic_semester' => ($_POST['academic_semester'] ?? '') === '' ? null : post_string('academic_semester'),
                ]);
                flash('success', 'تم تحديث حالة طلب التسجيل.');
            } catch (Throwable $error) {
                if ($error instanceof InvalidArgumentException || $error instanceof OutOfBoundsException) {
                    flash('error', $error->getMessage());
                } else {
                    app_log_error('admission_review_failed', $error);
                    flash('error', 'تعذر حفظ قرار التسجيل الآن.');
                }
            }
        }
        redirect(url('admissions'));
    } elseif ($action === 'review_profile_correction') {
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actor = require_permission('admissions.manage');
        try {
            if ($requestId === false) throw new InvalidArgumentException('رقم طلب التصحيح غير صالح.');
            review_profile_correction_request(
                (int) $requestId,
                (int) $actor['id'],
                post_string('status'),
                post_string('resolution_reason')
            );
            flash('success', 'تم البت في طلب تصحيح بيانات الطالب.');
        } catch (Throwable $error) {
            if ($error instanceof InvalidArgumentException || $error instanceof OutOfBoundsException) {
                flash('error', $error->getMessage());
            } else {
                app_log_error('profile_correction_review_failed', $error);
                flash('error', 'تعذر حفظ قرار تصحيح البيانات الآن.');
            }
        }
        redirect(url('admissions'));
    } elseif ($action === 'login') {
        $email = strtolower(post_string('email'));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || strlen($password) > 1024) {
            $errors[] = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
        } else {
            try {
                if (authenticate_user($email, $password)) {
                    flash('success', 'مرحباً بعودتك.');
                    redirect(url('home'));
                }
                $errors[] = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
            } catch (Throwable $error) {
                app_log_error('legacy_web_action_failed', $error);
                $errors[] = 'تعذر تسجيل الدخول الآن. حاول مرة أخرى لاحقًا.';
            }
        }
        $old = ['email' => $email];
        $page = 'login';
    } elseif ($action === 'logout') {
        logout_user();
        flash('success', 'تم تسجيل الخروج.');
        redirect(url('home'));
    } elseif ($action === 'update_profile') {
        $user = require_login();
        try {
            $fullName = post_string('full_name');
            $phone = post_string('phone');
            $parentPhone = post_string('parent_phone');
            if (user_has_role('student')) {
                $requestId = submit_student_profile_correction((int) $user['id'], [
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'parent_phone' => $parentPhone,
                    'reason' => post_string('correction_reason'),
                ]);
                flash('success', 'تم إرسال طلب تصحيح البيانات رقم ' . $requestId . ' إلى مسؤول التسجيل والقبول.');
            } else {
                update_user_self_profile((int) $user['id'], [
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'parent_phone' => $parentPhone,
                ]);
                flash('success', 'تم حفظ التعديلات والبيانات بنجاح.');
            }
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر حفظ البيانات الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('account'));
    } elseif ($action === 'update_teacher_profile_image') {
        $user = require_login();
        try {
            $file = $_FILES['profile_image'] ?? [];
            if (!is_array($file)) throw new ApiException(422, 'invalid_upload', 'اختر صورة صالحة.');
            update_teacher_profile_image((int) $user['id'], $file);
            flash('success', 'تم تحديث صورة الملف الشخصي.');
        } catch (ApiException|InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('teacher_profile_image_update_failed', $error);
            flash('error', 'تعذر تحديث الصورة الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('account'));
    } elseif ($action === 'change_password') {
        $user = require_login();
        try {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['new_password_confirmation'] ?? '');
            change_user_password((int) $user['id'], $currentPassword, $newPassword, $confirmPassword);
            flash('success', 'تم تحديث كلمة المرور بنجاح.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر تحديث كلمة المرور الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('account'));
    } elseif ($action === 'question_create') {
        try {
            $user = require_permission('questions.manage');
            if (!user_has_permission('roles.manage') && !in_array('teacher', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('إنشاء الأسئلة متاح للأستاذ المسند إليه أو للمدير.');
            }
            $classSubjectId = filter_var($_POST['class_subject_id'] ?? null, FILTER_VALIDATE_INT);
            $lessonId = filter_var($_POST['lesson_id'] ?? null, FILTER_VALIDATE_INT);
            $correctOption = filter_var($_POST['correct_option'] ?? null, FILTER_VALIDATE_INT);
            $type = post_string('question_type');
            if ($type === 'TRUE_FALSE') {
                $correctOption = filter_var($_POST['correct_option_tf'] ?? null, FILTER_VALIDATE_INT);
            }
            $options = $_POST['options'] ?? [];
            if ($classSubjectId === false || $classSubjectId < 1 || ($lessonId !== false && $lessonId !== null && $lessonId < 1) || !is_array($options)) {
                throw new InvalidArgumentException('راجع المادة والدرس والخيارات المختارة.');
            }
            create_question(
                $classSubjectId,
                $lessonId !== false && $lessonId !== null && $lessonId > 0 ? $lessonId : null,
                post_string('question_text'),
                $type,
                post_string('difficulty'),
                post_string('explanation'),
                $options,
                $correctOption === false ? null : $correctOption,
                post_string('status'),
                $user['id']
            );
            flash('success', 'حُفظ السؤال. لن يظهر للطلاب حتى يعتمد مراجع المحتوى نشره.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر حفظ السؤال الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('questions'));
    } elseif ($action === 'question_review') {
        try {
            $user = require_permission('content.review');
            $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
            if ($questionId === false || $questionId < 1) {
                throw new InvalidArgumentException('اختر سؤالًا صحيحًا.');
            }
            review_question($questionId, $user['id'], post_string('decision'), post_string('feedback'));
            flash('success', 'تم تسجيل قرار المراجعة.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر حفظ قرار المراجعة الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('questions'));
    } elseif ($action === 'question_update') {
        try {
            $user = require_permission('questions.manage');
            if (!user_has_permission('roles.manage') && !in_array('teacher', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('تعديل الأسئلة متاح لمؤلف السؤال أو للمدير.');
            }
            $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
            $correctOption = filter_var($_POST['correct_option'] ?? null, FILTER_VALIDATE_INT);
            $options = $_POST['options'] ?? [];
            if ($questionId === false || $questionId < 1 || !is_array($options)) {
                throw new InvalidArgumentException('راجع رقم السؤال وخياراته.');
            }
            update_question(
                $questionId,
                post_string('question_text'),
                post_string('difficulty'),
                post_string('explanation'),
                $options,
                $correctOption === false ? null : $correctOption,
                post_string('status'),
                $user['id']
            );
            flash('success', 'تم تعديل السؤال وحفظه.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر تعديل السؤال الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('questions'));
    } elseif ($action === 'quiz_create') {
        try {
            $user = require_permission('quizzes.manage');
            $subjectId = filter_var($_POST['class_subject_id'] ?? null, FILTER_VALIDATE_INT);
            $marks = $_POST['question_marks'] ?? [];
            if ($subjectId === false || $subjectId < 1 || !is_array($marks)) { throw new InvalidArgumentException('اختر المادة والأسئلة.'); }
            $items = [];
            foreach ($marks as $questionId => $mark) {
                $items[] = ['question_id' => $questionId, 'question_mark' => $mark];
            }
            $quiz = quiz_create($user, [
                'class_subject_id' => $subjectId,
                'title' => post_string('title'),
                'description' => post_string('description'),
                'duration_minutes' => post_string('duration_minutes') !== '' ? post_string('duration_minutes') : null,
                'passing_marks' => post_string('passing_marks') !== '' ? post_string('passing_marks') : null,
                'questions' => $items,
            ]);
            $quizId = (int) $quiz['id'];
            if (!empty($_POST['publish'])) quiz_update($user, $quizId, ['is_published' => true]);
            flash('success', !empty($_POST['publish']) ? 'تم إنشاء الاختبار ونشره.' : 'تم حفظ الاختبار كمسودة.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر إنشاء الاختبار الآن.');
        }
        redirect(url('quizzes'));
    } elseif ($action === 'quiz_publish') {
        try {
            $user = require_permission('quizzes.manage');
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if ($quizId === false || $quizId < 1) { throw new InvalidArgumentException('الاختبار غير صالح.'); }
            quiz_update($user, $quizId, ['is_published' => post_string('publish') === '1']);
            flash('success', post_string('publish') === '1' ? 'تم نشر الاختبار.' : 'تم إيقاف نشر الاختبار.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر تحديث حالة الاختبار.');
        }
        redirect(url('quizzes'));
    } elseif ($action === 'quiz_start') {
        try {
            $user = require_permission('quizzes.attempt');
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if ($quizId === false || $quizId < 1) { throw new InvalidArgumentException('الاختبار غير صالح.'); }
            $started = quiz_start_attempt($user, $quizId);
            $attemptId = (int) $started['attempt']['id'];
            redirect(url('quiz_attempt', ['id'=>$attemptId]));
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
            redirect(url('quizzes'));
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر بدء الاختبار الآن.');
            redirect(url('quizzes'));
        }
    } elseif ($action === 'quiz_answer') {
        try {
            $user = require_permission('quizzes.attempt');
            $attemptId = filter_var($_POST['attempt_id'] ?? null, FILTER_VALIDATE_INT);
            $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
            $optionId = filter_var($_POST['selected_option_id'] ?? null, FILTER_VALIDATE_INT);
            if ($attemptId === false || $attemptId < 1 || $questionId === false || $questionId < 1) { throw new InvalidArgumentException('المحاولة أو السؤال غير صالح.'); }
            quiz_save_answer($user, $attemptId, $questionId, [
                'selected_option_id' => $optionId === false || $optionId === null ? null : $optionId,
                'essay_answer' => post_string('essay_answer'),
            ]);
            flash('success','تم حفظ الإجابة.');
            redirect(url('quiz_attempt',['id'=>$attemptId]));
        } catch (InvalidArgumentException $error) {
            flash('error',$error->getMessage());
            $attemptId = filter_var($_POST['attempt_id'] ?? null,FILTER_VALIDATE_INT);
            redirect(url('quiz_attempt',$attemptId !== false && $attemptId > 0 ? ['id'=>$attemptId] : []));
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error','تعذر حفظ الإجابة الآن.');
            redirect(url('quizzes'));
        }
    } elseif ($action === 'quiz_submit') {
        try {
            $user = require_permission('quizzes.attempt');
            $attemptId = filter_var($_POST['attempt_id'] ?? null, FILTER_VALIDATE_INT);
            if ($attemptId === false || $attemptId < 1) { throw new InvalidArgumentException('المحاولة غير صالحة.'); }
            quiz_submit_attempt($user, $attemptId);
            flash('success','تم تسليم الاختبار. تظهر النتيجة بعد التصحيح إذا تضمن أسئلة مقالية.');
            redirect(url('quiz_attempt',['id'=>$attemptId]));
        } catch (InvalidArgumentException $error) {
            flash('error',$error->getMessage());
            $attemptId = filter_var($_POST['attempt_id'] ?? null,FILTER_VALIDATE_INT);
            redirect(url('quiz_attempt',$attemptId !== false && $attemptId > 0 ? ['id'=>$attemptId] : []));
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error','تعذر تسليم الاختبار الآن.');
            redirect(url('quizzes'));
        }
    } elseif ($action === 'quiz_grade_essay') {
        try {
            $user = require_permission('quizzes.manage');
            $attemptId = filter_var($_POST['attempt_id'] ?? null,FILTER_VALIDATE_INT);
            $questionId = filter_var($_POST['question_id'] ?? null,FILTER_VALIDATE_INT);
            if($attemptId===false||$attemptId<1||$questionId===false||$questionId<1){throw new InvalidArgumentException('المحاولة أو السؤال غير صالح.');}
            quiz_grade_essay($user, $attemptId, $questionId, ['awarded_mark' => post_string('mark'), 'teacher_feedback' => post_string('feedback')]);
            flash('success','تم حفظ تصحيح الإجابة المقالية.');
            redirect(url('quiz_attempt',['id'=>$attemptId]));
        } catch (InvalidArgumentException $error) {
            flash('error',$error->getMessage());
            $attemptId=filter_var($_POST['attempt_id']??null,FILTER_VALIDATE_INT);
            redirect(url('quiz_attempt',$attemptId!==false&&$attemptId>0?['id'=>$attemptId]:[]));
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error','تعذر حفظ التصحيح الآن.');redirect(url('quizzes'));
        }
    } elseif ($action === 'quiz_manager_review_attempt') {
        try {
            $user = require_permission('quizzes.manage');
            $attemptId = filter_var($_POST['attempt_id'] ?? null, FILTER_VALIDATE_INT);
            $decision = post_string('decision');
            if ($attemptId === false || $attemptId < 1) throw new InvalidArgumentException('المحاولة غير صالحة.');
            quiz_manager_review_attempt($user, $attemptId, $decision);
            flash('success', $decision === 'approve_result' ? 'اعتمد المدير نتيجة الطالب.' : 'مُنح الطالب محاولة إضافية متاحة الآن.');
            redirect(url('quiz_attempt', ['id' => $attemptId]));
        } catch (Throwable $error) {
            if ($error instanceof ApiException) flash('error', $error->getMessage());
            else { app_log_error('legacy_web_action_failed', $error); flash('error', 'تعذر تنفيذ قرار الإدارة.'); }
            $attemptId = filter_var($_POST['attempt_id'] ?? null, FILTER_VALIDATE_INT);
            redirect(url('quiz_attempt', $attemptId !== false && $attemptId > 0 ? ['id' => $attemptId] : []));
        }
    } elseif ($action === 'library_upload') {
        try {
            $user = require_permission('content.upload');
            $classSubjectId = filter_var($_POST['class_subject_id'] ?? null, FILTER_VALIDATE_INT);
            if ($classSubjectId === false || $classSubjectId < 1) {
                throw new InvalidArgumentException('اختر المادة الدراسية.');
            }
            $kind = post_string('kind');
            $externalUrl = post_string('external_url');
            if ($kind === 'resource' && $externalUrl === '') {
                $resourceType = strtoupper(post_string('resource_type'));
                if (!in_array($resourceType, ['PDF', 'AUDIO'], true)) {
                    throw new InvalidArgumentException('ارفع الفيديو باستخدام خيار الفيديو المباشر، أو اختر PDF أو صوتًا.');
                }
                $lessonId = post_string('lesson_id');
                if ($resourceType === 'AUDIO' && $lessonId === '') {
                    throw new InvalidArgumentException('اختر الدرس المرتبط بالملف الصوتي.');
                }
                $resource = content_create_resource($user, [
                    'class_subject_id' => $classSubjectId,
                    'lesson_id' => $lessonId,
                    'title' => post_string('title'),
                    'resource_type' => $resourceType,
                    'description' => post_string('description'),
                    'source_reference' => post_string('source_reference'),
                    'source_owner' => post_string('source_owner'),
                    'copyright_license' => post_string('copyright_license'),
                ], is_array($_FILES['document'] ?? null) ? $_FILES['document'] : []);
                content_submit_for_review($user, 'resource', (int) $resource['id']);
            } else {
                create_library_item(
                    $classSubjectId,
                    $kind,
                    post_string('title'),
                    post_string('description'),
                    is_array($_FILES['document'] ?? null) ? $_FILES['document'] : [],
                    $externalUrl,
                    post_string('source_owner'),
                    post_string('copyright_license'),
                    (int) $user['id']
                );
            }
            flash('success', 'تم إرسال المحتوى للمراجعة، ولن يظهر للطلاب قبل اعتماده.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (ApiException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر رفع المحتوى الآن. تحقق من حجم الملف وإعداد التخزين.');
        }
        redirect(url('library'));
    } elseif ($action === 'lesson_youtube_recommendation_create') {
        $lessonId = filter_var($_POST['lesson_id'] ?? null, FILTER_VALIDATE_INT);
        $returnLesson = $lessonId !== false && $lessonId > 0 ? (int) $lessonId : null;
        try {
            $user = require_permission('content.upload');
            if ($returnLesson === null) throw new InvalidArgumentException('الدرس غير صالح.');
            catalog_create_youtube_recommendation(
                $user,
                $returnLesson,
                post_string('title'),
                post_string('youtube_url')
            );
            flash('success', 'تمت إضافة توصية YouTube للدرس.');
        } catch (ApiException|InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('lesson_youtube_recommendation_failed', $error);
            flash('error', 'تعذر إضافة التوصية الآن.');
        }
        redirect($returnLesson === null ? url('search') : url('lesson', ['id' => $returnLesson]));
    } elseif ($action === 'library_review') {
        try {
            $user = require_permission('content.review');
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            if ($itemId === false || $itemId < 1) { throw new InvalidArgumentException('اختر محتوى صالحًا.'); }
            review_library_item(post_string('kind'), $itemId, (int) $user['id'], post_string('decision'), post_string('feedback'));
            flash('success', 'تم حفظ قرار المراجعة.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر حفظ قرار المراجعة الآن.');
        }
        redirect(url('library'));
    } elseif ($action === 'message_send') {
        try {
            $user = require_permission('messages.use');
            $receiverId = filter_var($_POST['receiver_id'] ?? null, FILTER_VALIDATE_INT);
            if ($receiverId === false || $receiverId < 1) {
                throw new InvalidArgumentException('اختر جهة اتصال صحيحة.');
            }
            send_individual_message((int) $user['id'], $receiverId, post_string('message_body'));
            flash('success', 'تم إرسال الرسالة.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر إرسال الرسالة الآن. حاول مرة أخرى لاحقًا.');
        }
        $receiverId = filter_var($_POST['receiver_id'] ?? null, FILTER_VALIDATE_INT);
        redirect(url('messages', $receiverId !== false && $receiverId > 0 ? ['with' => $receiverId] : []));
    } elseif ($action === 'message_request_decision') {
        try {
            $user = require_permission('messages.use');
            if (!in_array('teacher', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط الأستاذ يمكنه الرد على طلبات المراسلة.');
            }
            $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            $decision = post_string('decision');
            $reason = post_string('rejection_reason');

            if ($studentId === false || $studentId < 1) {
                throw new InvalidArgumentException('معرف الطالب غير صحيح.');
            }

            if ($decision === 'accept') {
                update_message_request_status((int) $user['id'], $studentId, 'ACCEPTED');
                flash('success', 'تم قبول طلب المراسلة بنجاح. يمكنك الآن التحدث مع الطالب.');
            } elseif ($decision === 'ignore') {
                update_message_request_status((int) $user['id'], $studentId, 'IGNORED');
                flash('success', 'تم تجاهل الطلب دون إشعار الطالب.');
            } elseif ($decision === 'reject') {
                if ($reason === '') {
                    throw new InvalidArgumentException('يرجى توضيح سبب الاعتذار للطالب.');
                }
                update_message_request_status((int) $user['id'], $studentId, 'REJECTED', $reason);
                flash('success', 'تم الاعتذار وتوضيح السبب للطالب.');
            }
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('message_request_decision_failed', $error);
            flash('error', 'تعذر تحديث طلب المراسلة الآن.');
        }
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        redirect(url('messages', $studentId !== false && $studentId > 0 ? ['with' => $studentId] : []));
    } elseif ($action === 'admin_freeze_conversation') {
        $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            $user = require_login();
            if (!in_array('admin', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط مدير النظام مخول بتجميد المحادثات.');
            }
            if (!$studentId || !$teacherId) {
                throw new InvalidArgumentException('بيانات المحادثة غير صحيحة.');
            }
            freeze_conversation($studentId, $teacherId, (int) $user['id']);
            flash('success', 'تم تجميد المحادثة بين الطالب والأستاذ بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('messages', ['teacher_id' => $teacherId, 'student_id' => $studentId]));
    } elseif ($action === 'admin_unfreeze_conversation') {
        $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            $user = require_login();
            if (!in_array('admin', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط مدير النظام مخول بفك تجميد المحادثات.');
            }
            if (!$studentId || !$teacherId) {
                throw new InvalidArgumentException('بيانات المحادثة غير صحيحة.');
            }
            unfreeze_conversation($studentId, $teacherId);
            flash('success', 'تم فك تجميد المحادثة بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('messages', ['teacher_id' => $teacherId, 'student_id' => $studentId]));
    } elseif ($action === 'admin_freeze_teacher') {
        $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            $user = require_login();
            if (!in_array('admin', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط مدير النظام مخول بتجميد محادثات المعلم.');
            }
            if (!$teacherId) {
                throw new InvalidArgumentException('معرف المعلم غير صحيح.');
            }
            freeze_all_teacher_conversations($teacherId, (int) $user['id']);
            flash('success', 'تم تجميد كافة محادثات المعلم المختار بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('messages', ['teacher_id' => $teacherId]));
    } elseif ($action === 'admin_unfreeze_teacher') {
        $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            $user = require_login();
            if (!in_array('admin', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط مدير النظام مخول بفك تجميد محادثات المعلم.');
            }
            if (!$teacherId) {
                throw new InvalidArgumentException('معرف المعلم غير صحيح.');
            }
            unfreeze_all_teacher_conversations($teacherId);
            flash('success', 'تم فك تجميد كافة محادثات المعلم المختار بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('messages', ['teacher_id' => $teacherId]));
    } elseif ($action === 'admin_freeze_all') {
        try {
            $user = require_login();
            if (!in_array('admin', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط مدير النظام مخول بتجميد كافة المحادثات.');
            }
            freeze_all_platform_conversations((int) $user['id']);
            flash('success', 'تم تجميد كافة المحادثات عبر كامل المنصة بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('messages'));
    } elseif ($action === 'admin_unfreeze_all') {
        try {
            $user = require_login();
            if (!in_array('admin', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط مدير النظام مخول بفك تجميد كافة المحادثات.');
            }
            unfreeze_all_platform_conversations();
            flash('success', 'تم فك تجميد كافة المحادثات عبر كامل المنصة بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('messages'));
    } elseif ($action === 'admin_freeze_selected') {
        try {
            $user = require_login();
            if (!in_array('admin', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط مدير النظام مخول بتجميد المحادثات المحددة.');
            }
            $pairs = (array) ($_POST['selected_conversations'] ?? []);
            foreach ($pairs as $pair) {
                $parts = explode(':', (string) $pair);
                if (count($parts) === 2) {
                    $tId = (int) $parts[0];
                    $sId = (int) $parts[1];
                    if ($tId > 0 && $sId > 0) {
                        freeze_conversation($sId, $tId, (int) $user['id']);
                    }
                }
            }
            flash('success', 'تم تجميد المحادثات المحددة بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('messages'));
    } elseif ($action === 'admin_unfreeze_selected') {
        try {
            $user = require_login();
            if (!in_array('admin', $user['roles'] ?? [], true)) {
                throw new InvalidArgumentException('فقط مدير النظام مخول بفك تجميد المحادثات المحددة.');
            }
            $pairs = (array) ($_POST['selected_conversations'] ?? []);
            foreach ($pairs as $pair) {
                $parts = explode(':', (string) $pair);
                if (count($parts) === 2) {
                    $tId = (int) $parts[0];
                    $sId = (int) $parts[1];
                    if ($tId > 0 && $sId > 0) {
                        unfreeze_conversation($sId, $tId);
                    }
                }
            }
            flash('success', 'تم فك تجميد المحادثات المحددة بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('messages'));
    } elseif ($action === 'announcement_create') {
        try {
            $user = require_login();
            $isAdmin = in_array('admin', $user['roles'] ?? [], true);
            $isTeacher = in_array('teacher', $user['roles'] ?? [], true);
            if (!$isAdmin && !$isTeacher) {
                throw new InvalidArgumentException('ليس لديك صلاحية لنشر إعلانات.');
            }
            $title = post_string('title');
            $content = post_string('content');
            $badge = post_string('badge_label');
            $priority = post_string('priority');
            $cover = post_string('cover_style');
            $classSubjectId = filter_var($_POST['class_subject_id'] ?? null, FILTER_VALIDATE_INT);
            if ($classSubjectId === false || $classSubjectId < 1) {
                $classSubjectId = null;
            }
            create_announcement_record((int) $user['id'], $title, $content, $badge, $priority, $cover, $classSubjectId, $isAdmin);
            flash('success', 'تم نشر الإعلان المدرسي بنجاح.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('announcements'));
    } elseif ($action === 'announcement_toggle') {
        try {
            $user = require_login();
            $isAdmin = in_array('admin', $user['roles'] ?? [], true);
            $id = filter_var($_POST['announcement_id'] ?? null, FILTER_VALIDATE_INT);
            if ($id && $id > 0) {
                toggle_announcement_status((int) $id, (int) $user['id'], $isAdmin);
                flash('success', 'تم تحديث حالة ظهور الإعلان.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('announcements'));
    } elseif ($action === 'announcement_delete') {
        try {
            $user = require_login();
            $isAdmin = in_array('admin', $user['roles'] ?? [], true);
            $id = filter_var($_POST['announcement_id'] ?? null, FILTER_VALIDATE_INT);
            if ($id && $id > 0) {
                delete_announcement_record((int) $id, (int) $user['id'], $isAdmin);
                flash('success', 'تم حذف الإعلان بنجاح.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(url('announcements'));
    } elseif ($action === 'staff_invite') {
        try {
            $user = require_permission('users.manage');
            $inviteOrigin = public_origin();
            $token = create_staff_invitation(
                post_string('full_name'),
                post_string('email'),
                post_string('role_code'),
                post_string('job_title'),
                $user['id']
            );
            $_SESSION['staff_invite_link'] = $inviteOrigin . url('accept_invitation', ['token' => $token]);
            flash('success', 'أُنشئت الدعوة. انسخ الرابط الظاهر مرة واحدة وأرسله للموظف عبر قناة موثوقة.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (PDOException $error) {
            if ($error->getCode() === '23505') {
                flash('error', 'هذا البريد مسجل بالفعل.');
            } else {
                app_log_error('legacy_web_database_failed', $error);
                flash('error', 'تعذر إنشاء الدعوة الآن. حاول مرة أخرى لاحقًا.');
            }
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر إنشاء الدعوة الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('staff'));
    } elseif ($action === 'staff_assign_subject') {
        try {
            $user = require_permission('roles.manage');
            $teacherId = filter_var($_POST['teacher_user_id'] ?? null, FILTER_VALIDATE_INT);
            $subjectId = filter_var($_POST['class_subject_id'] ?? null, FILTER_VALIDATE_INT);
            if ($teacherId === false || $teacherId < 1 || $subjectId === false || $subjectId < 1) {
                throw new InvalidArgumentException('اختر أستاذًا ومادة صحيحين.');
            }
            assign_teacher_subject($teacherId, $subjectId, $user['id']);
            flash('success', 'تم إسناد المادة إلى الأستاذ.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر إسناد المادة الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('staff'));
    } elseif (in_array($action, ['staff_disable', 'staff_enable'], true)) {
        try {
            $user = require_permission('users.manage');
            $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($targetUserId === false || $targetUserId < 1) {
                throw new InvalidArgumentException('اختر حسابًا صحيحًا.');
            }
            $active = $action === 'staff_enable';
            $changed = set_staff_account_active($targetUserId, $user['id'], $active);
            if ($changed) {
                flash('success', $active ? 'تمت إعادة تفعيل الحساب.' : 'تم تعطيل الحساب.');
            } else {
                flash('success', 'الحساب لديه الحالة المطلوبة بالفعل.');
            }
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر تحديث الحساب الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('staff'));
    } elseif ($action === 'accept_staff_invitation') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        $invitationId = (int) ($_SESSION['pending_staff_invitation_id'] ?? 0);
        if ($password !== $confirmation) {
            $errors[] = 'تأكيد كلمة المرور غير مطابق.';
            $page = 'accept_invitation';
        } else {
            try {
                accept_staff_invitation($invitationId, $password);
                unset($_SESSION['pending_staff_invitation_id'], $_SESSION['pending_staff_invitation_token']);
                logout_user();
                flash('success', 'تم تفعيل حسابك. سجّل الدخول بالبريد وكلمة المرور الجديدة.');
                redirect(url('login'));
            } catch (InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
                $page = 'accept_invitation';
            } catch (Throwable $error) {
                app_log_error('legacy_web_action_failed', $error);
                $errors[] = 'تعذر تفعيل الحساب الآن. اطلب من الإدارة إرسال دعوة جديدة.';
                $page = 'accept_invitation';
            }
        }
    } elseif (in_array($action, ['content_access_grant', 'content_access_revoke'], true)) {
        try {
            $user = require_permission('content_access.manage');
            $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            if ($studentId === false || $studentId < 1) {
                throw new InvalidArgumentException('اختر حساب طالب صحيحًا.');
            }
            $change = $action === 'content_access_grant' ? 'grant' : 'revoke';
            $changed = change_student_content_access($studentId, $user['id'], $change, post_string('reason'));
            if ($changed) {
                flash('success', $change === 'grant' ? 'تم منح الطالب صلاحية الوصول للمحتوى.' : 'تم سحب صلاحية الوصول للمحتوى.');
            } else {
                flash('success', $change === 'grant' ? 'الطالب مخوّل للوصول مسبقًا.' : 'لا توجد صلاحية نشطة لهذا الطالب.');
            }
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر تحديث صلاحية الوصول الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('content_access'));
    } elseif (in_array($action, ['school_schedule_create', 'school_schedule_delete', 'school_holiday_create', 'school_holiday_delete'], true)) {
        try {
            $user = require_permission('academics.manage');
            if ($action === 'school_schedule_create') {
                school_schedule_create_entry($_POST, (int) $user['id']);
                flash('success', 'تمت إضافة الموعد إلى الجدول الدراسي.');
            } elseif ($action === 'school_schedule_delete') {
                $id = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('اختر موعدًا صحيحًا للحذف.');
                }
                school_schedule_delete_entry($id, (int) $user['id']);
                flash('success', 'تم حذف الموعد من الجدول.');
            } elseif ($action === 'school_holiday_create') {
                school_schedule_create_holiday($_POST, (int) $user['id']);
                flash('success', 'تم نشر الإجازة ضمن إعلانات المنصة.');
            } else {
                $id = filter_var($_POST['holiday_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('اختر إجازة صحيحة للحذف.');
                }
                school_schedule_delete_holiday($id, (int) $user['id']);
                flash('success', 'تم حذف الإجازة وإعلانها.');
            }
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('school_schedule_action_failed', $error);
            flash('error', 'تعذر حفظ التغيير الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('school_schedule'));
    } elseif (in_array($action, ['academics_add_stage', 'academics_add_class', 'academics_add_subject'], true)) {
        try {
            $user = require_permission('academics.manage');
            if ($action === 'academics_add_stage') {
                $sortOrder = filter_var($_POST['sort_order'] ?? null, FILTER_VALIDATE_INT);
                if ($sortOrder === false) {
                    throw new InvalidArgumentException('أدخل ترتيب عرض صحيحًا.');
                }
                $name = post_string('name');
                $code = post_string('code');
                if ($code === '') {
                    $code = 'STG_' . strtoupper(substr(md5($name . microtime(true)), 0, 6));
                }
                create_academic_stage($code, $name, $sortOrder, $user['id']);
                flash('success', 'تمت إضافة المرحلة التعليمية بنجاح.');
            } elseif ($action === 'academics_add_class') {
                $stageId = filter_var($_POST['stage_id'] ?? null, FILTER_VALIDATE_INT);
                $sortOrder = filter_var($_POST['sort_order'] ?? null, FILTER_VALIDATE_INT);
                if ($stageId === false || $stageId < 1 || $sortOrder === false) {
                    throw new InvalidArgumentException('اختر مرحلة وأدخل ترتيب عرض صحيحًا.');
                }
                $name = post_string('name');
                $code = post_string('code');
                if ($code === '') {
                    $code = 'CLS_' . strtoupper(substr(md5($name . microtime(true)), 0, 6));
                }
                create_academic_class($stageId, $code, $name, $sortOrder, $user['id']);
                flash('success', 'تمت إضافة الصف الدراسي بنجاح.');
            } else {
                $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
                if ($classId === false || $classId < 1) {
                    throw new InvalidArgumentException('اختر صفًا صحيحًا.');
                }
                $name = post_string('name');
                $code = post_string('code');
                if ($code === '') {
                    $code = 'SUB_' . strtoupper(substr(md5($name . microtime(true)), 0, 6));
                }
                create_subject_assignment($classId, $name, $code, $user['id']);
                flash('success', 'تمت إضافة المادة وربطها بالصف بنجاح.');
            }
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (PDOException $error) {
            if ($error->getCode() === '23505') {
                flash('error', 'الرمز أو الاسم مسجل مسبقًا. راجع القائمة وحاول مرة أخرى.');
            } else {
                app_log_error('legacy_web_database_failed', $error);
                flash('error', 'تعذر حفظ التغيير الآن. حاول مرة أخرى لاحقًا.');
            }
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر حفظ التغيير الآن. حاول مرة أخرى لاحقًا.');
        }
        redirect(url('academics'));
    } else {
        http_response_code(400);
        exit('طلب غير معروف.');
    }
}

$title = 'منصة الإسناد الأكاديمي';
$contentView = PROJECT_ROOT . '/app/Views/home.php';
$data = [];

try {
    if ($page === 'quiz_attempt') {
        $user = require_login();
        header('Cache-Control: private, no-store');
        $attemptId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        try {
            if ($attemptId === false || $attemptId === null || $attemptId < 1) { throw new InvalidArgumentException('المحاولة غير موجودة.'); }
            $data['attempt'] = get_quiz_attempt($attemptId,(int)$user['id']);
            if ($data['attempt']['status'] === 'IN_PROGRESS' && !empty($data['attempt']['deadline_at']) && new DateTimeImmutable($data['attempt']['deadline_at']) <= new DateTimeImmutable('now')) {
                try { quiz_get_result($user, $attemptId); } catch (ApiException $error) {
                    if (!in_array($error->apiCode, ['result_not_available', 'attempt_in_progress'], true)) throw $error;
                }
                $data['attempt'] = get_quiz_attempt($attemptId, (int) $user['id']);
            }
            $data['is_owner'] = (int)$data['attempt']['student_user_id'] === (int)$user['id'];
            $data['is_teacher'] = !$data['is_owner'];
            $data['is_admin'] = in_array('admin', $user['roles'] ?? [], true);
            $data['result_available'] = false;
            if ($data['is_owner'] && $data['attempt']['status'] === 'GRADED' && !empty($data['attempt']['result_approved_at'])) {
                try {
                    quiz_get_result($user, $attemptId);
                    $data['result_available'] = true;
                } catch (ApiException $error) {
                    if (!in_array($error->apiCode, ['result_not_available', 'quiz_not_configured'], true)) throw $error;
                }
            }
            if ($data['is_teacher']) {
                require_permission('quizzes.manage');
                $data['answers'] = list_quiz_attempt_answers($attemptId,(int)$user['id']);
            }
            $title = 'محاولة اختبار';
            $contentView = PROJECT_ROOT . '/app/Views/quiz_attempt.php';
        } catch (InvalidArgumentException $error) {
            http_response_code(404);
            $title = 'المحاولة غير موجودة';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        }
    } elseif ($page === 'quizzes') {
        $user = require_login();
        header('Cache-Control: private, no-store');
        $data['can_manage'] = user_has_permission('quizzes.manage');
        $data['can_attempt'] = user_has_permission('quizzes.attempt');
        if (!$data['can_manage'] && !$data['can_attempt']) { http_response_code(403); $data['quizzes']=[]; $data['subjects']=[]; $data['questions']=[]; $data['access_denied']=true; }
        else {
            $data['quizzes'] = list_available_quizzes((int)$user['id'],$data['can_manage']);
            $data['attempts_by_quiz'] = [];
            if ($data['can_manage']) {
                foreach ($data['quizzes'] as $quiz) { $data['attempts_by_quiz'][(int)$quiz['id']] = list_quiz_attempts_for_teacher((int)$quiz['id'],(int)$user['id']); }
            }
            $data['subjects'] = $data['can_manage'] ? list_quiz_subjects((int)$user['id']) : [];
            $subjectId=filter_input(INPUT_GET,'subject',FILTER_VALIDATE_INT);
            $data['selected_subject_id']=$subjectId!==false&&$subjectId!==null&&$subjectId>0?$subjectId:0;
            $data['questions']=$data['can_manage']&&$data['selected_subject_id']>0?list_quiz_questions_for_author($data['selected_subject_id'],(int)$user['id']):[];
        }
        $title = 'الاختبارات الإلكترونية';
        $contentView = PROJECT_ROOT . '/app/Views/quizzes.php';
    } elseif ($page === 'library_download') {
        $user = require_login();
        $downloadId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        try {
            if ($downloadId === false || $downloadId === null || $downloadId < 1) { throw new InvalidArgumentException('الملف غير موجود.'); }
            $download = get_authorized_library_download(is_string($_GET['kind'] ?? null) ? $_GET['kind'] : '', $downloadId, (int) $user['id']);
            if (content_has_video_asset($download)) {
                header('Cache-Control: no-store');
                header('Referrer-Policy: no-referrer');
                header('Location: ' . content_video_playback_url($download), true, 302);
                exit;
            }
            header('Content-Type: ' . $download['mime']);
            header('Content-Length: ' . (string) filesize($download['path']));
            header('Content-Disposition: attachment; filename="document.pdf"; filename*=UTF-8\'\'' . rawurlencode($download['filename']));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($download['path']);
            exit;
        } catch (InvalidArgumentException $error) {
            http_response_code(404);
            exit('الملف غير موجود.');
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            http_response_code(403);
            exit('ليس لديك صلاحية لتنزيل هذا الملف.');
        }
    } elseif ($page === 'search') {
        $user = require_login();
        if (!user_has_role('admin') && !user_has_role('content_reviewer') && !user_has_role('student')) {
            http_response_code(403);
            $title = 'غير مصرح';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } else {
            $query = $_GET['q'] ?? '';
            $queryLength = is_string($query) ? preg_match_all('/./us', $query) : false;
            if (!is_string($query) || $queryLength === false || $queryLength > 120) {
                throw new InvalidArgumentException('عبارة البحث يجب ألا تتجاوز 120 محرفًا.');
            }
            $query = trim($query);
            $data['searchQuery'] = $query;
            $data['teachers'] = catalog_search_teachers($query, $user);
            $data['canSearchLessons'] = user_has_permission('content.read')
                || user_has_permission('content.publish')
                || user_has_permission('content.review');
            if (in_array('student', $user['roles'] ?? [], true)
                && !student_has_manual_content_access((int) $user['id'])) {
                $data['canSearchLessons'] = false;
            }
            $data['lessons'] = $data['canSearchLessons'] ? catalog_search_lessons($query, $user) : [];
            $data['popularMedia'] = $data['canSearchLessons'] ? catalog_popular_media($user) : [];
            $data['latestMedia'] = $data['canSearchLessons'] ? catalog_latest_media($user) : [];
            $title = 'البحث عن مادة أو أستاذ';
            $contentView = PROJECT_ROOT . '/app/Views/search.php';
        }
    } elseif ($page === 'lesson') {
        $user = require_login();
        $lessonId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $canRead = user_has_permission('content.read')
            || user_has_permission('content.publish')
            || user_has_permission('content.review');
        if (in_array('student', $user['roles'] ?? [], true)
            && !student_has_manual_content_access((int) $user['id'])) {
            $canRead = false;
        }
        $data['lesson'] = $canRead && $lessonId !== false && $lessonId !== null && $lessonId > 0
            ? catalog_find_lesson_for_actor((int) $lessonId, $user)
            : null;
        if ($data['lesson'] === null) {
            http_response_code($canRead ? 404 : 403);
            $title = $canRead ? 'الدرس غير موجود' : 'صلاحية المحتوى غير مفعلة';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } else {
            $media = array_values(array_filter(
                $data['lesson']['resources'],
                static fn(array $resource): bool => in_array($resource['resource_type'], ['VIDEO', 'AUDIO'], true)
            ));
            $selectedId = filter_input(INPUT_GET, 'resource_id', FILTER_VALIDATE_INT);
            $data['activeMedia'] = null;
            foreach ($media as $resource) {
                if ($selectedId !== false && $selectedId !== null && (int) $resource['id'] === $selectedId) {
                    $data['activeMedia'] = $resource;
                    break;
                }
            }
            $data['activeMedia'] ??= $media[0] ?? null;
            $data['canAddYoutubeRecommendations'] = user_has_permission('content.upload')
                && (user_has_role('admin') || user_has_role('teacher'));
            $title = $data['lesson']['title'];
            $contentView = PROJECT_ROOT . '/app/Views/lesson.php';
        }
    } elseif ($page === 'teacher') {
        $user = require_login();
        $employeeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $data['teacher'] = $employeeId !== false && $employeeId !== null && $employeeId > 0
            ? catalog_find_teacher_for_actor((int) $employeeId, $user)
            : null;
        if ($data['teacher'] === null) {
            http_response_code(404);
            $title = 'الأستاذ غير موجود';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } else {
            $canReadLessons = user_has_permission('content.read')
                || user_has_permission('content.publish')
                || user_has_permission('content.review');
            if (in_array('student', $user['roles'] ?? [], true)
                && !student_has_manual_content_access((int) $user['id'])) {
                $canReadLessons = false;
            }
            $data['teacherLessons'] = $canReadLessons
                ? catalog_teacher_lessons((int) $data['teacher']['employee_id'], $user)
                : [];
            $title = $data['teacher']['full_name'];
            $contentView = PROJECT_ROOT . '/app/Views/teacher.php';
        }
    } elseif ($page === 'library') {
        $user = require_login();
        $data['can_review'] = user_has_permission('content.review');
        $data['can_upload'] = user_has_permission('content.upload');
        if ($data['can_review']) {
            $data['items'] = list_library_items(true);
        } elseif ($data['can_upload']) {
            $subjects = list_library_subjects((int) $user['id']);
            $assignedIds = array_map(static fn(array $subject): int => (int) $subject['class_subject_id'], $subjects);
            $approved = array_values(array_filter(list_library_items(false), static fn(array $item): bool => in_array((int) $item['class_subject_id'], $assignedIds, true)));
            $pending = array_values(array_filter(list_library_items(true), static fn(array $item): bool => (int) $item['uploader_user_id'] === (int) $user['id']));
            $data['items'] = array_merge($pending, $approved);
            $data['subjects'] = $subjects;
            $data['lessons'] = catalog_list_upload_lessons((int) $user['id']);
        } elseif (user_has_permission('content.read')) {
            $data['items'] = list_student_library_items((int) $user['id']);
        } else {
            http_response_code(403);
            $data['items'] = [];
            $data['access_denied'] = true;
        }
        $data['subjects'] ??= $data['can_upload'] ? list_library_subjects((int) $user['id']) : [];
        $title = 'المكتبة والامتحانات';
        $contentView = PROJECT_ROOT . '/app/Views/library.php';
    } elseif ($page === 'accept_invitation') {
        $token = is_string($_GET['token'] ?? null)
            ? $_GET['token']
            : (is_string($_SESSION['pending_staff_invitation_token'] ?? null) ? $_SESSION['pending_staff_invitation_token'] : '');
        $data['invitation'] = find_staff_invitation($token);
        if (!$data['invitation']) {
            unset($_SESSION['pending_staff_invitation_id'], $_SESSION['pending_staff_invitation_token']);
            http_response_code(404);
            $title = 'رابط الدعوة غير صالح';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } else {
            $_SESSION['pending_staff_invitation_id'] = (int) $data['invitation']['invitation_id'];
            $_SESSION['pending_staff_invitation_token'] = $token;
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer', true);
            header('X-Robots-Tag: noindex, nofollow');
            $title = 'تفعيل حساب الفريق';
            $contentView = PROJECT_ROOT . '/app/Views/accept_invitation.php';
        }
    } elseif ($page === 'staff') {
        require_permission('users.manage');
        $data['staff'] = list_staff_accounts();
        $data['can_manage_roles'] = user_has_permission('roles.manage');
        $data['teacher_assignments'] = $data['can_manage_roles'] ? list_teacher_subject_assignments() : [];
        $data['teaching_subjects'] = $data['can_manage_roles'] ? list_active_teaching_subjects() : [];
        $data['new_invitation_link'] = $_SESSION['staff_invite_link'] ?? null;
        unset($_SESSION['staff_invite_link']);
        header('Cache-Control: no-store');
        $title = 'حسابات الفريق';
        $contentView = PROJECT_ROOT . '/app/Views/staff.php';
    } elseif ($page === 'accounts') {
        require_permission('roles.manage');
        $data['classes'] = academic_list_classes(null);
        header('Cache-Control: no-store');
        $title = 'إدارة حسابات المستخدمين';
        $contentView = PROJECT_ROOT . '/app/Views/accounts.php';
    } elseif ($page === 'questions') {
        $user = require_login();
        $canCreate = user_has_permission('questions.manage')
            && (user_has_permission('roles.manage') || in_array('teacher', $user['roles'] ?? [], true));
        $canReview = user_has_permission('content.review');
        if (!$canCreate && !$canReview && !student_has_manual_content_access((int) $user['id'])) {
            flash('error', 'يتطلب عرض بنك الأسئلة موافقة إدارة المنصة على الوصول إلى المحتوى.');
            redirect(url('subjects'));
        }
        $subjectFilter = filter_input(INPUT_GET, 'subject', FILTER_VALIDATE_INT);
        $creatorFilter = $canCreate && !$canReview ? (int) $user['id'] : null;
        $data['questions'] = list_question_bank(
            $subjectFilter !== false && $subjectFilter !== null && $subjectFilter > 0 ? $subjectFilter : null,
            $canReview || $creatorFilter !== null,
            $creatorFilter
        );
        $data['can_create'] = $canCreate;
        $data['can_review'] = $canReview;
        $data['subjects'] = $canCreate ? list_question_subjects((int) $user['id']) : [];
        $data['lessons_by_subject'] = [];
        foreach ($data['subjects'] as $subject) {
            $data['lessons_by_subject'][(int) $subject['class_subject_id']] = list_question_lessons((int) $subject['class_subject_id']);
        }
        $title = 'بنك الأسئلة';
        $contentView = PROJECT_ROOT . '/app/Views/questions.php';
    } elseif ($page === 'messages') {
        $user = require_login();
        $userId = (int) $user['id'];
        $data['is_admin'] = in_array('admin', $user['roles'] ?? [], true);
        $data['is_student'] = in_array('student', $user['roles'] ?? [], true);
        $data['is_teacher'] = in_array('teacher', $user['roles'] ?? [], true);

        if ($data['is_admin']) {
            $data['admin_overview'] = admin_list_messaging_overview();
            $teacherId = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);
            $studentId = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);
            $data['selected_teacher_id'] = ($teacherId !== false && $teacherId > 0) ? $teacherId : null;
            $data['selected_student_id'] = ($studentId !== false && $studentId > 0) ? $studentId : null;
            $data['admin_teacher'] = null;
            $data['admin_student'] = null;
            $data['thread'] = [];
            $data['request'] = null;

            if ($data['selected_teacher_id'] && $data['selected_student_id']) {
                $stmt = database_connection()->prepare("SELECT id, full_name, email FROM users WHERE id IN (:tid, :sid)");
                $stmt->execute(['tid' => $data['selected_teacher_id'], 'sid' => $data['selected_student_id']]);
                $uRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($uRows as $ur) {
                    if ((int)$ur['id'] === $data['selected_teacher_id']) $data['admin_teacher'] = $ur;
                    if ((int)$ur['id'] === $data['selected_student_id']) $data['admin_student'] = $ur;
                }
                if ($data['admin_teacher'] && $data['admin_student']) {
                    $data['thread'] = admin_list_message_thread($data['selected_teacher_id'], $data['selected_student_id']);
                    $data['request'] = get_message_request($data['selected_student_id'], $data['selected_teacher_id']);
                    $data['peer'] = ['id' => $data['selected_student_id'], 'full_name' => $data['admin_student']['full_name'], 'role_name' => 'طالب'];
                }
            }
        } else {
            require_permission('messages.use');
            $data['contacts'] = list_message_contacts($userId);
            $data['conversations'] = list_message_conversations($userId);
            $data['peer'] = null;
            $data['thread'] = [];
            $data['request'] = null;
            $peerId = filter_input(INPUT_GET, 'with', FILTER_VALIDATE_INT);
            if ($peerId !== false && $peerId !== null && $peerId > 0) {
                foreach ($data['contacts'] as $contact) {
                    if ((int) $contact['id'] === $peerId) {
                        $data['peer'] = $contact;
                        break;
                    }
                }
                if ($data['peer']) {
                    mark_message_thread_read($userId, $peerId);
                    $data['thread'] = list_message_thread($userId, $peerId);

                    // إدارة طلب المراسلة بحسب الدور
                    if ($data['is_student']) {
                        $data['request'] = ensure_message_request($userId, $peerId);
                    } elseif ($data['is_teacher']) {
                        $data['request'] = get_message_request($peerId, $userId);
                    }
                } else {
                    flash('error', 'جهة الاتصال غير متاحة للمراسلة.');
                }
            }
        }
        $title = 'المراسلة والتواصل المباشر';
        $contentView = PROJECT_ROOT . '/app/Views/messages.php';
    } elseif ($page === 'announcements') {
        $user = require_login();
        $isAdmin = in_array('admin', $user['roles'] ?? [], true);
        $isTeacher = in_array('teacher', $user['roles'] ?? [], true);
        if (!$isAdmin && !$isTeacher) {
            http_response_code(403);
            $title = 'غير مصرح';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } else {
            $data['is_admin'] = $isAdmin;
            $data['is_teacher'] = $isTeacher;
            $data['announcements'] = $isAdmin ? list_admin_announcements() : list_teacher_announcements((int) $user['id']);
            $data['calendar_holidays'] = school_schedule_list_holidays($user, 100);
            $data['teachingAssignments'] = $isTeacher ? academic_teacher_assignments((int) $user['id']) : [];
            $data['allSubjects'] = $isAdmin ? list_active_teaching_subjects() : [];
            $title = 'إدارة الإعلانات المدرسية';
            $contentView = PROJECT_ROOT . '/app/Views/announcements.php';
        }
    } elseif ($page === 'content_access') {
        require_permission('content_access.manage');
        $requestedPage = filter_input(INPUT_GET, 'page_num', FILTER_VALIDATE_INT);
        $data = list_students_with_access($requestedPage !== false && $requestedPage !== null ? $requestedPage : 1);
        $title = 'إدارة صلاحية المحتوى';
        $contentView = PROJECT_ROOT . '/app/Views/content_access.php';
    } elseif ($page === 'academics') {
        require_permission('academics.manage');
        $data['stages'] = list_academic_structure();
        $title = 'إدارة الهيكل الدراسي';
        $contentView = PROJECT_ROOT . '/app/Views/academics.php';
    } elseif ($page === 'school_schedule') {
        $user = require_login();
        $isAdmin = in_array('admin', $user['roles'] ?? [], true);
        $isTeacher = in_array('teacher', $user['roles'] ?? [], true);
        $isStudent = in_array('student', $user['roles'] ?? [], true);
        $canManageSchedule = $isAdmin && user_has_permission('academics.manage');
        $canReadSchedule = $canManageSchedule || $isTeacher || $isStudent;
        if (!$canReadSchedule) {
            http_response_code(403);
            $title = 'غير مصرح';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } else {
            $data['canManageSchedule'] = $canManageSchedule;
            $scheduleActor = $user;
            if (!$canManageSchedule) {
                $scheduleActor['roles'] = array_values(array_intersect(['student', 'teacher'], $user['roles'] ?? []));
            }
            $data['school_schedule_entries'] = school_schedule_list_entries($scheduleActor);
            $data['school_holidays'] = $canManageSchedule
                ? school_schedule_list_manager_holidays()
                : school_schedule_list_holidays($scheduleActor);
            if ($canManageSchedule) {
                $data['schedule_classes'] = school_schedule_list_classes();
                $data['schedule_assignments'] = school_schedule_list_assignments();
            }
            $title = 'الجدول الدراسي والإجازات';
            $contentView = PROJECT_ROOT . '/app/Views/school_schedule.php';
        }
    } elseif ($page === 'sessions') {
        $user = require_login();
        $data['sessionCanManage'] = user_has_permission('sessions.manage');
        $canJoin = user_has_permission('sessions.join');
        if (!$data['sessionCanManage'] && !$canJoin) {
            http_response_code(403);
            $title = 'غير مصرح';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } else {
            $data['sessions'] = sm_list_sessions($user);
            $data['teachingAssignments'] = in_array('teacher', $user['roles'] ?? [], true)
                ? academic_teacher_assignments((int) $user['id']) : [];
            $title = 'الحصص والتسجيلات';
            $contentView = PROJECT_ROOT . '/app/Views/sessions.php';
        }
    } elseif ($page === 'subjects' || $page === 'home') {
        $user = current_user();
        if ($page === 'subjects'
            && (!$user || (!user_has_role('admin') && !user_has_role('content_reviewer')
                && !user_has_role('student') && !user_has_role('teacher')))) {
            http_response_code(403);
            $title = 'غير مصرح';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } elseif ($page === 'subjects') {
            if (user_has_role('admin') || user_has_role('content_reviewer')) {
                $data['subjects'] = list_class_subjects(50);
            } elseif (user_has_role('teacher') && !user_has_role('student')) {
                $data['subjects'] = list_teacher_class_subjects((int) $user['id']);
            } else {
                $data['subjects'] = list_student_class_subjects((int) $user['id']);
            }
            $data['teacher_subjects_only'] = user_has_role('teacher')
                && !user_has_role('admin')
                && !user_has_role('content_reviewer')
                && !user_has_role('student');
            $title = $data['teacher_subjects_only'] ? 'موادي الدراسية' : 'المواد الدراسية';
            $contentView = PROJECT_ROOT . '/app/Views/subjects.php';
        } elseif ($page === 'home' && $user) {
            $homeCanManageSchedule = in_array('admin', $user['roles'] ?? [], true)
                && user_has_permission('academics.manage');
            $homeCanReadSchedule = $homeCanManageSchedule
                || in_array('teacher', $user['roles'] ?? [], true)
                || in_array('student', $user['roles'] ?? [], true);
            if ($homeCanReadSchedule) {
                $scheduleActor = $user;
                if (!$homeCanManageSchedule) {
                    $scheduleActor['roles'] = array_values(array_intersect(['student', 'teacher'], $user['roles'] ?? []));
                }
                $data['school_holidays'] = school_schedule_list_holidays($scheduleActor, 5);
                $data['school_schedule_entries'] = school_schedule_list_entries($scheduleActor);
            } else {
                $data['school_holidays'] = school_schedule_list_holidays(null, 5);
            }
            if (user_has_role('admin') || user_has_role('content_reviewer')) {
                $data['published_announcements'] = list_published_announcements(null, 5);
            } elseif (user_has_role('teacher')) {
                $data['teacher_assignments'] = academic_teacher_assignments((int) $user['id']);
                $data['sessions'] = user_has_permission('sessions.manage') ? sm_list_sessions($user) : [];
                $data['published_announcements'] = list_published_announcements(null, 5);
            } elseif (user_has_role('student')) {
                $studentClassId = student_class_id((int) $user['id']);
                $data['subjects'] = $studentClassId === null ? [] : list_class_subjects(3, $studentClassId);
                $data['has_student_class'] = $studentClassId !== null;
                $data['student_has_content_access'] = student_has_manual_content_access((int) $user['id']);
                $data['sessions'] = user_has_permission('sessions.join') ? sm_list_sessions($user) : [];
                $data['published_announcements'] = list_published_announcements($studentClassId, 5);
            }
            if (user_has_permission('admissions.manage')) {
                $pendingAdmissions = database_connection()->query("SELECT COUNT(*) FROM students WHERE registration_status = 'PENDING'")->fetchColumn();
                $pendingCorrections = database_connection()->query("SELECT COUNT(*) FROM student_profile_correction_requests WHERE status = 'PENDING'")->fetchColumn();
                $data['admissionsOverview'] = [
                    'pending_admissions' => (int) $pendingAdmissions,
                    'pending_corrections' => (int) $pendingCorrections,
                ];
            }
            if ($page === 'home' && $user && user_has_role('admin') && user_has_permission('roles.manage')) {
                $data['adminOverview'] = admin_dashboard_summary();
            }
        } elseif ($page === 'home') {
            $data['school_holidays'] = school_schedule_list_holidays(null, 5);
            $data['subjects'] = list_class_subjects(3);
            $data['published_announcements'] = list_published_announcements(null, 5);
        }
    } elseif ($page === 'subject') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $data['subject'] = $id ? find_class_subject($id) : null;
        if (!$data['subject']) {
            http_response_code(404);
            $title = 'الصفحة غير موجودة';
            $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
        } else {
            $user = current_user();
            $classSubjectId = (int) $data['subject']['class_subject_id'];
            $studentClassId = $user && user_has_role('student') ? student_class_id((int) $user['id']) : null;
            $studentInClass = $user
                && user_has_role('student')
                && $studentClassId === (int) $data['subject']['class_id'];
            $teacherAssigned = $user
                && user_has_role('teacher')
                && teacher_has_class_subject((int) $user['id'], $classSubjectId);
            $studentCanRead = $studentInClass
                && student_has_manual_content_access((int) $user['id']);
            $teacherCanRead = $teacherAssigned && user_has_permission('content.read');
            $canViewContent = $user !== null
                && (user_has_permission('content.publish') || user_has_permission('content.review')
                    || $studentCanRead || $teacherCanRead);
            $outsideAssignedScope = $user
                && (user_has_role('student') || user_has_role('teacher'))
                && !(user_has_permission('content.publish') || user_has_permission('content.review')
                    || $studentInClass || $teacherAssigned);
            if ($outsideAssignedScope) {
                http_response_code(404);
                $data['subject'] = null;
                $title = 'الصفحة غير موجودة';
                $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
            } else {
                $data['can_view_content'] = $canViewContent;
                $data['lessons'] = $data['can_view_content']
                    ? list_published_lessons($classSubjectId)
                    : [];
                $title = $data['subject']['subject_name'] . ' | المواد الدراسية';
                $contentView = PROJECT_ROOT . '/app/Views/subject.php';
            }
        }
    } elseif ($page === 'account' || $page === 'profile') {
        $user = require_login();
        $accountDetails = get_user_account_details((int) $user['id']);
        $data['accountUser'] = $accountDetails['user'];
        $data['studentInfo'] = $accountDetails['student'];
        $data['employeeInfo'] = $accountDetails['employee'];
        $data['teachingSubjects'] = $accountDetails['teaching_subjects'];
        $data['hasContentAccess'] = $accountDetails['has_content_access'];
        $title = 'الملف الشخصي والحساب';
        $contentView = PROJECT_ROOT . '/app/Views/account.php';
    } elseif ($page === 'register') {
        $data['classes'] = academic_list_classes(null);
        $title = 'إنشاء حساب';
        $contentView = PROJECT_ROOT . '/app/Views/register.php';
    } elseif ($page === 'guide') {
        $title = 'دليل استخدام المنصة والواجهات';
        $contentView = PROJECT_ROOT . '/app/Views/guide.php';
    } elseif ($page === 'login') {
        $title = 'تسجيل الدخول';
        $contentView = PROJECT_ROOT . '/app/Views/login.php';
    } elseif ($page === 'forgot_password') {
        $title = 'استعادة كلمة المرور';
        $contentView = PROJECT_ROOT . '/app/Views/forgot_password.php';
    } elseif ($page === 'reset_password') {
        $data['token'] = is_string($_GET['token'] ?? null) ? $_GET['token'] : ($data['token'] ?? '');
        $data['reset_token_valid'] ??= password_reset_token_is_valid($data['token']);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer', true);
        $title = 'تعيين كلمة مرور جديدة';
        $contentView = PROJECT_ROOT . '/app/Views/reset_password.php';
    } elseif ($page === 'complete_admission') {
        $data['token'] = is_string($_GET['token'] ?? null) ? $_GET['token'] : ($data['token'] ?? '');
        $data['admission_token_valid'] ??= admission_completion_is_valid($data['token']);
        $data['classes'] = academic_list_classes(null);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer', true);
        $title = 'استكمال طلب التسجيل';
        $contentView = PROJECT_ROOT . '/app/Views/complete_admission.php';
    } elseif ($page === 'admissions') {
        require_permission('admissions.manage');
        $filters = [
            'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
            'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : 'PENDING',
            'class_id' => is_string($_GET['class_id'] ?? null) ? $_GET['class_id'] : '',
            'from' => is_string($_GET['from'] ?? null) ? $_GET['from'] : '',
            'to' => is_string($_GET['to'] ?? null) ? $_GET['to'] : '',
        ];
        $data['admissions'] = [];
        $data['corrections'] = list_profile_correction_requests('PENDING');
        $data['classes'] = academic_list_classes(null);
        $data['filters'] = $filters;
        try {
            $data['admissions'] = list_student_admissions($filters, 100, 0);
        } catch (InvalidArgumentException $error) {
            $data['admissionsPageError'] = $error->getMessage();
        }
        $title = 'التسجيل والقبول';
        $contentView = PROJECT_ROOT . '/app/Views/admissions.php';
    } else {
        http_response_code(404);
        $title = 'الصفحة غير موجودة';
        $contentView = PROJECT_ROOT . '/app/Views/not_found.php';
    }
} catch (Throwable $error) {
    app_log_error('legacy_web_action_failed', $error);
    http_response_code(503);
    $title = 'الخدمة غير متاحة';
    $contentView = PROJECT_ROOT . '/app/Views/unavailable.php';
}

$flashes = consume_flashes();
require PROJECT_ROOT . '/app/Views/layout.php';
}
