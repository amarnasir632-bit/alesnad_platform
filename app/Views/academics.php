<section class="section page-section">
  <span class="eyebrow">إدارة المنصة</span>
  <h1 class="page-title">الهيكل الدراسي</h1>
  <p class="page-intro">أضف المراحل والصفوف واربط المواد بكل صف. التغييرات محفوظة في قاعدة البيانات ويسجلها سجل التدقيق.</p>
  <div class="admin-grid">
    <form class="detail-card admin-form" method="post" action="<?= e(url('academics')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="academics_add_stage">
      <h2>إضافة مرحلة</h2>
      <div class="form-field"><label for="stage_name">اسم المرحلة</label><input id="stage_name" name="name" maxlength="100" required></div>
      <div class="form-field"><label for="stage_code">رمز داخلي (إنجليزي)</label><input id="stage_code" name="code" pattern="[A-Za-z0-9_]{2,50}" maxlength="50" required></div>
      <div class="form-field"><label for="stage_order">ترتيب العرض</label><input id="stage_order" name="sort_order" type="number" min="0" max="32767" value="0" required></div>
      <button class="button" type="submit">حفظ المرحلة</button>
    </form>

    <form class="detail-card admin-form" method="post" action="<?= e(url('academics')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="academics_add_class">
      <h2>إضافة صف</h2>
      <div class="form-field"><label for="class_stage">المرحلة</label><select id="class_stage" name="stage_id" required><option value="">اختر مرحلة</option><?php foreach ($data['stages'] as $stage): if (!(bool) $stage['is_active']) continue; ?><option value="<?= (int) $stage['id'] ?>"><?= e($stage['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-field"><label for="class_name">اسم الصف</label><input id="class_name" name="name" maxlength="100" required></div>
      <div class="form-field"><label for="class_code">رمز داخلي (إنجليزي)</label><input id="class_code" name="code" pattern="[A-Za-z0-9_]{2,50}" maxlength="50" required></div>
      <div class="form-field"><label for="class_order">ترتيب العرض</label><input id="class_order" name="sort_order" type="number" min="0" max="32767" value="0" required></div>
      <button class="button" type="submit">حفظ الصف</button>
    </form>

    <form class="detail-card admin-form" method="post" action="<?= e(url('academics')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="academics_add_subject">
      <h2>إضافة مادة لصف</h2>
      <div class="form-field"><label for="subject_class">الصف</label><select id="subject_class" name="class_id" required><option value="">اختر صفًا</option><?php foreach ($data['stages'] as $stage): foreach ($stage['classes'] as $class): if (!(bool) $class['is_active']) continue; ?><option value="<?= (int) $class['id'] ?>"><?= e($stage['name'] . ' · ' . $class['name']) ?></option><?php endforeach; endforeach; ?></select></div>
      <div class="form-field"><label for="subject_name">اسم المادة</label><input id="subject_name" name="name" maxlength="120" required></div>
      <div class="form-field"><label for="subject_code">رمز المادة (اختياري)</label><input id="subject_code" name="code" pattern="[A-Za-z0-9_]{2,50}" maxlength="50"></div>
      <button class="button" type="submit">حفظ المادة</button>
    </form>
  </div>
</section>

<section class="section page-section structure-list">
  <h2>المراحل والصفوف والمواد المسجلة</h2>
  <?php if (!$data['stages']): ?><div class="empty-state"><p>لم تُضف مراحل بعد.</p></div><?php endif; ?>
  <?php foreach ($data['stages'] as $stage): ?>
    <article class="detail-card structure-stage">
      <h3><?= e($stage['name']) ?> <small><?= e($stage['code']) ?></small></h3>
      <?php if (!(bool) $stage['is_active']): ?><p class="muted">غير نشطة</p><?php endif; ?>
      <?php if (!$stage['classes']): ?><p class="muted">لا توجد صفوف مسجلة.</p><?php endif; ?>
      <?php foreach ($stage['classes'] as $class): ?>
        <div class="structure-class">
          <strong><?= e($class['name']) ?></strong> <span class="muted"><?= e($class['code']) ?></span>
          <?php if (!(bool) $class['is_active']): ?><span class="muted"> · غير نشط</span><?php endif; ?>
          <?php if (!$class['subjects']): ?><p class="muted">لا توجد مواد مرتبطة بهذا الصف.</p><?php else: ?>
            <ul><?php foreach ($class['subjects'] as $subject): ?><li><?= e($subject['subject_name']) ?><?php if ($subject['subject_code']): ?> <small><?= e($subject['subject_code']) ?></small><?php endif; ?><?php if (!(bool) $subject['is_active'] || !(bool) $subject['subject_active']): ?> <span class="muted">(غير نشطة)</span><?php endif; ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </article>
  <?php endforeach; ?>
</section>
