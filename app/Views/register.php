<section class="auth-wrap">
  <div class="auth-card auth-card-wide">
    <div class="auth-header">
      <span class="eyebrow">ابدأ رحلتك التعليمية</span>
      <h1>إنشاء حساب طالب جديد</h1>
      <p class="auth-intro">أدخل بياناتك خطوة بخطوة لإرسال طلب تسجيل يراجعه مسؤول التسجيل والقبول.</p>
    </div>

    <?php if (empty($data['classes'])): ?>
      <div class="notice">
        <span class="notice-icon" aria-hidden="true">ℹ</span>
        <div>
          <strong>التسجيل غير متاح حاليًا</strong>
          <p>ستقوم إدارة المنصة بإضافة المراحل والصفوف الدراسية قريبًا.</p>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('register')) ?>" class="multi-step-form" id="student-register-form">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="register">

      <!-- الخطوة 1: البيانات الشخصية -->
      <fieldset class="form-step" data-step="1" data-step-title="البيانات الشخصية">
        <legend class="step-legend">الخطوة الأولى: البيانات الأساسية للدارس</legend>
        <p class="step-desc">يرجى كتابة الاسم ورقم الهاتف للتواصل الرسمي.</p>

        <div class="form-field">
          <label for="full_name">الاسم الرباعي للدارس</label>
          <input id="full_name" name="full_name" autocomplete="name" maxlength="150" value="<?= e($old['full_name'] ?? '') ?>" placeholder="مثال: محمد أحمد علي عثمان" required>
          <small class="field-hint">اكتب اسمك كاملاً (بين حرفين إلى 150 حرفاً) كما يُعتمد في الشهادات.</small>
        </div>

        <div class="form-field">
          <label for="phone">رقم الهاتف الجوال (اختياري)</label>
          <input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="32" value="<?= e($old['phone'] ?? '') ?>" placeholder="مثال: 0912345678 أو 0123456789">
          <small class="field-hint">رقم هاتف الطالب أو ولي الأمر للتواصل والتنبيهات المدرسية.</small>
        </div>

        <div class="step-actions">
          <!-- يضاف زر التالي تلقائياً عبر السكربت -->
        </div>
      </fieldset>

      <!-- الخطوة 2: المسار التعليمي والبريد الإلكتروني -->
      <fieldset class="form-step" data-step="2" data-step-title="المرحلة والبريد">
        <legend class="step-legend">الخطوة الثانية: المسار التعليمي والبريد</legend>
        <p class="step-desc">اختر صفك الدراسي وأدخل بريدك الإلكتروني لتسجيل الدخول.</p>

        <div class="form-field">
          <label for="class_id">المرحلة والصف الدراسي</label>
          <select id="class_id" name="class_id" required <?= empty($data['classes']) ? 'disabled' : '' ?>>
            <option value="">-- اضغط هنا لاختيار الصف الدراسي --</option>
            <?php foreach ($data['classes'] as $class): ?>
              <option value="<?= (int) $class['id'] ?>" <?= (string) ($old['class_id'] ?? '') === (string) $class['id'] ? 'selected' : '' ?>>
                <?= e($class['stage_name'] . ' · ' . $class['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="field-hint">يحدد الصف الدراسي المواد والامتحانات التي ستظهر لك.</small>
        </div>

        <div class="form-field">
          <label for="email">البريد الإلكتروني</label>
          <input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= e($old['email'] ?? '') ?>" placeholder="name@example.com" required>
          <small class="field-hint">ستستخدم هذا البريد لتسجيل الدخول لحسابك في كل مرة.</small>
        </div>

        <div class="step-actions">
          <!-- تضاف أزرار السابق والتالي تلقائياً عبر السكربت -->
        </div>
      </fieldset>

      <!-- الخطوة 3: أمان الحساب وكلمة المرور -->
      <fieldset class="form-step" data-step="3" data-step-title="أمان الحساب">
        <legend class="step-legend">الخطوة الثالثة: كلمة المرور وحماية الحساب</legend>
        <p class="step-desc">اختر كلمة مرور قوية وسهلة التذكر لحسابك.</p>

        <div class="form-field">
          <label for="password">كلمة المرور</label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="12" pattern="[!-~]{8,12}" title="استخدم من 8 إلى 12 حرفًا إنجليزيًا أو رقمًا أو رمزًا." lang="en" dir="ltr" autocapitalize="off" spellcheck="false" placeholder="••••••••" required>
          <small class="field-hint">من 8 إلى 12 محرفًا إنجليزيًا (أحرف أو أرقام أو رموز).</small>
        </div>

        <div class="form-field">
          <label for="password_confirmation">تأكيد كلمة المرور</label>
          <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="12" pattern="[!-~]{8,12}" title="استخدم من 8 إلى 12 حرفًا إنجليزيًا أو رقمًا أو رمزًا." lang="en" dir="ltr" autocapitalize="off" spellcheck="false" placeholder="أعد إدخال كلمة المرور نفسها" required>
          <small class="field-hint">أعد إدخال كلمة المرور للتأكد من مطابقتها.</small>
        </div>

        <div class="form-terms-box">
          <span class="terms-icon" aria-hidden="true">🔒</span>
          <p class="terms-text">بالضغط على زر "إنشاء الحساب"، فإنك توافق على سياسات المنصة واستخدامها للمنهج السوداني.</p>
        </div>

        <div class="step-actions">
          <!-- يضاف زر السابق هنا -->
          <button class="button button-primary button-large" type="submit" <?= empty($data['classes']) ? 'disabled' : '' ?>>
            <span>إرسال طلب التسجيل</span>
          </button>
        </div>
      </fieldset>
    </form>

    <div class="auth-foot">
      <span>لديك حساب بالفعل؟</span>
      <a href="<?= e(url('login')) ?>" class="auth-link-highlight">تسجيل الدخول هنا</a>
    </div>
  </div>
</section>
