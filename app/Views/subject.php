<section class="section page-section">
  <a class="text-link" href="<?= e(url('subjects')) ?>">← العودة إلى المواد</a>
  <div class="detail-card">
    <span class="eyebrow"><?= e($data['subject']['stage_name'] . ' · ' . $data['subject']['class_name']) ?></span>
    <h1 class="page-title"><?= e($data['subject']['subject_name']) ?></h1>
    <?php if ($data['subject']['description']): ?><p class="page-intro"><?= nl2br(e($data['subject']['description'])) ?></p><?php endif; ?>
    <div class="notice">المحتوى المدفوع متاح للمشتركين. بوابة الدفع وإدارة الاشتراكات قيد الإعداد، لذلك لم تُفعّل عملية الشراء بعد.</div>
  </div>
</section>
