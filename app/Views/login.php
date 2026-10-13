<section class="auth-wrap"><div class="auth-card">
  <span class="eyebrow">مرحباً بعودتك</span><h1>تسجيل الدخول</h1><p>واصل رحلتك التعليمية.</p>
  <form method="post" action="<?= e(url('login')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login">
    <div class="form-field"><label for="email">البريد الإلكتروني</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= e($old['email'] ?? '') ?>" required></div>
    <div class="form-field"><label for="password">كلمة المرور</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
    <button class="button button-full" type="submit">دخول</button>
  </form>
  <p class="auth-foot">مستخدم جديد؟ <a href="<?= e(url('register')) ?>">أنشئ حسابًا</a></p>
</div></section>
