<section class="auth-wrap">
  <div class="auth-card">
    <div class="auth-header">
      <span class="eyebrow">مرحباً بعودتك</span>
      <h1>تسجيل الدخول إلى حسابك</h1>
      <p class="auth-intro">أدخل بريدك الإلكتروني وكلمة المرور للمتابعة.</p>
    </div>

    <form method="post" action="<?= e(url('login')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="login">

      <div class="form-field">
        <label for="email">البريد الإلكتروني</label>
        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= e($old['email'] ?? '') ?>" placeholder="name@example.com" required>
      </div>

      <div class="form-field">
        <label for="password">كلمة المرور</label>
        <input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" placeholder="••••••••••••" required>
      </div>

      <button class="button button-primary button-large button-full" type="submit">
        <span>تسجيل الدخول ←</span>
      </button>
    </form>

    <div class="auth-foot">
      <span>ليس لديك حساب بعد؟</span>
      <a href="<?= e(url('register')) ?>" class="auth-link-highlight">إنشاء حساب طالب جديد</a>
    </div>
  </div>
</section>
