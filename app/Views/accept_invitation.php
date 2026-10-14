<section class="auth-wrap"><div class="auth-card">
  <span class="eyebrow">تفعيل حساب الفريق</span>
  <h1>مرحبًا <?= e($data['invitation']['full_name']) ?></h1>
  <p><?= e($data['invitation']['job_title']) ?> · <?= e($data['invitation']['email']) ?></p>
  <p>اختر كلمة مرور جديدة لا تقل عن 12 حرفًا. بعد الحفظ سجّل الدخول بالبريد وكلمة المرور.</p>
  <form method="post" action="<?= e(url('accept_invitation')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="accept_staff_invitation">
    <div class="form-field"><label for="password">كلمة المرور</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required></div>
    <div class="form-field"><label for="password_confirmation">تأكيد كلمة المرور</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required></div>
    <button class="button button-full" type="submit">تفعيل الحساب</button>
  </form>
</div></section>
