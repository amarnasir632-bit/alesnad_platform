<section class="auth-wrap">
  <div class="auth-card login-card">
    <div class="auth-header">
      <span class="eyebrow">استكمال التسجيل</span>
      <h1>تحديث بيانات طلبك</h1>
      <?php if (!empty($data['admission_token_valid'])): ?>
        <p class="auth-intro">عدّل البيانات المطلوبة. سيعود الطلب بعد الإرسال إلى قائمة المراجعة، ولن يُفعّل الحساب قبل اعتماده.</p>
      <?php else: ?>
        <p class="auth-intro">رابط الاستكمال غير صالح أو انتهت صلاحيته. تواصل مع مسؤول التسجيل والقبول لطلب رابط جديد.</p>
      <?php endif; ?>
    </div>
    <?php if (!empty($data['admission_token_valid'])): ?>
      <form method="post" action="<?= e(url('complete_admission')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="complete_admission">
        <input type="hidden" name="token" value="<?= e($data['token'] ?? '') ?>">
        <div class="form-field">
          <label for="full_name">الاسم الكامل</label>
          <input id="full_name" name="full_name" autocomplete="name" maxlength="150" required>
        </div>
        <div class="form-field">
          <label for="phone">رقم الهاتف</label>
          <input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="32">
        </div>
        <div class="form-field">
          <label for="class_id">المرحلة والصف الدراسي</label>
          <select id="class_id" name="class_id" required>
            <option value="">اختر الصف الدراسي</option>
            <?php foreach ($data['classes'] as $class): ?>
              <option value="<?= (int) $class['id'] ?>"><?= e($class['stage_name'] . ' · ' . $class['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="button button-primary button-large button-full" type="submit">إرسال البيانات للمراجعة</button>
      </form>
    <?php endif; ?>
  </div>
</section>
