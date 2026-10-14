<section class="section page-section">
  <span class="eyebrow">إدارة المستخدمين</span>
  <h1 class="page-title">حسابات الفريق</h1>
  <p class="page-intro">أنشئ دعوة أولية فقط؛ المستخدم يحدد كلمة مروره من رابط سري لمرة واحدة صالح لمدة 48 ساعة. أرسل الرابط للموظف عبر قناة موثوقة، ولا تشاركه علنًا.</p>

  <?php if ($data['new_invitation_link']): ?>
    <div class="detail-card invitation-result" role="status">
      <h2>رابط الدعوة — يظهر مرة واحدة</h2>
      <p>انسخ الرابط وأرسله للموظف. إذا فُقد أو انتهت صلاحيته، أنشئ دعوة جديدة للحساب.</p>
      <input type="text" readonly dir="ltr" value="<?= e($data['new_invitation_link']) ?>" aria-label="رابط الدعوة الجديد">
    </div>
  <?php endif; ?>

  <form class="detail-card staff-create-form" method="post" action="<?= e(url('staff')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="staff_invite">
    <h2>دعوة عضو فريق</h2>
    <div class="staff-form-grid">
      <div class="form-field"><label for="staff_name">الاسم الكامل</label><input id="staff_name" name="full_name" maxlength="150" autocomplete="name" required></div>
      <div class="form-field"><label for="staff_email">البريد الإلكتروني</label><input id="staff_email" name="email" type="email" maxlength="254" autocomplete="email" required></div>
      <div class="form-field"><label for="staff_role">الدور</label>
        <select id="staff_role" name="role_code" required>
          <option value="teacher">أستاذ</option><option value="content_reviewer">مراجع محتوى</option><option value="admin">مدير نظام</option>
        </select>
      </div>
      <div class="form-field"><label for="staff_job_title">المسمى الوظيفي</label><input id="staff_job_title" name="job_title" maxlength="100" required></div>
    </div>
    <button class="button" type="submit">إنشاء رابط الدعوة</button>
  </form>
</section>
<?php if ($data['can_manage_roles']): ?>
<section class="section page-section staff-list-section">
  <h2>إسناد المواد للأساتذة</h2>
  <p class="page-intro">يستطيع الأستاذ إنشاء الأسئلة للمواد المسندة إليه فقط.</p>
  <?php if (!$data['teaching_subjects']): ?><div class="empty-state"><p>أضف الصفوف والمواد أولًا من صفحة إدارة المنهج.</p></div>
  <?php else: ?><div class="admin-grid">
    <?php foreach ($data['teacher_assignments'] as $assignment): ?>
      <article class="detail-card teacher-assignment-card"><h3><?= e($assignment['full_name']) ?></h3><p><?= e($assignment['subjects'] ?: 'لا توجد مواد مسندة') ?></p>
        <form method="post" action="<?= e(url('staff')) ?>" class="admin-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="staff_assign_subject"><input type="hidden" name="teacher_user_id" value="<?= (int) $assignment['teacher_user_id'] ?>">
          <label for="teacher_subject_<?= (int) $assignment['teacher_user_id'] ?>">إضافة مادة</label>
          <select id="teacher_subject_<?= (int) $assignment['teacher_user_id'] ?>" name="class_subject_id" required><option value="">اختر مادة</option><?php foreach ($data['teaching_subjects'] as $subject): ?><option value="<?= (int) $subject['class_subject_id'] ?>"><?= e($subject['stage_name'] . ' · ' . $subject['class_name'] . ' · ' . $subject['subject_name']) ?></option><?php endforeach; ?></select>
          <button class="button button-small" type="submit">إسناد</button>
        </form>
      </article>
    <?php endforeach; ?>
  </div><?php endif; ?>
</section>
<?php endif; ?>

<section class="section page-section staff-list-section">
  <h2>حسابات الفريق الحالية</h2>
  <?php if (!$data['staff']): ?>
    <div class="empty-state"><p>لا توجد حسابات فريق بعد.</p></div>
  <?php else: ?>
    <div class="detail-card staff-list-wrap">
      <table class="staff-table">
        <thead><tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>المسمى</th><th>الحالة</th><th>الإجراء</th></tr></thead>
        <tbody>
        <?php foreach ($data['staff'] as $member): ?>
          <?php $roles = explode(',', $member['role_codes']); $isPrivileged = (bool) array_intersect($roles, ['admin', 'content_reviewer']); $isSelf = (int) $member['id'] === (int) current_user()['id']; ?>
          <tr>
            <td><?= e($member['full_name']) ?></td>
            <td dir="ltr"><?= e($member['email']) ?></td>
            <td><?= e($member['role_names']) ?></td>
            <td><?= e($member['job_title']) ?></td>
            <td><?php if ((int) $member['account_active'] === 1): ?>نشط<?php elseif ($member['invitation_expires_at'] !== null): ?><?= (int) $member['invitation_valid'] === 1 ? 'بانتظار تفعيل الدعوة' : 'الدعوة منتهية' ?><?php else: ?>معطل<?php endif; ?></td>
            <td>
              <?php if ($isSelf): ?><span class="muted">الحساب الحالي</span>
              <?php elseif ((int) $member['account_active'] !== 1 && $member['invitation_expires_at'] !== null): ?>
                <span class="muted">أعد الدعوة من النموذج أعلاه</span>
              <?php elseif ($data['can_manage_roles'] || !$isPrivileged): ?>
                <form method="post" action="<?= e(url('staff')) ?>">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="target_user_id" value="<?= (int) $member['id'] ?>">
                  <?php if ((int) $member['account_active'] === 1): ?><input type="hidden" name="action" value="staff_disable"><button class="button button-small button-danger" type="submit">تعطيل</button>
                  <?php else: ?><input type="hidden" name="action" value="staff_enable"><button class="button button-small" type="submit">إعادة تفعيل</button><?php endif; ?>
                </form>
              <?php else: ?><span class="muted">للمدير فقط</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
