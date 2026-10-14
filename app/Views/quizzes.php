<section class="section page-section">
  <span class="eyebrow">التقييم والتحصيل</span>
  <h1 class="page-title">الاختبارات الإلكترونية</h1>
  <p class="page-intro">يُصحّح النظام الأسئلة الموضوعية تلقائيًا، وتنتظر الإجابات المقالية مراجعة الأستاذ.</p>
  <?php if (!empty($data['access_denied'])): ?><div class="empty-state"><h2>لا تملك صلاحية عرض الاختبارات</h2></div><?php endif; ?>

  <?php if ($data['can_manage']): ?>
    <details class="detail-card quiz-create" <?= $data['selected_subject_id'] > 0 ? 'open' : '' ?>>
      <summary>إنشاء اختبار من بنك الأسئلة</summary>
      <form method="get" action="/" class="quiz-filter"><input type="hidden" name="page" value="quizzes"><label for="quiz_subject_filter">اختر المادة لاستعراض أسئلتها</label><select id="quiz_subject_filter" name="subject" required><option value="">اختر</option><?php foreach($data['subjects'] as $subject): ?><option value="<?= (int)$subject['class_subject_id'] ?>" <?= $data['selected_subject_id']===(int)$subject['class_subject_id']?'selected':'' ?>><?= e($subject['class_name'].' · '.$subject['subject_name']) ?></option><?php endforeach; ?></select><button class="button button-small">عرض الأسئلة</button></form>
      <?php if ($data['selected_subject_id'] > 0): ?>
        <form method="post" action="<?= e(url('quizzes')) ?>" class="quiz-create-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="quiz_create"><input type="hidden" name="class_subject_id" value="<?= (int)$data['selected_subject_id'] ?>">
          <div class="quiz-form-grid"><label>عنوان الاختبار<input name="title" maxlength="150" required></label><label>مدة الاختبار بالدقائق (اختياري)<input name="duration_minutes" type="number" min="1" max="600"></label><label class="quiz-wide">وصف الاختبار<textarea name="description" rows="2" maxlength="5000"></textarea></label><label>درجة النجاح (اختياري)<input name="passing_marks" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?"></label></div>
          <h2>الأسئلة المعتمدة</h2>
          <?php if (!$data['questions']): ?><p class="muted">لا توجد أسئلة معتمدة لهذه المادة بعد.</p><?php else: ?><div class="quiz-question-picker">
            <?php foreach($data['questions'] as $question): ?><label class="quiz-question-choice"><input type="checkbox" name="question_ids[]" value="<?= (int)$question['id'] ?>"><span><?= e($question['question_text']) ?><small><?= e(match($question['question_type']){'MCQ'=>'اختيار من متعدد','TRUE_FALSE'=>'صح أو خطأ','ESSAY'=>'مقالي',default=>$question['question_type']}) ?></small></span><span class="quiz-mark"><input aria-label="درجة السؤال" type="number" min="0.01" step="0.01" value="1" name="question_marks[<?= (int)$question['id'] ?>]" data-mark-for="<?= (int)$question['id'] ?>" disabled></span></label><?php endforeach; ?>
          </div><p class="muted">حدّد الأسئلة وأدخل درجاتها. إعداد سياسة المحاولات والنتائج مطلوب قبل إتاحة الاختبارات للطلاب.</p><label class="quiz-publish-check"><input type="checkbox" name="publish" value="1" checked>نشر الاختبار فورًا</label><button class="button" type="submit">حفظ الاختبار</button><?php endif; ?>
        </form>
      <?php endif; ?>
    </details>
  <?php endif; ?>

  <?php if (!$data['quizzes']): ?><div class="empty-state"><p>لا توجد اختبارات متاحة في هذا العرض.</p></div><?php else: ?><div class="quiz-list">
    <?php foreach($data['quizzes'] as $quiz): ?><article class="detail-card quiz-card"><div class="quiz-card-meta"><span><?= e($quiz['class_name'].' · '.$quiz['subject_name']) ?></span><?php if ($data['can_manage']): ?><span class="status-pill"><?= database_bool($quiz['is_published'])?'منشور':'مسودة' ?></span><?php endif; ?></div><h2><?= e($quiz['title']) ?></h2><?php if($quiz['description']): ?><p><?= nl2br(e($quiz['description'])) ?></p><?php endif; ?><p class="muted"><?= (int)$quiz['question_count'] ?> سؤال · <?= e((string)$quiz['total_marks']) ?> درجة<?php if($quiz['duration_minutes']): ?> · <?= (int)$quiz['duration_minutes'] ?> دقيقة<?php endif; ?></p>
      <?php if($data['can_manage']): ?>
        <form method="post" action="<?= e(url('quizzes')) ?>" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="quiz_publish"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><input type="hidden" name="publish" value="<?= database_bool($quiz['is_published'])?'0':'1' ?>"><button class="button button-small" type="submit"><?= database_bool($quiz['is_published'])?'إيقاف النشر':'نشر الاختبار' ?></button></form>
        <details class="quiz-attempt-list"><summary>المحاولات (<?= (int)$quiz['attempt_count'] ?>)</summary><?php foreach(($data['attempts_by_quiz'][(int)$quiz['id']]??[]) as $attempt): ?><a class="quiz-attempt-link" href="<?= e(url('quiz_attempt',['id'=>(int)$attempt['id']])) ?>"><?= e($attempt['full_name']) ?> · <?= e(match($attempt['status']){'IN_PROGRESS'=>'قيد الأداء','SUBMITTED'=>'بانتظار التصحيح','GRADED'=>'تم التصحيح','CANCELLED'=>'ملغاة',default=>$attempt['status']}) ?><?php if($attempt['score']!==null): ?> · <?= e((string)$attempt['score']) ?><?php endif; ?></a><?php endforeach; ?></details>
      <?php else: ?>
        <?php if($quiz['active_attempt_id']): ?><a class="button button-small" href="<?= e(url('quiz_attempt',['id'=>(int)$quiz['active_attempt_id']])) ?>">متابعة المحاولة</a><?php else: ?><form method="post" action="<?= e(url('quizzes')) ?>" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="quiz_start"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="button button-small">بدء محاولة جديدة</button></form><?php endif; ?>
        <?php if(!empty($quiz['last_attempt_id'])): ?><a class="button button-small button-secondary" href="<?= e(url('quiz_attempt',['id'=>(int)$quiz['last_attempt_id']])) ?>"><?= $quiz['last_attempt_status']==='IN_PROGRESS'?'متابعة المحاولة':($quiz['last_attempt_status']==='SUBMITTED'?'حالة النتيجة':'عرض النتيجة') ?></a><?php endif; ?>
      <?php endif; ?>
    </article><?php endforeach; ?>
  </div><?php endif; ?>
</section>
