<section class="auth-wrap">
  <div class="auth-card login-card">
    <div class="auth-header">
      <span class="eyebrow">استعادة الوصول</span>
      <h1>نسيت كلمة المرور؟</h1>
      <p class="auth-intro">أدخل بريد حسابك، وسنرسل رابطًا آمنًا لإعادة تعيين كلمة المرور إذا كان الحساب مسجلًا.</p>
    </div>
    <form method="post" action="<?= e(url('forgot_password')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="request_password_reset">
      <div class="form-field">
        <label for="email">البريد الإلكتروني</label>
        <input id="email" name="email" type="email" dir="ltr" inputmode="email" autocomplete="email" maxlength="254" required>
      </div>
      <button class="button button-primary button-large button-full" type="submit">إرسال رابط الاستعادة</button>
    </form>
    <div class="auth-foot">
      <a href="<?= e(url('login')) ?>" class="auth-link-highlight">العودة إلى تسجيل الدخول</a>
    </div>
  </div>
</section>
