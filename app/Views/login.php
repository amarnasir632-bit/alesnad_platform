<section class="auth-wrap">
  <div class="auth-card login-card">
    <div class="auth-header">
      <span class="eyebrow">الإسناد الأكاديمي</span>
      <h1>تسجيل الدخول</h1>
      <p class="auth-intro">أدخل بريدك الإلكتروني وكلمة المرور للمتابعة.</p>
    </div>

    <form id="login-form" method="post" action="<?= e(url('login')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="login">

      <div class="form-field">
        <label for="email">البريد الإلكتروني</label>
        <input id="email" name="email" type="email" dir="ltr" inputmode="email" autocomplete="email" maxlength="254" value="<?= e($old['email'] ?? '') ?>" placeholder="name@example.com" required>
      </div>

      <div class="form-field">
        <label for="password">كلمة المرور</label>
        <div class="password-control">
          <input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required>
          <button class="password-toggle" type="button" aria-controls="password" aria-label="إظهار كلمة المرور" aria-pressed="false">إظهار</button>
        </div>
      </div>

      <button class="button button-primary button-large button-full" type="submit">
        <span data-submit-label>دخول إلى حسابي</span>
      </button>
      <span class="visually-hidden" data-login-loading role="status" aria-live="polite"></span>
    </form>

    <div class="auth-foot">
      <p>إنشاء الحساب متاح للطلاب وفق سياسة التسجيل المعتمدة.</p>
      <a href="<?= e(url('register')) ?>" class="auth-link-highlight">إنشاء حساب طالب جديد</a>
    </div>
  </div>
</section>
