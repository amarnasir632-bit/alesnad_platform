<section class="section page-section">
  <span class="eyebrow">إدارة فريق العمل</span>
  <h1 class="page-title">حسابات ودعوات الفريق</h1>
  <p class="page-intro">أنشئ دعوة أولية لعضو الفريق؛ يحدد المستخدم كلمة مروره بنفسه عبر رابط سري لمرة واحدة صالح لمدة 48 ساعة.</p>

  <?php if ($data['new_invitation_link']): ?>
    <div class="detail-card invitation-result-card" role="status">
      <div class="invitation-icon" aria-hidden="true">🎉</div>
      <div>
        <h3>تم إنشاء رابط الدعوة بنجاح — يظهر لمرة واحدة فقط</h3>
        <p>انسخ الرابط التالي وأرسله للموظف عبر وسيلة تواصل خاصة وآمنة. إذا انتهت صلاحيته (48 ساعة)، يمكنك إنشاء دعوة جديدة:</p>
        <div class="invitation-link-box">
          <input type="text" readonly dir="ltr" value="<?= e($data['new_invitation_link']) ?>" id="invitation-link-input" aria-label="رابط الدعوة الجديد">
          <button class="button button-primary button-small" type="button" onclick="navigator.clipboard.writeText(document.getElementById('invitation-link-input').value); this.textContent='✓ تم النسخ!';">
            نسخ الرابط
          </button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <form class="detail-card staff-create-form" method="post" action="<?= e(url('staff')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="staff_invite">
    <h2>دعوة عضو فريق جديد</h2>
    <p class="form-desc">أدخل بيانات الموظف لتوليد رابط التفعيل الخاص به.</p>

    <div class="staff-form-grid">
      <div class="form-field">
        <label for="staff_name">الاسم الكامل للموظف</label>
        <input id="staff_name" name="full_name" maxlength="150" autocomplete="name" placeholder="مثال: أ. عثمان إبراهيم حسن" required>
      </div>

      <div class="form-field">
        <label for="staff_email">البريد الإلكتروني الرسمي</label>
        <input id="staff_email" name="email" type="email" maxlength="254" autocomplete="email" placeholder="staff@example.com" required>
      </div>

      <div class="form-field">
        <label for="staff_role">الدور والصلاحية</label>
        <select id="staff_role" name="role_code" required>
          <option value="teacher">أستاذ (تدريس وإعداد محتوى واختبارات)</option>
          <option value="content_reviewer">مراجع محتوى (فحص واعتماد المواد)</option>
          <option value="admin">مدير نظام (تحكم كامل)</option>
        </select>
      </div>

      <div class="form-field">
        <label for="staff_job_title">المسمى الوظيفي</label>
        <input id="staff_job_title" name="job_title" maxlength="100" placeholder="مثال: معلم أول فيزياء، مراجع مناهج" required>
      </div>
    </div>

    <button class="button button-primary button-large" type="submit">
      <span>✉️ توليد رابط الدعوة والتفعيل</span>
    </button>
  </form>
</section>

