<section class="auth-wrap">
  <div class="auth-card">
    <div class="auth-header">
      <span class="eyebrow">تفعيل حساب فريق العمل</span>
      <h1>مرحبًا بك، <?= e($data['invitation']['full_name']) ?></h1>
      <p class="auth-intro">
        تمت دعوتك للانضمام إلى منصة الإسناد الأكاديمي بصفتك:
        <strong class="highlight-role"><?= e($data['invitation']['job_title']) ?></strong>
      </p>
      <small class="account-email-badge" dir="ltr"><?= e($data['invitation']['email']) ?></small>
    </div>

    <div class="notice">
      <span class="notice-icon" aria-hidden="true">🔑</span>
      <p class="notice-text">
        يرجى تعيين كلمة مرور جديدة قوية (لا تقل عن 12 خانة) لتفعيل حسابك والبدء في استخدام مهامك الإدارية أو التعليمية.
      </p>
    </div>

    <form method="post" action="<?= e(url('accept_invitation')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="accept_staff_invitation">

      <div class="form-field">
        <label for="password">كلمة المرور الجديدة</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="1024" placeholder="••••••••••••" required>
        <small class="field-hint">يجب أن تتكون من 12 خانة على الأقل (أحرف وأرقام).</small>
      </div>

      <div class="form-field">
        <label for="password_confirmation">تأكيد كلمة المرور</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="1024" placeholder="أعد إدخال كلمة المرور نفسها" required>
        <small class="field-hint">تأكد من مطابقة كلمة المرور المدخلة أعلاه.</small>
      </div>

      <button class="button button-primary button-large button-full" type="submit">
        <span>✓ تفعيل الحساب وحفظ كلمة المرور</span>
      </button>
    </form>

    <div class="auth-foot">
      <span>لديك حساب مفعل مسبقاً؟</span>
      <a href="<?= e(url('login')) ?>" class="auth-link-highlight">تسجيل الدخول مباشرة</a>
    </div>
  </div>
</section>
