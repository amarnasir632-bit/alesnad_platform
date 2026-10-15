<section class="section page-section">
  <span class="eyebrow">المكتبة والمنهج الدراسي</span>
  <h1 class="page-title">المواد الدراسية المقررة</h1>
  <p class="page-intro">اختر المادة الدراسية لاستعراض قائمة الدروس المعتمدة، الشروحات، والموارد التعليمية المرفقة.</p>

  <?php if (!empty($data['subjects'])): ?>
    <div class="course-grid">
      <?php foreach ($data['subjects'] as $subject): ?>
        <?php $sc = get_subject_cover_data($subject['subject_name']); ?>
        <article class="course-card">
          <div class="subject-card-cover" style="background: <?= e($sc['gradient']) ?>;">
            <span class="cover-symbol-watermark" aria-hidden="true"><?= e($sc['symbol']) ?></span>
            <span class="cover-icon-badge" aria-hidden="true"><?= e($sc['icon']) ?></span>
          </div>
          <div class="course-body">
            <span class="course-tag"><?= e($subject['stage_name'] . ' · ' . $subject['class_name']) ?></span>
            <h2><?= e($subject['subject_name']) ?></h2>
            <p class="course-lesson-count">
              <span class="lesson-icon" aria-hidden="true">📝</span>
              <span><?= (int) $subject['lesson_count'] ?> درس معتمد ومتاح</span>
            </p>
            <a class="button button-primary button-full" href="<?= e(url('subject', ['id' => $subject['class_subject_id']])) ?>">
              <span>فتح المادة واستعراض الدروس ←</span>
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon" aria-hidden="true">📚</div>
      <h3>لا توجد مواد منشورة حاليًا</h3>
      <p>ستظهر المواد والدروس هنا بعد تسجيلها واعتمادها من إدارة المنصة.</p>
    </div>
  <?php endif; ?>
</section>
