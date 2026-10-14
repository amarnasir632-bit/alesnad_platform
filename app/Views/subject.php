<section class="section page-section">
  <a class="text-link" href="<?= e(url('subjects')) ?>">← العودة إلى المواد</a>
  <div class="detail-card">
    <span class="eyebrow"><?= e($data['subject']['stage_name'] . ' · ' . $data['subject']['class_name']) ?></span>
    <h1 class="page-title"><?= e($data['subject']['subject_name']) ?></h1>
    <?php if ($data['subject']['description']): ?><p class="page-intro"><?= nl2br(e($data['subject']['description'])) ?></p><?php endif; ?>
    <?php if (!$data['can_view_content']): ?>
      <?php if (!current_user()): ?><div class="notice">سجّل الدخول، ثم يراجع المدير أو مراجع المحتوى طلب إتاحة المحتوى يدويًا.</div>
      <?php else: ?><div class="notice">ليس لديك وصول للمحتوى حاليًا. يحدد المدير أو مراجع المحتوى أهلية الوصول يدويًا.</div><?php endif; ?>
    <?php else: ?>
      <div class="notice">أهلية الوصول مفعلة لهذا الحساب.</div>
      <h2 class="lesson-heading">الدروس المنشورة</h2>
      <?php if ($data['lessons']): ?>
        <ol class="lesson-list">
          <?php foreach ($data['lessons'] as $lesson): ?>
            <li><span><?= (int) $lesson['position'] ?></span><div><strong><?= e($lesson['title']) ?></strong>
              <?php if ($lesson['description']): ?><p><?= nl2br(e($lesson['description'])) ?></p><?php endif; ?>
              <?php if ($lesson['body']): ?><div class="lesson-content"><?= nl2br(e($lesson['body'])) ?></div><?php endif; ?>
            </div></li>
          <?php endforeach; ?>
        </ol>
      <?php else: ?><p class="muted">لا توجد دروس منشورة لهذه المادة حتى الآن.</p><?php endif; ?>
    <?php endif; ?>
  </div>
</section>
