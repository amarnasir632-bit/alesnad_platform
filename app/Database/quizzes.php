<?php
declare(strict_types=1);

function list_quiz_subjects(int $userId): array
{
    $sql = "SELECT cs.id AS class_subject_id,c.name AS class_name,s.name AS subject_name
            FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.is_active
            JOIN subjects s ON s.id=cs.subject_id AND s.is_active WHERE cs.is_active";
    $params = [];
    if (!user_has_permission('roles.manage')) {
        $sql .= ' AND EXISTS (SELECT 1 FROM employees e JOIN teaching_assignments ta ON ta.employee_id=e.id WHERE e.user_id=:user_id AND e.is_active AND ta.class_subject_id=cs.id)';
        $params['user_id'] = $userId;
    }
    $statement = database_connection()->prepare($sql . ' ORDER BY c.name,s.name');
    $statement->execute($params);
    return $statement->fetchAll();
}

function list_quiz_questions_for_author(int $classSubjectId, int $userId): array
{
    if (!user_has_permission('roles.manage')) {
        $assignment = database_connection()->prepare('SELECT 1 FROM employees e JOIN teaching_assignments ta ON ta.employee_id=e.id WHERE e.user_id=:user AND e.is_active AND ta.class_subject_id=:subject');
        $assignment->execute(['user'=>$userId,'subject'=>$classSubjectId]);
        if (!$assignment->fetchColumn()) { return []; }
    }
    $statement = database_connection()->prepare(
        "SELECT id,question_text,question_type FROM questions WHERE class_subject_id=:subject AND status='APPROVED' ORDER BY created_at DESC,id DESC LIMIT 500"
    );
    $statement->execute(['subject'=>$classSubjectId]);
    return $statement->fetchAll();
}

