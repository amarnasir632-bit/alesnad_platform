<section id="hero" class="hero container">
  <div class="hero-grid">
    <div>
      <span class="eyebrow">منصة الإسناد الأكاديمي · السودان</span>
      <h1>تعلّم بسهولة،<br>وتقدّم بثقة.</h1>
      <p>مساحة تعليمية تجمع المواد الدراسية والموارد لمتابعة رحلتك التعليمية.</p>
      <div class="hero-actions">
        <a class="btn btn-primary" href="<?= e(url('subjects')) ?>">تصفح المواد الدراسية</a>
        <?php if (!current_user()): ?><a class="btn btn-secondary" href="<?= e(url('register')) ?>">إنشاء حساب طالب</a><?php endif; ?>
      </div>
    </div>
    <div class="visual-box" aria-label="الإسناد الأكاديمي">
      <div class="visual-circle"><img src="/assets/brand/al-warith-logo.jpg" alt="شعار الإسناد الأكاديمي" class="logo-icon"></div>
    </div>
  </div>
</section>

<section id="library" class="section container">
  <div class="section-head"><h2>المواد الدراسية</h2><a href="<?= e(url('subjects')) ?>" class="link-more">عرض كل المواد</a></div>
  <?php if ($data['subjects']): ?>
    <div class="course-grid">
      <?php foreach ($data['subjects'] as $subject): ?>
        <article class="course-card">
          <div class="course-thumb" aria-hidden="true">📚</div>
          <div class="course-body">
            <span class="course-tag"><?= e($subject['stage_name'] . ' · ' . $subject['class_name']) ?></span>
            <h3><?= e($subject['subject_name']) ?></h3>
            <p><?= (int) $subject['lesson_count'] ?> درس منشور</p>
            <a class="btn btn-primary" href="<?= e(url('subject', ['id' => $subject['class_subject_id']])) ?>">عرض المادة</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state"><h3>لا توجد مواد منشورة حاليًا</h3><p>ستظهر المواد هنا بعد إضافتها إلى المنصة.</p></div>
  <?php endif; ?>
</section>

<section id="questions" class="section container">
  <div class="section-head"><h2>بنك الأسئلة</h2></div>
  <div class="empty-state"><h3>خدمة الاختبارات قيد التجهيز</h3><p>لم تُربط واجهة بنك الأسئلة بخدمات المنصة بعد.</p></div>
</section>
<section id="exams" class="section container">
  <div class="section-head"><h2>الامتحانات السابقة</h2></div>
  <div class="empty-state"><h3>مكتبة الامتحانات قيد التجهيز</h3><p>ستظهر الملفات المنشورة هنا عند اكتمال ربط المحتوى.</p></div>
</section>
<section id="sessions" class="section container">
  <div class="section-head"><h2>الحصص المباشرة والتسجيلات</h2></div>
  <div class="empty-state"><h3>خدمة الحصص قيد التجهيز</h3><p>لا توجد حصص أو تسجيلات متاحة للعرض حاليًا.</p></div>
</section>
