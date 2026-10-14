<?php
declare(strict_types=1);

function handle_web_request(): void
{
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self' data:; script-src 'self'; frame-src https://*.cloudflarestream.com; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

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
        if (!password_meets_policy($password)) {
            $errors[] = 'يجب أن تتكون كلمة المرور من 12 حرفًا على الأقل وألا تتجاوز 1024 بايت.';
        }
        if (!hash_equals($password, $confirmation)) {
            $errors[] = 'تأكيد كلمة المرور غير مطابق.';
        }

        if (!$errors) {
            try {
                register_student($fullName, $email, $phone, $password, (int) $classId);
                flash('success', 'تم إنشاء الحساب. إتاحة المحتوى تتطلب موافقة يدوية من إدارة المنصة.');
                redirect(url('home'));
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
    } elseif ($action === 'library_upload') {
        try {
            $user = require_permission('content.upload');
            $classSubjectId = filter_var($_POST['class_subject_id'] ?? null, FILTER_VALIDATE_INT);
            if ($classSubjectId === false || $classSubjectId < 1) {
                throw new InvalidArgumentException('اختر المادة الدراسية.');
            }
            create_library_item(
                $classSubjectId,
                post_string('kind'),
                post_string('title'),
                post_string('description'),
                is_array($_FILES['document'] ?? null) ? $_FILES['document'] : [],
                post_string('external_url'),
                post_string('source_owner'),
                post_string('copyright_license'),
                (int) $user['id']
            );
            flash('success', 'تم إرسال المحتوى للمراجعة، ولن يظهر للطلاب قبل اعتماده.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (Throwable $error) {
            app_log_error('legacy_web_action_failed', $error);
            flash('error', 'تعذر رفع المحتوى الآن. تحقق من حجم الملف وإعداد التخزين.');
        }
        redirect(url('library'));
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
    } elseif (in_array($action, ['academics_add_stage', 'academics_add_class', 'academics_add_subject'], true)) {
        try {
            $user = require_permission('academics.manage');
            if ($action === 'academics_add_stage') {
                $sortOrder = filter_var($_POST['sort_order'] ?? null, FILTER_VALIDATE_INT);
                if ($sortOrder === false) {
                    throw new InvalidArgumentException('أدخل ترتيب عرض صحيحًا.');
                }
                create_academic_stage(post_string('code'), post_string('name'), $sortOrder, $user['id']);
                flash('success', 'تمت إضافة المرحلة.');
            } elseif ($action === 'academics_add_class') {
                $stageId = filter_var($_POST['stage_id'] ?? null, FILTER_VALIDATE_INT);
                $sortOrder = filter_var($_POST['sort_order'] ?? null, FILTER_VALIDATE_INT);
                if ($stageId === false || $stageId < 1 || $sortOrder === false) {
                    throw new InvalidArgumentException('اختر مرحلة وأدخل ترتيب عرض صحيحًا.');
                }
                create_academic_class($stageId, post_string('code'), post_string('name'), $sortOrder, $user['id']);
                flash('success', 'تمت إضافة الصف.');
            } else {
                $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
                if ($classId === false || $classId < 1) {
                    throw new InvalidArgumentException('اختر صفًا صحيحًا.');
                }
                create_subject_assignment($classId, post_string('name'), post_string('code'), $user['id']);
                flash('success', 'تمت إضافة المادة وربطها بالصف.');
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
            $data['is_owner'] = (int)$data['attempt']['student_user_id'] === (int)$user['id'];
            $data['is_teacher'] = !$data['is_owner'];
            $data['result_available'] = false;
            if ($data['is_owner'] && $data['attempt']['status'] === 'GRADED') {
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
        $user = require_permission('messages.use');
        $userId = (int) $user['id'];
        $data['contacts'] = list_message_contacts($userId);
        $data['conversations'] = list_message_conversations($userId);
        $data['peer'] = null;
        $data['thread'] = [];
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
            } else {
                flash('error', 'جهة الاتصال غير متاحة للمراسلة.');
            }
        }
        $title = 'الرسائل';
        $contentView = PROJECT_ROOT . '/app/Views/messages.php';
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
        $data['subjects'] = list_class_subjects($page === 'home' ? 3 : 50);
        if ($page === 'subjects') {
            $title = 'المواد الدراسية';
            $contentView = PROJECT_ROOT . '/app/Views/subjects.php';
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
            $data['can_view_content'] = $user !== null
                && (user_has_permission('content.publish') || student_has_manual_content_access((int) $user['id']));
            $data['lessons'] = $data['can_view_content']
                ? list_published_lessons((int) $data['subject']['class_subject_id'])
                : [];
            $title = $data['subject']['subject_name'] . ' | المواد الدراسية';
            $contentView = PROJECT_ROOT . '/app/Views/subject.php';
        }
    } elseif ($page === 'register') {
        $data['classes'] = academic_list_classes(null);
        $title = 'إنشاء حساب';
        $contentView = PROJECT_ROOT . '/app/Views/register.php';
    } elseif ($page === 'login') {
        $title = 'تسجيل الدخول';
        $contentView = PROJECT_ROOT . '/app/Views/login.php';
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
