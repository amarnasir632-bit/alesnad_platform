<section class="auth-wrap"><div class="auth-card">
  <span class="eyebrow">ابدأ رحلتك</span><h1>إنشاء حساب طالب</h1><p>أنشئ حسابًا للوصول إلى خدمات المنصة.</p>
  <form method="post" action="<?= e(url('register')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="register">
    <div class="form-field"><label for="full_name">الاسم الكامل</label><input id="full_name" name="full_name" autocomplete="name" maxlength="150" value="<?= e($old['full_name'] ?? '') ?>" required></div>
    <div class="form-field"><label for="email">البريد الإلكتروني</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= e($old['email'] ?? '') ?>" required></div>
    <div class="form-field"><label for="phone">رقم الهاتف (اختياري)</label><input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="32" value="<?= e($old['phone'] ?? '') ?>"></div>
    <div class="form-field"><label for="password">كلمة المرور</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required><small>12 حرفًا على الأقل.</small></div>
    <div class="form-field"><label for="password_confirmation">تأكيد كلمة المرور</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required></div>
    <button class="button button-full" type="submit">إنشاء الحساب</button>
  </form>
  <p class="auth-foot">لديك حساب؟ <a href="<?= e(url('login')) ?>">سجّل الدخول</a></p>
</div></section>
