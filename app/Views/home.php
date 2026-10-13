<section class="hero">
  <div class="hero-copy">
    <span class="eyebrow">منصة الإسناد الأكاديمي · السودان</span>
    <h1>تعلّم أقرب،<br><em>ومستقبل أوضح.</em></h1>
    <p>مساحة تعليمية تجمع المواد والموارد والدروس، وتساعد الطالب على متابعة رحلته الدراسية.</p>
    <div class="hero-actions">
      <a class="button" href="<?= e(url('subjects')) ?>">تصفح المواد <span aria-hidden="true">←</span></a>
      <?php if (!current_user()): ?><a class="text-link" href="<?= e(url('register')) ?>">أنشئ حساب طالب</a><?php endif; ?>
    </div>
  </div>
  <div class="hero-card" aria-label="المنصة التعليمية">
    <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
    <div class="learning-card"><span class="card-icon">✳</span><span class="card-label">رحلتك التعليمية</span><strong>كل خطوة<br>تقرّبك لهدفك</strong><span class="card-progress"><i></i></span><small>تعلّم على راحتك</small></div>
    <span class="float-note note-top">تعلم على راحتك</span><span class="float-note note-bottom">محتوى للمنهج السوداني</span>
  </div>
</section>
<section class="section courses-section">
  <div class="section-heading"><div><span class="eyebrow">ابدأ من مادتك</span><h2>المواد المتاحة</h2></div><a class="text-link" href="<?= e(url('subjects')) ?>">كل المواد ←</a></div>
  <?php if ($data['subjects']): ?>
    <div class="course-grid">
      <?php foreach ($data['subjects'] as $subject): ?>
        <article class="course-card"><span class="course-symbol">✦</span><p class="course-meta"><?= e($subject['stage_name'] . ' · ' . $subject['class_name']) ?></p><h3><?= e($subject['subject_name']) ?></h3><p><?= (int) $subject['lesson_count'] ?> درس منشور</p><a class="card-link" href="<?= e(url('subject', ['id' => $subject['class_subject_id']])) ?>">عرض المادة <span aria-hidden="true">←</span></a></article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state"><span>✳</span><h3>سيظهر المحتوى هنا بعد إضافته</h3><p>لم تُضف المواد الدراسية إلى قاعدة البيانات بعد.</p></div>
  <?php endif; ?>
</section>
