<?php
$questionTypes = ['MCQ' => 'اختيار من متعدد', 'TRUE_FALSE' => 'صح أو خطأ', 'ESSAY' => 'مقالي'];
$difficultyNames = ['EASY' => 'سهل', 'MEDIUM' => 'متوسط', 'HARD' => 'متقدم'];
$statusNames = ['DRAFT' => 'مسودة', 'PENDING_REVIEW' => 'بانتظار المراجعة', 'APPROVED' => 'منشور', 'REJECTED' => 'مرفوض'];
?>
<section class="section page-section">
  <span class="eyebrow">التعلّم والتقييم</span>
  <h1 class="page-title">بنك الأسئلة</h1>
  <p class="page-intro">تُصنّف الأسئلة حسب المرحلة والصف والمادة والدرس والنوع والصعوبة. لا يظهر السؤال للطلاب قبل اعتماده.</p>

  <?php if ($data['can_create']): ?>
  <details class="detail-card question-create">
    <summary class="button">إضافة سؤال</summary>
    <form method="post" action="<?= e(url('questions')) ?>" class="admin-form">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="question_create">
      <div class="form-field"><label for="question_subject">المرحلة والصف والمادة</label>
        <select id="question_subject" name="class_subject_id" required><option value="">اختر المادة المسندة</option>
          <?php foreach ($data['subjects'] as $subject): ?><option value="<?= (int) $subject['class_subject_id'] ?>"><?= e($subject['stage_name'] . ' · ' . $subject['class_name'] . ' · ' . $subject['subject_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-field"><label for="question_lesson">الدرس (اختياري)</label>
        <select id="question_lesson" name="lesson_id"><option value="">بدون ربط بدرس</option>
          <?php foreach ($data['lessons_by_subject'] as $subjectId => $lessons): foreach ($lessons as $lesson): ?><option data-subject="<?= (int) $subjectId ?>" value="<?= (int) $lesson['id'] ?>"><?= e($lesson['title']) ?></option><?php endforeach; endforeach; ?>
        </select>
      </div>
      <div class="form-field"><label for="question_type">نوع السؤال</label><select id="question_type" name="question_type"><option value="MCQ">اختيار من متعدد</option><option value="TRUE_FALSE">صح أو خطأ</option><option value="ESSAY">مقالي</option></select></div>
      <div class="form-field"><label for="question_difficulty">الصعوبة</label><select id="question_difficulty" name="difficulty"><option value="EASY">سهل</option><option value="MEDIUM" selected>متوسط</option><option value="HARD">متقدم</option></select></div>
      <div class="form-field"><label for="question_text">نص السؤال</label><textarea id="question_text" name="question_text" rows="4" maxlength="10000" required></textarea></div>
      <fieldset class="question-options"><legend>خيارات الإجابة (للاختيار من متعدد)</legend>
        <?php for ($i = 0; $i < 4; $i++): ?><label class="question-option"><input type="radio" name="correct_option" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>><input name="options[]" maxlength="2000" placeholder="الخيار <?= $i + 1 ?>"></label><?php endfor; ?>
      </fieldset>
      <div class="form-field question-tf-answer"><label for="correct_tf">الإجابة الصحيحة (للصح والخطأ)</label><select id="correct_tf" name="correct_option_tf"><option value="0">صحيح</option><option value="1">خطأ</option></select></div>
      <div class="form-field"><label for="question_explanation">التفسير (اختياري)</label><textarea id="question_explanation" name="explanation" rows="2" maxlength="10000"></textarea></div>
      <div class="form-field"><label for="question_status">الحفظ</label><select id="question_status" name="status"><option value="PENDING_REVIEW">إرسال للمراجعة</option><option value="DRAFT">حفظ كمسودة</option></select></div>
      <button class="button" type="submit">حفظ السؤال</button>
    </form>
  </details>
  <?php endif; ?>

  <div class="question-bank">
    <?php if (!$data['questions']): ?><div class="empty-state"><p>لا توجد أسئلة في هذا العرض حتى الآن.</p></div><?php endif; ?>
    <?php foreach ($data['questions'] as $question): $options = json_decode((string) $question['options'], true) ?: []; ?>
      <article class="detail-card question-card">
        <div class="question-meta"><span><?= e($question['stage_name'] . ' · ' . $question['class_name'] . ' · ' . $question['subject_name']) ?></span>
          <?php if ($question['lesson_title']): ?><span><?= e($question['lesson_title']) ?></span><?php endif; ?>
          <span><?= e($questionTypes[$question['question_type']] ?? $question['question_type']) ?> · <?= e($difficultyNames[$question['difficulty']] ?? $question['difficulty']) ?></span>
          <?php if ($data['can_review']): ?><span class="status-pill"><?= e($statusNames[$question['status']] ?? $question['status']) ?></span><?php endif; ?>
        </div>
        <h2><?= nl2br(e($question['question_text'])) ?></h2>
        <?php if ($options): ?><ol class="question-option-list"><?php foreach ($options as $option): ?><li><?= e($option['text']) ?><?php if ($data['can_review'] && $option['correct']): ?> <strong>(الإجابة الصحيحة)</strong><?php endif; ?></li><?php endforeach; ?></ol><?php endif; ?>
        <?php if ($data['can_review'] && $question['explanation']): ?><p><strong>التفسير:</strong> <?= e($question['explanation']) ?></p><?php endif; ?>
        <?php if ($data['can_review'] && in_array($question['status'], ['PENDING_REVIEW', 'REJECTED'], true)): ?>
          <form method="post" action="<?= e(url('questions')) ?>" class="question-review-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="question_review"><input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
            <label>ملاحظة المراجع <input name="feedback" maxlength="3000"></label>
            <button class="button button-small" name="decision" value="approve">اعتماد ونشر</button>
            <button class="button button-small" name="decision" value="revise">إرجاع للتعديل</button>
            <button class="button button-small button-danger" name="decision" value="reject">رفض</button>
          </form>
        <?php endif; ?>
        <?php if ($data['can_create'] && (user_has_permission('roles.manage') || (int) $question['author_user_id'] === (int) current_user()['id']) && in_array($question['status'], ['DRAFT', 'REJECTED'], true)): ?>
          <?php if ($question['latest_feedback']): ?><p class="question-feedback"><strong>ملاحظة المراجع:</strong> <?= e($question['latest_feedback']) ?></p><?php endif; ?>
          <details class="question-edit">
            <summary>تعديل السؤال</summary>
            <form method="post" action="<?= e(url('questions')) ?>" class="question-edit-form">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="question_update"><input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
              <input type="hidden" name="question_type" value="<?= e($question['question_type']) ?>">
              <label>نص السؤال<textarea name="question_text" rows="3" maxlength="10000" required><?= e($question['question_text']) ?></textarea></label>
              <label>الصعوبة<select name="difficulty"><option value="EASY" <?= $question['difficulty'] === 'EASY' ? 'selected' : '' ?>>سهل</option><option value="MEDIUM" <?= $question['difficulty'] === 'MEDIUM' ? 'selected' : '' ?>>متوسط</option><option value="HARD" <?= $question['difficulty'] === 'HARD' ? 'selected' : '' ?>>متقدم</option></select></label>
              <?php if ($question['question_type'] === 'MCQ' || $question['question_type'] === 'TRUE_FALSE'): ?>
                <fieldset class="question-options"><legend>الإجابات (حدد الصحيحة)</legend>
                  <?php foreach ($options as $index => $option): ?><label class="question-option"><input type="radio" name="correct_option" value="<?= $index ?>" <?= $option['correct'] ? 'checked' : '' ?> required><input name="options[]" value="<?= e($option['text']) ?>" maxlength="2000" required></label><?php endforeach; ?>
                  <?php if ($question['question_type'] === 'MCQ'): ?>
                    <?php for ($index = count($options); $index < 4; $index++): ?><label class="question-option"><input type="radio" name="correct_option" value="<?= $index ?>"><input name="options[]" maxlength="2000" placeholder="خيار إضافي"></label><?php endfor; ?>
                  <?php endif; ?>
                </fieldset>
              <?php endif; ?>
              <label>التفسير<textarea name="explanation" rows="2" maxlength="10000"><?= e($question['explanation'] ?? '') ?></textarea></label>
              <label>بعد الحفظ<select name="status"><option value="PENDING_REVIEW">إرسال للمراجعة</option><option value="DRAFT" <?= $question['status'] === 'DRAFT' ? 'selected' : '' ?>>حفظ كمسودة</option></select></label>
              <button class="button button-small" type="submit">حفظ التعديل</button>
            </form>
          </details>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</section>
