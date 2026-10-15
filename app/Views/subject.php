<section class="section page-section">
  <div class="page-nav-back">
    <a class="button button-small button-secondary" href="<?= e(url('subjects')) ?>">
      <span>→ العودة إلى قائمة المواد</span>
    </a>
  </div>

  <article class="detail-card subject-overview-card">
    <div class="subject-header">
      <span class="eyebrow"><?= e($data['subject']['stage_name'] . ' · ' . $data['subject']['class_name']) ?></span>
      <h1 class="page-title"><?= e($data['subject']['subject_name']) ?></h1>
      <?php if (!empty($data['subject']['description'])): ?>
        <p class="page-intro"><?= nl2br(e($data['subject']['description'])) ?></p>
      <?php endif; ?>
    </div>

    <?php if (!$data['can_view_content']): ?>
      <?php if (!current_user()): ?>
        <div class="notice">
          <span class="notice-icon" aria-hidden="true">🔒</span>
          <div>
            <strong>يتطلب مشاهدة الدروس تسجيل الدخول</strong>
            <p>سجّل الدخول بحسابك المدرسي، ثم تمنحك إدارة المنصة صلاحية الوصول للمحتوى يدويًا.</p>
            <div class="notice-actions" style="margin-top: 10px;">
              <a class="button button-small button-primary" href="<?= e(url('login')) ?>">تسجيل الدخول</a>
              <a class="button button-small button-secondary" href="<?= e(url('register')) ?>">إنشاء حساب طالب</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="notice">
          <span class="notice-icon" aria-hidden="true">⏳</span>
          <div>
            <strong>بانتظار تفعيل صلاحية المحتوى لحسابك</strong>
            <p>حسابك مسجل بنجاح؛ يرجى مراجعة إدارة المنصة لمنحك إذن تصفح الدروس والمكتبة.</p>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="notice notice-success">
        <span class="notice-icon" aria-hidden="true">✓</span>
        <div>
          <strong>أهلية الوصول مفعلة ومتاحة لحسابك</strong>
          <p>يمكنك استعراض الدروس المقررة وتحميل الموارد التعليمية التابعة لهذه المادة.</p>
        </div>
      </div>

      <div class="lessons-container">
        <h2 class="lesson-heading">قائمة الدروس المقررة</h2>
        <?php if (!empty($data['lessons'])): ?>
          <ol class="lesson-list">
            <?php foreach ($data['lessons'] as $lesson): ?>
              <li class="lesson-item-card">
                <div class="lesson-number-circle"><?= (int) $lesson['position'] ?></div>
                <div class="lesson-body-content">
                  <strong class="lesson-title"><?= e($lesson['title']) ?></strong>
                  <?php if (!empty($lesson['description'])): ?>
                    <p class="lesson-description"><?= nl2br(e($lesson['description'])) ?></p>
                  <?php endif; ?>
                  <?php if (!empty($lesson['body'])): ?>
                    <div class="lesson-content-text"><?= nl2br(e($lesson['body'])) ?></div>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php else: ?>
          <div class="empty-state">
            <p class="muted">لا توجد دروس منشورة لهذه المادة حتى الآن. ستظهر فور اعتمادها من الأستاذ المشرف.</p>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </article>
</section>
