<section class="auth-wrap">
  <div class="auth-card login-card">
    <div class="auth-header">
      <span class="eyebrow">استعادة الوصول</span>
      <h1>تعيين كلمة مرور جديدة</h1>
      <?php if (!empty($data['reset_token_valid'])): ?>
        <p class="auth-intro">اختر كلمة مرور جديدة من 8 إلى 12 محرفًا إنجليزيًا. الرابط لمرة واحدة وصالح لمدة محدودة.</p>
      <?php else: ?>
        <p class="auth-intro">هذا الرابط غير صالح أو انتهت صلاحيته. اطلب رابط استعادة جديدًا.</p>
      <?php endif; ?>
    </div>
    <?php if (!empty($data['reset_token_valid'])): ?>
      <form method="post" action="<?= e(url('reset_password')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="token" value="<?= e($data['token'] ?? '') ?>">
        <div class="form-field">
          <label for="password">كلمة المرور الجديدة</label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="12" pattern="[!-~]{8,12}" title="استخدم من 8 إلى 12 حرفًا إنجليزيًا أو رقمًا أو رمزًا." lang="en" dir="ltr" autocapitalize="off" spellcheck="false" required>
        </div>
        <div class="form-field">
          <label for="password_confirmation">تأكيد كلمة المرور</label>
          <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="12" pattern="[!-~]{8,12}" title="استخدم من 8 إلى 12 حرفًا إنجليزيًا أو رقمًا أو رمزًا." lang="en" dir="ltr" autocapitalize="off" spellcheck="false" required>
        </div>
        <button class="button button-primary button-large button-full" type="submit">حفظ كلمة المرور</button>
      </form>
    <?php else: ?>
      <a class="button button-primary button-full" href="<?= e(url('forgot_password')) ?>">طلب رابط جديد</a>
    <?php endif; ?>
    <div class="auth-foot">
      <a href="<?= e(url('login')) ?>" class="auth-link-highlight">العودة إلى تسجيل الدخول</a>
    </div>
  </div>
</section>
