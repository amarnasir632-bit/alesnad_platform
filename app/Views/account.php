<?php
$user = $data['accountUser'] ?? current_user();
$student = $data['studentInfo'] ?? null;
$employee = $data['employeeInfo'] ?? null;
$teachingSubjects = $data['teachingSubjects'] ?? [];
$hasContentAccess = $data['hasContentAccess'] ?? false;

$roleTitle = 'عضو بالمنصة';
if (user_has_role('admin')) {
    $roleTitle = 'مدير النظام ومسؤول المنصة';
} elseif (user_has_role('teacher')) {
    $roleTitle = 'أستاذ ومحاضر معتمد';
} elseif (user_has_role('content_reviewer')) {
    $roleTitle = 'مراجع محتوى تعليمي';
} elseif (user_has_role('student')) {
    $roleTitle = 'طالب مسجل';
}
?>

<section class="section page-section account-profile-page" aria-labelledby="account-title">
  <!-- رأس الصفحة والبطاقة العلوية -->
  <div class="account-hero-card">
    <div class="account-hero-content">
      <div class="account-avatar-large<?= !empty($employee['profile_image_key']) ? ' has-profile-image' : '' ?>" aria-hidden="true">
        <?= mb_substr($user['full_name'] ?? 'ع', 0, 1) ?>
        <?php if (!empty($employee['profile_image_key'])): ?>
          <img src="/api/v1/teachers/<?= (int) $employee['id'] ?>/photo" alt="" loading="lazy">
        <?php endif; ?>
      </div>
      <div class="account-hero-meta">
        <span class="account-role-tag"><?= e($roleTitle) ?></span>
        <h1 id="account-title" class="account-user-name"><?= e($user['full_name']) ?></h1>
        <p class="account-email-line">
          <span>📧 <?= e($user['email']) ?></span>
          <?php if (!empty($user['phone'])): ?>
            <span class="meta-dot">·</span>
            <span>📱 <?= e($user['phone']) ?></span>
          <?php endif; ?>
          <?php if (!empty($user['created_at'])): ?>
            <span class="meta-dot">·</span>
            <span>📅 انضم في: <?= e(date('Y-m-d', strtotime($user['created_at']))) ?></span>
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>

  <div class="account-layout-grid">
    <!-- العمود الأيمن: البطاقة التعريفية الأكاديمية ومعلومات الصف والرقم الدراسي -->
    <div class="account-side-column">
      <?php if ($student): ?>
        <div class="detail-card academic-id-card">
          <div class="card-header-with-icon">
            <span class="card-icon" aria-hidden="true">🎓</span>
            <div>
              <h3>بطاقة الهوية الأكاديمية</h3>
              <p class="card-subtext">بيانات القيد والمرحلة الدراسية المعتمدة</p>
            </div>
          </div>

          <div class="academic-badge-box">
            <span class="badge-label">الرقم الدراسي المعتمد</span>
            <div class="badge-number-row">
              <strong class="student-number-val" dir="ltr"><?= e($student['student_number'] ?? student_number_for_id((int) $student['id'])) ?></strong>
            </div>
            <small class="badge-note">استخدم هذا الرقم عند مراجعة إدارة المدرسة والمعلمين</small>
          </div>

          <ul class="academic-info-list">
            <li>
              <span class="info-label">سنة القبول:</span>
              <strong class="info-value"><?= e($student['admission_year'] ?? 'غير محددة') ?></strong>
            </li>
            <li>
              <span class="info-label">المرحلة الدراسية:</span>
              <strong class="info-value"><?= e($student['stage_name'] ?? 'غير محدد') ?></strong>
            </li>
            <li>
              <span class="info-label">الصف الحالي:</span>
              <strong class="info-value"><?= e($student['class_name'] ?? 'غير محدد') ?></strong>
            </li>
            <li>
              <span class="info-label">الفصل الدراسي:</span>
              <strong class="info-value"><?= e($student['semester_name'] ?? 'غير محدد') ?></strong>
            </li>
            <li>
              <span class="info-label">صلاحية تفعيل المحتوى:</span>
              <?php if ($hasContentAccess): ?>
                <span class="status-chip chip-live" style="font-size: 0.85rem;">✓ معتمد ومفعل</span>
              <?php else: ?>
                <span class="status-chip chip-scheduled" style="font-size: 0.85rem;">⏳ بانتظار اعتماد الإدارة</span>
              <?php endif; ?>
            </li>
            <?php if (!empty($student['parent_phone'])): ?>
              <li>
                <span class="info-label">هاتف ولي الأمر:</span>
                <strong class="info-value" dir="ltr"><?= e($student['parent_phone']) ?></strong>
              </li>
            <?php endif; ?>
          </ul>
        </div>
      <?php elseif ($employee): ?>
        <div class="detail-card academic-id-card">
          <div class="card-header-with-icon">
            <span class="card-icon" aria-hidden="true">👨‍🏫</span>
            <div>
              <h3>الملف المهني والأكاديمي</h3>
              <p class="card-subtext">بيانات الإسناد والتدريس</p>
            </div>
          </div>

          <ul class="academic-info-list">
            <li>
              <span class="info-label">المسمى الوظيفي:</span>
              <strong class="info-value"><?= e($employee['job_title'] ?? 'أستاذ') ?></strong>
            </li>
            <li>
              <span class="info-label">رقم ملف الموظف (Teacher ID):</span>
              <strong class="info-value"><?= (int) $employee['id'] ?></strong>
            </li>
          </ul>

          <?php if (!empty($teachingSubjects)): ?>
            <div class="assigned-subjects-box" style="margin-top: 14px;">
              <span class="info-label" style="display:block; margin-bottom: 8px;">المواد والصفوف المسندة للتدريس:</span>
              <ul class="assigned-subjects-list">
                <?php foreach ($teachingSubjects as $sub): ?>
                  <li>
                    <span>📖 <?= e($sub['stage_name'] . ' · ' . $sub['class_name'] . ' · ' . $sub['subject_name']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="detail-card academic-id-card">
          <div class="card-header-with-icon">
            <span class="card-icon" aria-hidden="true">🛡️</span>
            <div>
              <h3>صلاحيات الحساب</h3>
              <p class="card-subtext">حساب إدارة النظام</p>
            </div>
          </div>
          <p style="color: var(--text-muted); font-size: 0.95rem; line-height: 1.6;">
            يمتلك هذا الحساب صلاحيات الإدارة والرقابة الكاملة على المواد، بنك الأسئلة، تفعيل وصول الطلاب، والجدولة.
          </p>
        </div>
      <?php endif; ?>

      <!-- بطاقة تسجيل الخروج السريع -->
      <div class="detail-card logout-action-card">
        <h3>جلسة الدخول</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 14px;">
          يمكنك إنهاء جلستك الحالية والعودة لصفحة البداية بأمان.
        </p>
        <form method="post" action="<?= e(url('home')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="logout">
          <button class="button button-danger button-full" type="submit">
            <span>🚪 تسجيل الخروج من الحساب</span>
          </button>
        </form>
      </div>
    </div>

    <!-- العمود الأيسر: تعديل البيانات الشخصية ورقم الهاتف وتغيير كلمة المرور -->
    <div class="account-main-column">
      <!-- نموذج تعديل البيانات الشخصية ورقم الهاتف -->
      <div class="detail-card">
        <div class="card-header-with-icon">
          <span class="card-icon" aria-hidden="true">✏️</span>
          <div>
            <h2>تعديل البيانات ورقم الهاتف</h2>
            <p class="card-subtext"><?= $student ? 'أرسل طلبًا لمسؤول التسجيل والقبول لتحديث بياناتك الدراسية والشخصية.' : 'قم بتحديث اسمك أو رقم هاتفك الخاص لسهولة التواصل والتنبيهات.' ?></p>
          </div>
        </div>

        <form method="post" action="<?= e(url('account')) ?>" class="account-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="update_profile">

          <div class="form-grid">
            <div class="form-field form-span-2">
              <label for="prof_name">الاسم الكامل <span class="required-mark">*</span></label>
              <input id="prof_name" name="full_name" maxlength="150" value="<?= e($user['full_name']) ?>" required>
            </div>

            <div class="form-field">
              <label for="prof_email">البريد الإلكتروني (المعرف الأساسي)</label>
              <input id="prof_email" type="email" value="<?= e($user['email']) ?>" disabled style="background: #f1f5f9; cursor: not-allowed;">
              <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 4px;">البريد الإلكتروني مرتبط بحسابك الأكاديمي ولا يمكن تغييره يدوياً.</small>
            </div>

            <div class="form-field">
              <label for="prof_phone">رقم هاتف الطالب / المستخدم</label>
              <input id="prof_phone" name="phone" type="tel" maxlength="32" placeholder="مثال: +249123456789" value="<?= e($user['phone'] ?? '') ?>">
            </div>

            <?php if ($student): ?>
              <div class="form-field form-span-2">
                <label for="prof_parent_phone">رقم هاتف ولي الأمر (للتنبيهات والمتابعة)</label>
                <input id="prof_parent_phone" name="parent_phone" type="tel" maxlength="32" placeholder="مثال: +249912345678" value="<?= e($student['parent_phone'] ?? '') ?>">
              </div>
              <div class="form-field form-span-2">
                <label for="correction_reason">سبب طلب التعديل</label>
                <textarea id="correction_reason" name="correction_reason" minlength="5" maxlength="1000" rows="3" required></textarea>
                <small class="field-hint">لن تُعتمد بيانات الطالب إلا بعد مراجعتها من مسؤول التسجيل والقبول.</small>
              </div>
            <?php endif; ?>
          </div>

          <div class="form-actions-bar" style="margin-top: 20px;">
            <button class="button button-primary button-large" type="submit">
              <span>💾 حفظ التعديلات والبيانات</span>
            </button>
          </div>
        </form>
      </div>

      <?php if ($employee && user_has_role('teacher')): ?>
        <div class="detail-card teacher-photo-card">
          <h3>صورة الملف المهني</h3>
          <p class="card-subtext">تظهر الصورة داخل المنصة للمستخدمين المسجلين، ويمكن استبدالها لاحقًا.</p>
          <form method="post" action="<?= e(url('account')) ?>" enctype="multipart/form-data" class="account-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_teacher_profile_image">
            <div class="form-field">
              <label for="teacher_profile_image">اختر صورة JPEG أو PNG أو WebP (حتى 5 ميغابايت)</label>
              <input id="teacher_profile_image" name="profile_image" type="file" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <button class="button button-secondary" type="submit">حفظ الصورة</button>
          </form>
        </div>
      <?php endif; ?>

      <!-- نموذج تغيير كلمة المرور -->
      <div class="detail-card" style="margin-top: 24px;">
        <div class="card-header-with-icon">
          <span class="card-icon" aria-hidden="true">🔐</span>
          <div>
            <h2>الأمان وتغيير كلمة المرور</h2>
            <p class="card-subtext">تأكد من اختيار كلمة مرور قوية مكونة من 12 حرفاً على الأقل</p>
          </div>
        </div>

        <form method="post" action="<?= e(url('account')) ?>" class="account-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="change_password">

          <div class="form-grid">
            <div class="form-field form-span-2">
              <label for="current_pass">كلمة المرور الحالية <span class="required-mark">*</span></label>
              <input id="current_pass" name="current_password" type="password" autocomplete="current-password" required>
            </div>

            <div class="form-field">
              <label for="new_pass">كلمة المرور الجديدة <span class="required-mark">*</span></label>
              <input id="new_pass" name="new_password" type="password" minlength="12" autocomplete="new-password" required>
              <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 4px;">يجب ألا تقل عن 12 حرفاً.</small>
            </div>

            <div class="form-field">
              <label for="confirm_new_pass">تأكيد كلمة المرور الجديدة <span class="required-mark">*</span></label>
              <input id="confirm_new_pass" name="new_password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
            </div>
          </div>

          <div class="form-actions-bar" style="margin-top: 20px;">
            <button class="button button-secondary button-large" type="submit">
              <span>🔒 تحديث كلمة المرور</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>