function create_quiz(int $classSubjectId, int $actorId, string $title, string $description, string $duration, string $passing, array $questionMarks, bool $publish): int
{
    if (!user_has_permission('quizzes.manage')) { throw new InvalidArgumentException('ليس لديك صلاحية إنشاء الاختبارات.'); }
    $title = trim($title); $description = trim($description);
    if ($title === '' || mb_strlen($title) > 150 || mb_strlen($description) > 5000) { throw new InvalidArgumentException('راجع عنوان الاختبار ووصفه.'); }
    $duration = trim($duration);
    if ($duration !== '' && (!ctype_digit($duration) || (int)$duration < 1 || (int)$duration > 600)) { throw new InvalidArgumentException('مدة الاختبار من دقيقة إلى 600 دقيقة.'); }
    $passing = trim($passing);
    if ($passing !== '' && !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $passing)) { throw new InvalidArgumentException('درجة النجاح غير صالحة.'); }
    if (!$questionMarks || count($questionMarks) > 100) { throw new InvalidArgumentException('أضف سؤالًا واحدًا على الأقل، وبحد أقصى 100 سؤال.'); }
    $cleanMarks = [];
    $total = 0.0;
    foreach ($questionMarks as $questionId => $mark) {
        if (!is_string((string)$questionId) && !is_int($questionId)) { continue; }
        $id = filter_var((string)$questionId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || !is_string($mark) || !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $mark) || (float)$mark <= 0) {
            throw new InvalidArgumentException('درجات الأسئلة يجب أن تكون أرقامًا موجبة.');
        }
        $cleanMarks[$id] = $mark;
        $total += (float)$mark;
    }
    if ($total <= 0 || $total > 99999999) { throw new InvalidArgumentException('مجموع الدرجات خارج النطاق المسموح.'); }
    if ($passing !== '' && (float)$passing > $total) { throw new InvalidArgumentException('درجة النجاح لا تتجاوز مجموع درجات الاختبار.'); }

    $pdo = database_connection();
    $subject = $pdo->prepare('SELECT 1 FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.is_active JOIN stages st ON st.id=c.stage_id AND st.is_active JOIN subjects s ON s.id=cs.subject_id AND s.is_active WHERE cs.id=:id AND cs.is_active');
    $subject->execute(['id'=>$classSubjectId]);
    if (!$subject->fetchColumn()) { throw new InvalidArgumentException('المادة غير نشطة أو غير موجودة.'); }
    $employee = $pdo->prepare('SELECT id FROM employees WHERE user_id=:user_id AND is_active');
    $employee->execute(['user_id'=>$actorId]);
    $employeeId = $employee->fetchColumn();
    if (!$employeeId) { throw new InvalidArgumentException('حساب الأستاذ غير نشط.'); }
    if (!user_has_permission('roles.manage')) {
        $assigned = $pdo->prepare('SELECT 1 FROM teaching_assignments WHERE employee_id=:employee AND class_subject_id=:subject');
        $assigned->execute(['employee'=>$employeeId,'subject'=>$classSubjectId]);
        if (!$assigned->fetchColumn()) { throw new InvalidArgumentException('المادة غير مسندة إليك.'); }
    }
    $pdo->beginTransaction();
    try {
        $questionIds = array_keys($cleanMarks);
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $eligible = $pdo->prepare("SELECT id FROM questions WHERE class_subject_id=? AND status='APPROVED' AND id IN ({$placeholders})");
        $eligible->execute(array_merge([$classSubjectId], $questionIds));
        $eligibleIds = array_map('intval', $eligible->fetchAll(PDO::FETCH_COLUMN));
        if (count($eligibleIds) !== count($cleanMarks)) { throw new InvalidArgumentException('الاختبار يقبل الأسئلة المعتمدة للمادة نفسها فقط.'); }
        $insert = $pdo->prepare('INSERT INTO quizzes (class_subject_id,title,description,duration_minutes,total_marks,passing_marks,is_published,requires_subscription,created_by) VALUES (:subject,:title,:description,:duration,:total,:passing,:published,FALSE,:creator) RETURNING id');
        $insert->execute([
            'subject'=>$classSubjectId,'title'=>$title,'description'=>$description !== '' ? $description : null,
            'duration'=>$duration !== '' ? (int)$duration : null,'total'=>number_format($total,2,'.',''),
            'passing'=>$passing !== '' ? $passing : null,'published'=>$publish,'creator'=>$employeeId,
        ]);
        $quizId = (int)$insert->fetchColumn();
        $link = $pdo->prepare('INSERT INTO quiz_questions (quiz_id,question_id,question_mark,sort_order) VALUES (:quiz,:question,:mark,:sort)');
        $sort = 1;
        foreach ($cleanMarks as $questionId=>$mark) { $link->execute(['quiz'=>$quizId,'question'=>$questionId,'mark'=>$mark,'sort'=>$sort++]); }
        record_audit_event($pdo,$actorId,'quiz.created','quiz',$quizId,['question_count'=>count($cleanMarks),'published'=>$publish]);
        $pdo->commit();
        return $quizId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function set_quiz_published(int $quizId,int $userId,bool $publish): void
{
    if(!user_has_permission('quizzes.manage')||!user_can_manage_quiz($quizId,$userId)){throw new InvalidArgumentException('ليس لديك صلاحية إدارة هذا الاختبار.');}
    $pdo=database_connection();
    if($publish){
        $check=$pdo->prepare('SELECT COUNT(*) AS question_count,COALESCE(SUM(question_mark),0) AS marks FROM quiz_questions WHERE quiz_id=:quiz');
        $check->execute(['quiz'=>$quizId]);$row=$check->fetch();
        if(!$row||(int)$row['question_count']<1||(float)$row['marks']<=0){throw new InvalidArgumentException('أضف أسئلة ودرجات صالحة قبل نشر الاختبار.');}
    }
    $pdo->beginTransaction();
    try {
        $updated=$pdo->prepare('UPDATE quizzes SET is_published=:published WHERE id=:quiz');
        $updated->execute(['published'=>$publish,'quiz'=>$quizId]);
        if($updated->rowCount()<1){throw new InvalidArgumentException('الاختبار غير موجود.');}
        record_audit_event($pdo,$userId,$publish?'quiz.published':'quiz.unpublished','quiz',$quizId,[]);
        $pdo->commit();
    } catch(Throwable $error) { if($pdo->inTransaction()){$pdo->rollBack();} throw $error; }
}

function list_available_quizzes(int $userId, bool $forTeacher): array
{
    if ($forTeacher) {
        $where = user_has_permission('roles.manage') ? '' :
            ' WHERE q.created_by=(SELECT id FROM employees WHERE user_id=:user_id) OR EXISTS (SELECT 1 FROM employees e JOIN teaching_assignments ta ON ta.employee_id=e.id WHERE e.user_id=:user_id2 AND ta.class_subject_id=q.class_subject_id)';
        $sql =
            "SELECT q.id,q.title,q.description,q.duration_minutes,q.total_marks,q.passing_marks,q.is_published,q.created_at,
                    cs.id AS class_subject_id,c.name AS class_name,s.name AS subject_name,
                    (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS question_count,
                    (SELECT COUNT(*) FROM quiz_attempts a WHERE a.quiz_id=q.id) AS attempt_count
             FROM quizzes q JOIN class_subjects cs ON cs.id=q.class_subject_id JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id" . $where;
        $params=user_has_permission('roles.manage')?[]:['user_id'=>$userId,'user_id2'=>$userId];
        $statement = database_connection()->prepare($sql . ' ORDER BY q.created_at DESC');
        $statement->execute($params);
        return $statement->fetchAll();
    }
    $statement = database_connection()->prepare(
        "SELECT q.id,q.title,q.description,q.duration_minutes,q.total_marks,q.passing_marks,q.created_at,
                c.name AS class_name,s.name AS subject_name,
                (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS question_count,
                (SELECT id FROM quiz_attempts a WHERE a.quiz_id=q.id AND a.student_id=st.id AND a.status='IN_PROGRESS' ORDER BY a.attempt_number DESC LIMIT 1) AS active_attempt_id,
                (SELECT status FROM quiz_attempts a WHERE a.quiz_id=q.id AND a.student_id=st.id ORDER BY a.attempt_number DESC LIMIT 1) AS last_attempt_status,
                (SELECT score FROM quiz_attempts a WHERE a.quiz_id=q.id AND a.student_id=st.id ORDER BY a.attempt_number DESC LIMIT 1) AS last_score
         FROM quizzes q JOIN class_subjects cs ON cs.id=q.class_subject_id JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id
         JOIN students st ON st.user_id=:user_id JOIN users u ON u.id=st.user_id AND u.is_active
         WHERE q.is_published AND st.class_id=cs.class_id
           AND (q.starts_at IS NULL OR q.starts_at<=CURRENT_TIMESTAMP) AND (q.ends_at IS NULL OR q.ends_at>CURRENT_TIMESTAMP)
           AND EXISTS (SELECT 1 FROM manual_content_access a WHERE a.student_id=st.id AND a.revoked_at IS NULL)
         ORDER BY q.created_at DESC"
    );
    $statement->execute(['user_id'=>$userId]);
    return $statement->fetchAll();
}

function start_quiz_attempt(int $quizId, int $userId): int
{
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $quizStatement = $pdo->prepare(
             "SELECT q.id,q.class_subject_id,q.is_published,q.starts_at,q.ends_at,cs.class_id,st.id AS student_id,st.class_id AS student_class_id,u.is_active AS student_active,
                    EXISTS(SELECT 1 FROM manual_content_access a WHERE a.student_id=st.id AND a.revoked_at IS NULL) AS has_access
             FROM quizzes q JOIN class_subjects cs ON cs.id=q.class_subject_id JOIN students st ON st.user_id=:user_id JOIN users u ON u.id=st.user_id
             WHERE q.id=:quiz_id FOR UPDATE OF q"
        );
        $quizStatement->execute(['user_id'=>$userId,'quiz_id'=>$quizId]);
        $quiz=$quizStatement->fetch();
        if (!$quiz || !database_bool($quiz['student_active']) || !database_bool($quiz['is_published']) || (int)$quiz['class_id'] !== (int)$quiz['student_class_id'] || !database_bool($quiz['has_access'])) { throw new InvalidArgumentException('الاختبار غير متاح لهذا الحساب.'); }
        $time = $pdo->prepare('SELECT CASE WHEN (CAST(:starts_null AS TIMESTAMPTZ) IS NULL OR CAST(:starts_check AS TIMESTAMPTZ)<=CURRENT_TIMESTAMP) AND (CAST(:ends_null AS TIMESTAMPTZ) IS NULL OR CAST(:ends_check AS TIMESTAMPTZ)>CURRENT_TIMESTAMP) THEN 1 ELSE 0 END');
        $time->execute(['starts_null'=>$quiz['starts_at'],'starts_check'=>$quiz['starts_at'],'ends_null'=>$quiz['ends_at'],'ends_check'=>$quiz['ends_at']]);
        if (!database_bool($time->fetchColumn())) { throw new InvalidArgumentException('الاختبار خارج فترة الإتاحة.'); }
        $active = $pdo->prepare("SELECT id FROM quiz_attempts WHERE quiz_id=:quiz AND student_id=:student AND status='IN_PROGRESS' FOR UPDATE");
        $active->execute(['quiz'=>$quizId,'student'=>$quiz['student_id']]);
        $attemptId=$active->fetchColumn();
        if ($attemptId !== false) { $pdo->commit(); return (int)$attemptId; }
        $number = $pdo->prepare('SELECT COALESCE(MAX(attempt_number),0)+1 FROM quiz_attempts WHERE quiz_id=:quiz AND student_id=:student');
        $number->execute(['quiz'=>$quizId,'student'=>$quiz['student_id']]);
        $insert=$pdo->prepare('INSERT INTO quiz_attempts (quiz_id,student_id,attempt_number) VALUES (:quiz,:student,:number) RETURNING id');
        $insert->execute(['quiz'=>$quizId,'student'=>$quiz['student_id'],'number'=>(int)$number->fetchColumn()]);
        $id=(int)$insert->fetchColumn();
        record_audit_event($pdo,$userId,'quiz.attempt_started','quiz_attempt',$id,['quiz_id'=>$quizId]);
        $pdo->commit();
        return $id;
    } catch (Throwable $error) { if ($pdo->inTransaction()) {$pdo->rollBack();} throw $error; }
}

function get_quiz_attempt(int $attemptId, int $userId): array
{
    $statement=database_connection()->prepare(
        'SELECT a.id,a.quiz_id,a.status,a.started_at,a.submitted_at,a.score,q.title,q.duration_minutes,q.total_marks,q.passing_marks,
                st.user_id AS student_user_id,q.class_subject_id,
                EXISTS(SELECT 1 FROM manual_content_access ca JOIN class_subjects acs ON acs.id=q.class_subject_id WHERE ca.student_id=st.id AND st.class_id=acs.class_id AND ca.revoked_at IS NULL) AS student_has_access,
                EXISTS(SELECT 1 FROM users su WHERE su.id=st.user_id AND su.is_active) AS student_active,u.full_name AS student_name
         FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN class_subjects cs ON cs.id=q.class_subject_id JOIN students st ON st.id=a.student_id JOIN users u ON u.id=st.user_id WHERE a.id=:id'
    );
    $statement->execute(['id'=>$attemptId]); $attempt=$statement->fetch();
    if (!$attempt) { throw new InvalidArgumentException('المحاولة غير متاحة.'); }
    if ((int)$attempt['student_user_id'] === $userId) {
        if (!database_bool($attempt['student_active']) || !database_bool($attempt['student_has_access'])) { throw new InvalidArgumentException('المحاولة غير متاحة بعد سحب صلاحية المحتوى.'); }
    } elseif (!user_can_manage_quiz((int)$attempt['quiz_id'],$userId)) {
        throw new InvalidArgumentException('المحاولة غير متاحة.');
    }
    $questions=database_connection()->prepare(
        'SELECT qu.question_id,qu.question_mark,qu.sort_order,q.question_text,q.question_type,
                COALESCE((SELECT json_agg(json_build_object(\'id\',o.id,\'text\',o.option_text) ORDER BY o.sort_order) FROM question_options o WHERE o.question_id=q.id),\'[]\'::json) AS options,
                sa.selected_option_id,sa.essay_answer,sa.awarded_mark,sa.teacher_feedback
         FROM quiz_questions qu JOIN questions q ON q.id=qu.question_id
         LEFT JOIN student_answers sa ON sa.question_id=q.id AND sa.attempt_id=:attempt
         WHERE qu.quiz_id=:quiz ORDER BY qu.sort_order'
    );
    $questions->execute(['attempt'=>$attemptId,'quiz'=>$attempt['quiz_id']]);
    $attempt['questions']=$questions->fetchAll();
    return $attempt;
}

function user_can_manage_quiz(int $quizId,int $userId): bool
{
    if (user_has_permission('roles.manage')) { return true; }
    $statement=database_connection()->prepare("SELECT EXISTS(SELECT 1 FROM quizzes q WHERE q.id=:quiz AND (q.created_by=(SELECT id FROM employees WHERE user_id=:user) OR EXISTS(SELECT 1 FROM employees e JOIN teaching_assignments ta ON ta.employee_id=e.id WHERE e.user_id=:user2 AND e.is_active AND ta.class_subject_id=q.class_subject_id)))");
    $statement->execute(['quiz'=>$quizId,'user'=>$userId,'user2'=>$userId]);
    return database_bool($statement->fetchColumn());
}

function save_quiz_answer(int $attemptId,int $questionId,int $userId,?int $optionId,string $essay): void
{
    $attempt=get_quiz_attempt($attemptId,$userId);
    if ($attempt['status']!=='IN_PROGRESS') { throw new InvalidArgumentException('تم تسليم هذه المحاولة.'); }
    $pdo=database_connection();
    $scope=$pdo->prepare("SELECT 1 FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN quiz_questions qq ON qq.quiz_id=q.id WHERE a.id=:attempt AND a.student_id=(SELECT id FROM students WHERE user_id=:user) AND a.status='IN_PROGRESS' AND qq.question_id=:question AND (q.duration_minutes IS NULL OR CURRENT_TIMESTAMP<=a.started_at+(q.duration_minutes*INTERVAL '1 minute'))");
    $scope->execute(['attempt'=>$attemptId,'user'=>$userId,'question'=>$questionId]);
    if (!$scope->fetchColumn()) { throw new InvalidArgumentException('انتهى وقت الاختبار أو أن السؤال غير تابع له.'); }
    $item=null; foreach ($attempt['questions'] as $question) if ((int)$question['question_id']===$questionId) {$item=$question;break;}
    if (!$item) { throw new InvalidArgumentException('السؤال غير تابع لهذا الاختبار.'); }
    $essay=trim($essay);
        if ($item['question_type']==='ESSAY') {
        if ($optionId!==null || mb_strlen($essay)>10000) { throw new InvalidArgumentException('الإجابة المقالية غير صالحة.'); }
        if ($essay==='') { $pdo->prepare('DELETE FROM student_answers WHERE attempt_id=:attempt AND question_id=:question')->execute(['attempt'=>$attemptId,'question'=>$questionId]); return; }
        $pdo->prepare('INSERT INTO student_answers (attempt_id,question_id,essay_answer) VALUES (:attempt,:question,:essay) ON CONFLICT (attempt_id,question_id) DO UPDATE SET selected_option_id=NULL,essay_answer=EXCLUDED.essay_answer,is_correct=NULL,awarded_mark=NULL,answered_at=CURRENT_TIMESTAMP')->execute(['attempt'=>$attemptId,'question'=>$questionId,'essay'=>$essay]);
    } else {
        if ($essay!=='' || $optionId===null) { if($optionId===null){$pdo->prepare('DELETE FROM student_answers WHERE attempt_id=:attempt AND question_id=:question')->execute(['attempt'=>$attemptId,'question'=>$questionId]);return;} throw new InvalidArgumentException('اختر إجابة واحدة.'); }
        $valid=$pdo->prepare('SELECT 1 FROM question_options WHERE id=:option AND question_id=:question'); $valid->execute(['option'=>$optionId,'question'=>$questionId]);
        if (!$valid->fetchColumn()) { throw new InvalidArgumentException('الخيار لا ينتمي لهذا السؤال.'); }
        $pdo->prepare('INSERT INTO student_answers (attempt_id,question_id,selected_option_id) VALUES (:attempt,:question,:option) ON CONFLICT (attempt_id,question_id) DO UPDATE SET selected_option_id=EXCLUDED.selected_option_id,essay_answer=NULL,is_correct=NULL,awarded_mark=NULL,answered_at=CURRENT_TIMESTAMP')->execute(['attempt'=>$attemptId,'question'=>$questionId,'option'=>$optionId]);
    }
}

function submit_quiz_attempt(int $attemptId,int $userId): void
{
    $pdo=database_connection(); $pdo->beginTransaction();
    try {
        $query=$pdo->prepare("SELECT a.id,a.status,a.started_at,q.duration_minutes,q.total_marks,st.id AS student_id FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN students st ON st.id=a.student_id WHERE a.id=:id AND st.user_id=:user FOR UPDATE OF a");
        $query->execute(['id'=>$attemptId,'user'=>$userId]); $attempt=$query->fetch();
        if (!$attempt || $attempt['status']!=='IN_PROGRESS') { throw new InvalidArgumentException('المحاولة غير موجودة أو سبق تسليمها.'); }
        $expiredQuery=$pdo->prepare("SELECT (q.duration_minutes IS NOT NULL AND CURRENT_TIMESTAMP>a.started_at+(q.duration_minutes*INTERVAL '1 minute')) FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id WHERE a.id=:attempt");
        $expiredQuery->execute(['attempt'=>$attemptId]);$expired=database_bool($expiredQuery->fetchColumn());
        $grade=$pdo->prepare("UPDATE student_answers sa SET is_correct=(qo.is_correct),awarded_mark=CASE WHEN qo.is_correct THEN qq.question_mark ELSE 0 END FROM quiz_questions qq JOIN question_options qo ON qo.question_id=qq.question_id WHERE sa.attempt_id=:attempt AND qq.quiz_id=(SELECT quiz_id FROM quiz_attempts WHERE id=:attempt2) AND qq.question_id=sa.question_id AND qo.id=sa.selected_option_id RETURNING sa.awarded_mark");
        $grade->execute(['attempt'=>$attemptId,'attempt2'=>$attemptId]);
        $unanswered=$pdo->prepare('SELECT COUNT(*) FROM quiz_questions qq JOIN quiz_attempts a ON a.quiz_id=qq.quiz_id LEFT JOIN student_answers sa ON sa.attempt_id=a.id AND sa.question_id=qq.question_id WHERE a.id=:attempt AND sa.id IS NULL');
        $unanswered->execute(['attempt'=>$attemptId]);
        $unansweredCount=(int)$unanswered->fetchColumn();
        if (!$expired && $unansweredCount>0) { throw new InvalidArgumentException('أجب عن جميع الأسئلة قبل التسليم.'); }
        if ($expired) {
            $emptyEssays=$pdo->prepare("INSERT INTO student_answers (attempt_id,question_id,essay_answer,awarded_mark) SELECT :attempt,qq.question_id,'',0 FROM quiz_questions qq JOIN quiz_attempts a ON a.quiz_id=qq.quiz_id JOIN questions q ON q.id=qq.question_id LEFT JOIN student_answers sa ON sa.attempt_id=a.id AND sa.question_id=qq.question_id WHERE a.id=:attempt2 AND q.question_type='ESSAY' AND sa.id IS NULL");
            $emptyEssays->execute(['attempt'=>$attemptId,'attempt2'=>$attemptId]);
        }
        $essayCount=$pdo->prepare("SELECT COUNT(*) FROM quiz_questions qq JOIN questions q ON q.id=qq.question_id JOIN student_answers sa ON sa.question_id=q.id AND sa.attempt_id=:attempt WHERE qq.quiz_id=(SELECT quiz_id FROM quiz_attempts WHERE id=:attempt2) AND q.question_type='ESSAY' AND sa.essay_answer<>''");
        $essayCount->execute(['attempt'=>$attemptId,'attempt2'=>$attemptId]); $hasEssay=(int)$essayCount->fetchColumn()>0;
        $score=$pdo->prepare('SELECT COALESCE(SUM(awarded_mark),0) FROM student_answers WHERE attempt_id=:attempt'); $score->execute(['attempt'=>$attemptId]); $marks=$score->fetchColumn();
        $status=$hasEssay?'SUBMITTED':'GRADED';
        $pdo->prepare('UPDATE quiz_attempts SET submitted_at=CURRENT_TIMESTAMP,score=:score,status=:status WHERE id=:id')->execute(['score'=>$marks,'status'=>$status,'id'=>$attemptId]);
        record_audit_event($pdo,$userId,'quiz.attempt_submitted','quiz_attempt',$attemptId,['status'=>$status]);
        $pdo->commit();
    } catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}throw $error;}
}

function list_quiz_attempts_for_teacher(int $quizId,int $userId): array
{
    if(!user_can_manage_quiz($quizId,$userId)){throw new InvalidArgumentException('ليس لديك صلاحية عرض نتائج هذا الاختبار.');}
    $statement=database_connection()->prepare('SELECT a.id,a.attempt_number,a.started_at,a.submitted_at,a.score,a.status,u.full_name FROM quiz_attempts a JOIN students st ON st.id=a.student_id JOIN users u ON u.id=st.user_id WHERE a.quiz_id=:quiz ORDER BY a.started_at DESC');
    $statement->execute(['quiz'=>$quizId]);return $statement->fetchAll();
}

function list_quiz_attempt_answers(int $attemptId,int $userId): array
{
    $attempt=database_connection()->prepare('SELECT quiz_id FROM quiz_attempts WHERE id=:id');$attempt->execute(['id'=>$attemptId]);$quizId=$attempt->fetchColumn();
    if(!$quizId||!user_can_manage_quiz((int)$quizId,$userId)){throw new InvalidArgumentException('ليس لديك صلاحية مراجعة هذه المحاولة.');}
    $statement=database_connection()->prepare("SELECT q.id AS question_id,q.question_text,q.question_type,qq.question_mark,sa.selected_option_id,sa.essay_answer,sa.is_correct,sa.awarded_mark,sa.teacher_feedback,chosen.option_text AS chosen_option_text,correct.option_text AS correct_option_text
        FROM quiz_questions qq JOIN questions q ON q.id=qq.question_id LEFT JOIN student_answers sa ON sa.question_id=q.id AND sa.attempt_id=:attempt
        LEFT JOIN question_options chosen ON chosen.id=sa.selected_option_id
        LEFT JOIN question_options correct ON correct.question_id=q.id AND correct.is_correct
        WHERE qq.quiz_id=:quiz ORDER BY qq.sort_order");
    $statement->execute(['attempt'=>$attemptId,'quiz'=>$quizId]);return $statement->fetchAll();
}

function grade_essay_answer(int $attemptId,int $questionId,int $userId,string $mark,string $feedback): void
{
    if(!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/',$mark)||mb_strlen($feedback)>2000){throw new InvalidArgumentException('درجة المقال أو الملاحظة غير صالحة.');}
    $pdo=database_connection();
    $attemptQuery=$pdo->prepare('SELECT quiz_id FROM quiz_attempts WHERE id=:id');$attemptQuery->execute(['id'=>$attemptId]);$quizId=$attemptQuery->fetchColumn();
    if(!$quizId||!user_can_manage_quiz((int)$quizId,$userId)){throw new InvalidArgumentException('ليس لديك صلاحية تصحيح هذا الاختبار.');}
    $pdo->beginTransaction();
    try{
        $scope=$pdo->prepare("SELECT a.status,qq.question_mark,q.question_type,cs.id AS class_subject_id,st.id AS student_id FROM quiz_attempts a JOIN quizzes z ON z.id=a.quiz_id JOIN class_subjects cs ON cs.id=z.class_subject_id JOIN quiz_questions qq ON qq.quiz_id=z.id AND qq.question_id=:question JOIN questions q ON q.id=qq.question_id JOIN students st ON st.id=a.student_id WHERE a.id=:attempt AND a.status='SUBMITTED' AND q.question_type='ESSAY' FOR UPDATE OF a");
        $scope->execute(['question'=>$questionId,'attempt'=>$attemptId]);$row=$scope->fetch();
        if(!$row||(float)$mark>(float)$row['question_mark']){throw new InvalidArgumentException('السؤال غير متاح للتصحيح أو الدرجة تتجاوز الحد.');}
        $pdo->prepare('UPDATE student_answers SET is_correct=NULL,awarded_mark=:mark,teacher_feedback=:feedback WHERE attempt_id=:attempt AND question_id=:question AND essay_answer IS NOT NULL')->execute(['mark'=>$mark,'feedback'=>trim($feedback)?:null,'attempt'=>$attemptId,'question'=>$questionId]);
        $remaining=$pdo->prepare("SELECT COUNT(*) FROM quiz_questions qq JOIN questions q ON q.id=qq.question_id LEFT JOIN student_answers sa ON sa.attempt_id=:attempt AND sa.question_id=q.id WHERE qq.quiz_id=(SELECT quiz_id FROM quiz_attempts WHERE id=:attempt2) AND q.question_type='ESSAY' AND (sa.awarded_mark IS NULL)");
        $remaining->execute(['attempt'=>$attemptId,'attempt2'=>$attemptId]);
        $score=$pdo->prepare('SELECT COALESCE(SUM(awarded_mark),0) FROM student_answers WHERE attempt_id=:attempt3');$score->execute(['attempt3'=>$attemptId]);
        if((int)$remaining->fetchColumn()===0){$pdo->prepare("UPDATE quiz_attempts SET score=:score,status='GRADED' WHERE id=:attempt4")->execute(['score'=>$score->fetchColumn(),'attempt4'=>$attemptId]);}
        else {$pdo->prepare('UPDATE quiz_attempts SET score=:score WHERE id=:attempt4')->execute(['score'=>$score->fetchColumn(),'attempt4'=>$attemptId]);}
        record_audit_event($pdo,$userId,'quiz.essay_graded','quiz_attempt',$attemptId,['question_id'=>$questionId,'mark'=>$mark,'feedback'=>$feedback?:null]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}throw $error;}
}
