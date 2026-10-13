<section class="section page-section">
  <span class="eyebrow">المكتبة التعليمية</span><h1 class="page-title">المواد الدراسية</h1>
  <p class="page-intro">اختر المرحلة والصف ثم افتح المادة لمشاهدة المحتوى المنشور.</p>
  <?php if ($data['subjects']): ?>
    <div class="course-grid">
      <?php foreach ($data['subjects'] as $subject): ?>
        <article class="course-card"><span class="course-symbol">✦</span><p class="course-meta"><?= e($subject['stage_name'] . ' · ' . $subject['class_name']) ?></p><h2><?= e($subject['subject_name']) ?></h2><p><?= (int) $subject['lesson_count'] ?> درس منشور</p><a class="card-link" href="<?= e(url('subject', ['id' => $subject['class_subject_id']])) ?>">عرض المادة <span>←</span></a></article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state"><span>✳</span><h3>لا توجد مواد منشورة حاليًا</h3><p>ستظهر المواد بعد إدخالها واعتمادها من الإدارة.</p></div>
  <?php endif; ?>
</section>
