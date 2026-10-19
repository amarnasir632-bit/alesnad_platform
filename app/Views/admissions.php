<?php
$filters = $data['filters'];
$statusLabels = [
  'PENDING' => 'قيد المراجعة',
  'NEEDS_INFO' => 'يحتاج استكمالًا',
  'APPROVED' => 'مقبول',
  'REJECTED' => 'مرفوض',
  'SUSPENDED' => 'موقوف إداريًا',
];
?>
<section class="section page-section admissions-page" aria-labelledby="admissions-title">
  <div class="section-head">
    <div><span class="eyebrow">إدارة شؤون الطلاب</span><h1 id="admissions-title">التسجيل والقبول</h1></div>
    <a class="button button-secondary" href="<?= e('/api/v1/admin/admissions/export?' . http_build_query($filters)) ?>">تصدير النتائج CSV</a>
  </div>
  <p class="page-intro">راجع طلبات التسجيل، صحّح الصف وسنة القبول والفصل الدراسي، وتابع أسباب القرارات وسجل التغييرات.</p>

  <form class="admission-filter-form" method="get" action="/">
    <input type="hidden" name="page" value="admissions">
    <label class="form-field"><span>بحث بالاسم أو البريد أو رقم الطالب أو الهاتف</span><input name="q" value="<?= e($filters['q']) ?>" maxlength="150"></label>
    <label class="form-field"><span>حالة التسجيل</span><select name="status"><option value="">كل الحالات</option><?php foreach ($statusLabels as $code => $label): ?><option value="<?= e($code) ?>" <?= $filters['status'] === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="form-field"><span>الصف</span><select name="class_id"><option value="">كل الصفوف</option><?php foreach ($data['classes'] as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (string) $filters['class_id'] === (string) $class['id'] ? 'selected' : '' ?>><?= e($class['stage_name'] . ' · ' . $class['name']) ?></option><?php endforeach; ?></select></label>
    <label class="form-field"><span>من تاريخ التقديم</span><input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="form-field"><span>إلى تاريخ التقديم</span><input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <button class="button button-primary" type="submit">تصفية الطلبات</button>
  </form>

  <?php if (!empty($data['admissionsPageError'])): ?><div class="message error" role="alert"><?= e($data['admissionsPageError']) ?></div><?php endif; ?>

  <div class="admission-results-heading">
    <h2>طلبات التسجيل (<?= count($data['admissions']) ?>)</h2>
    <span class="muted">تُعرض حتى 100 نتيجة في الصفحة.</span>
  </div>
  <?php if (empty($data['admissions'])): ?>
    <div class="empty-state"><h3>لا توجد طلبات مطابقة</h3><p>جرّب تغيير حالة التسجيل أو معايير البحث.</p></div>
  <?php else: ?>
    <div class="admission-list">
      <?php foreach ($data['admissions'] as $admission): ?>
        <article class="admission-card">
          <div class="admission-card-heading">
            <div><h3><?= e($admission['full_name']) ?></h3><p><?= e($admission['email']) ?><?= $admission['phone'] ? ' · ' . e($admission['phone']) : '' ?></p></div>
            <span class="status-chip <?= $admission['registration_status'] === 'APPROVED' ? 'chip-live' : 'chip-scheduled' ?>"><?= e($statusLabels[$admission['registration_status']] ?? $admission['registration_status']) ?></span>
          </div>
          <p class="admission-meta"><bdi dir="ltr"><?= e($admission['student_number'] ?? '—') ?></bdi> · <?= e($admission['stage_name'] ?? 'مرحلة غير محددة') ?> · <?= e($admission['class_name'] ?? 'صف غير محدد') ?> · تاريخ الطلب <?= e(date('Y-m-d H:i', strtotime($admission['applied_at']))) ?></p>
          <form method="post" action="<?= e(url('admissions')) ?>" class="admission-review-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="review_admission">
            <input type="hidden" name="student_id" value="<?= (int) $admission['student_id'] ?>">
            <label class="form-field"><span>الصف الدراسي</span><select name="class_id" required><option value="">اختر الصف</option><?php foreach ($data['classes'] as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) $admission['class_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= e($class['stage_name'] . ' · ' . $class['name']) ?></option><?php endforeach; ?></select></label>
            <label class="form-field"><span>سنة القبول</span><input type="number" name="admission_year" min="1900" max="<?= (int) date('Y') ?>" value="<?= e(isset($admission['admission_year']) ? (string) $admission['admission_year'] : '') ?>"></label>
            <label class="form-field"><span>الفصل الدراسي</span><select name="academic_semester"><option value="">غير محدد</option><option value="FIRST" <?= $admission['academic_semester'] === 'FIRST' ? 'selected' : '' ?>>الأول</option><option value="SECOND" <?= $admission['academic_semester'] === 'SECOND' ? 'selected' : '' ?>>الثاني</option></select></label>
            <label class="form-field"><span>قرار التسجيل</span><select name="status" required><?php foreach ($statusLabels as $code => $label): ?><option value="<?= e($code) ?>" <?= $admission['registration_status'] === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label class="form-field admission-reason-field"><span>سبب الرفض أو البيانات المطلوب استكمالها</span><textarea name="reason" rows="2" maxlength="1000" placeholder="مطلوب عند طلب الاستكمال أو الرفض"></textarea></label>
            <button class="button button-primary" type="submit">حفظ القرار والبيانات</button>
          </form>
          <details class="admission-history" data-student-id="<?= (int) $admission['student_id'] ?>">
            <summary>سجل قرارات التسجيل وطلبات تصحيح البيانات</summary>
            <button class="button button-secondary button-small" type="button" data-load-admission-history>عرض السجل</button>
            <div class="admission-history-content" aria-live="polite"></div>
          </details>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="correction-queue" id="correction-queue" aria-labelledby="correction-queue-title">
    <h2 id="correction-queue-title">طلبات تصحيح البيانات (<?= count($data['corrections']) ?>)</h2>
    <?php if (empty($data['corrections'])): ?>
      <p class="muted">لا توجد طلبات تصحيح تنتظر المراجعة.</p>
    <?php else: ?>
      <?php foreach ($data['corrections'] as $request): ?>
        <?php
          $changes = is_array($request['requested_changes']) ? $request['requested_changes'] : [];
          $changeLabels = ['full_name' => 'الاسم', 'phone' => 'الهاتف', 'parent_phone' => 'هاتف ولي الأمر', 'class_id' => 'معرّف الصف'];
        ?>
        <article class="admission-card correction-card">
          <h3><?= e($request['full_name']) ?> · <?= e($request['student_number'] ?? '—') ?></h3>
          <p><?= e($request['email']) ?> · <?= e($request['class_name'] ?? 'صف غير محدد') ?></p>
          <p><strong>السبب:</strong> <?= e($request['reason']) ?></p>
          <ul><?php foreach ($changes as $key => $value): ?><li><strong><?= e($changeLabels[$key] ?? $key) ?>:</strong> <?= e((string) ($value ?? 'فارغ')) ?></li><?php endforeach; ?></ul>
          <form method="post" action="<?= e(url('admissions')) ?>" class="admission-review-form correction-review-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="review_profile_correction">
            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
            <label class="form-field"><span>قرار التصحيح</span><select name="status"><option value="APPROVED">اعتماد التعديلات</option><option value="REJECTED">رفض الطلب</option></select></label>
            <label class="form-field"><span>ملاحظة أو سبب القرار</span><input name="resolution_reason" maxlength="1000"></label>
            <button class="button button-primary" type="submit">حفظ القرار</button>
          </form>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</section>