<?php if ($data['can_manage_roles']): ?>
<section class="section page-section staff-list-section">
  <div class="section-head">
    <div>
      <span class="eyebrow">التدريس والمسؤوليات</span>
      <h2>إسناد المواد للأساتذة</h2>
    </div>
  </div>
  <p class="page-intro">يستطيع الأستاذ إنشاء الأسئلة والمحتوى والاختبارات للمواد المسندة إليه فقط.</p>

  <?php if (!$data['teaching_subjects']): ?>
    <div class="empty-state">
      <p>أضف الصفوف والمواد أولًا من صفحة إدارة المنهج لتتمكن من إسنادها للأساتذة.</p>
    </div>
  <?php else: ?>
    <div class="teacher-assignments-grid">
      <?php foreach ($data['teacher_assignments'] as $assignment): ?>
        <article class="detail-card teacher-assignment-card">
          <div class="assignment-head">
            <span class="teacher-avatar" aria-hidden="true">👨‍🏫</span>
            <div>
              <h3><?= e($assignment['full_name']) ?></h3>
              <p class="teacher-subjects-list">المواد الحالية: <?= e($assignment['subjects'] ?: 'لا توجد مواد مسندة بعد') ?></p>
            </div>
          </div>

          <form method="post" action="<?= e(url('staff')) ?>" class="assignment-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="staff_assign_subject">
            <input type="hidden" name="teacher_user_id" value="<?= (int) $assignment['teacher_user_id'] ?>">
            
            <div class="form-field">
              <label for="teacher_subject_<?= (int) $assignment['teacher_user_id'] ?>">إسناد مادة إضافية</label>
              <select id="teacher_subject_<?= (int) $assignment['teacher_user_id'] ?>" name="class_subject_id" required>
                <option value="">-- اختر مادة لإسنادها --</option>
                <?php foreach ($data['teaching_subjects'] as $subject): ?>
                  <option value="<?= (int) $subject['class_subject_id'] ?>">
                    <?= e($subject['stage_name'] . ' · ' . $subject['class_name'] . ' · ' . $subject['subject_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="button button-small button-primary button-full" type="submit">إسناد المادة للأستاذ</button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="section page-section staff-list-section">
  <div class="section-head">
    <div>
      <span class="eyebrow">فريق العمل</span>
      <h2>قائمة حسابات الفريق الحالية</h2>
    </div>
  </div>

  <?php if (!$data['staff']): ?>
    <div class="empty-state">
      <p>لا توجد حسابات فريق مسجلة بعد.</p>
    </div>
  <?php else: ?>
    <div class="detail-card staff-list-wrap">
      <table class="staff-table">
        <thead>
          <tr>
            <th scope="col">الاسم</th>
            <th scope="col">البريد الإلكتروني</th>
            <th scope="col">الدور</th>
            <th scope="col">المسمى الوظيفي</th>
            <th scope="col">حالة الحساب</th>
            <th scope="col">الإجراء</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($data['staff'] as $member): ?>
          <?php 
            $roles = explode(',', $member['role_codes']); 
            $isPrivileged = (bool) array_intersect($roles, ['admin', 'content_reviewer']); 
            $isSelf = (int) $member['id'] === (int) current_user()['id']; 
          ?>
          <tr>
            <td data-label="الاسم"><strong><?= e($member['full_name']) ?></strong></td>
            <td data-label="البريد الإلكتروني" dir="ltr" class="email-cell"><?= e($member['email']) ?></td>
            <td data-label="الدور"><span class="status-pill status-pill-primary"><?= e($member['role_names']) ?></span></td>
            <td data-label="المسمى الوظيفي"><?= e($member['job_title']) ?></td>
            <td data-label="حالة الحساب">
              <?php if ((int) $member['account_active'] === 1): ?>
                <span class="status-pill status-pill-success">نشط</span>
              <?php elseif ($member['invitation_expires_at'] !== null): ?>
                <span class="status-pill status-pill-warning">
                  <?= (int) $member['invitation_valid'] === 1 ? 'بانتظار قبول الدعوة' : 'الدعوة منتهية' ?>
                </span>
              <?php else: ?>
                <span class="status-pill status-pill-danger">معطل</span>
              <?php endif; ?>
            </td>
            <td data-label="الإجراء">
              <?php if ($isSelf): ?>
                <span class="muted self-badge">الحساب الحالي</span>
              <?php elseif ((int) $member['account_active'] !== 1 && $member['invitation_expires_at'] !== null): ?>
                <span class="muted">أعد الدعوة من النموذج أعلاه</span>
              <?php elseif ($data['can_manage_roles'] || !$isPrivileged): ?>
                <form method="post" action="<?= e(url('staff')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="target_user_id" value="<?= (int) $member['id'] ?>">
                  <?php if ((int) $member['account_active'] === 1): ?>
                    <input type="hidden" name="action" value="staff_disable">
                    <button class="button button-small button-danger" type="submit">تعطيل</button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="staff_enable">
                    <button class="button button-small button-primary" type="submit">إعادة تفعيل</button>
                  <?php endif; ?>
                </form>
              <?php else: ?>
                <span class="muted">للمدير فقط</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
