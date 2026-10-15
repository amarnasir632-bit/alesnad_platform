<section class="section page-section">
  <span class="eyebrow">إدارة وصول الطلاب</span>
  <h1 class="page-title">صلاحيات محتوى الطلاب</h1>
  <p class="page-intro">يُمنح الوصول إلى محتوى المنصة والدروس والامتحانات أو يُسحب يدويًا من قِبل إدارة المنصة. كل إجراء يُحفظ في سجل التدقيق الموثق.</p>

  <div class="detail-card access-table-wrap">
    <?php if (!$data['students']): ?>
      <div class="empty-state">
        <h3>لا توجد حسابات طلاب مسجلة بعد</h3>
        <p>ستظهر حسابات الطلاب هنا فور قيامهم بإنشاء حساب جديد.</p>
      </div>
    <?php else: ?>
      <table class="access-table">
        <thead>
          <tr>
            <th scope="col">بيانات الطالب</th>
            <th scope="col">البريد الإلكتروني</th>
            <th scope="col">حالة الحساب</th>
            <th scope="col">أهلية الوصول للمحتوى</th>
            <th scope="col">إجراء الإدارة</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($data['students'] as $student): ?>
          <?php 
            $hasAccess = $student['access_id'] !== null; 
            $accountActive = (int) $student['account_active'] === 1; 
          ?>
          <tr>
            <td data-label="بيانات الطالب">
              <strong class="student-name-text"><?= e($student['full_name']) ?></strong>
              <small class="student-id-sub">معرف الطالب: #<?= (int) $student['student_id'] ?></small>
            </td>
            <td data-label="البريد الإلكتروني" dir="ltr" class="email-cell"><?= e($student['email']) ?></td>
            <td data-label="حالة الحساب">
              <?php if ($accountActive): ?>
                <span class="status-pill status-pill-success">نشط</span>
              <?php else: ?>
                <span class="status-pill status-pill-danger">معطل</span>
              <?php endif; ?>
            </td>
            <td data-label="أهلية الوصول للمحتوى">
              <?php if (!$accountActive): ?>
                <span class="status-pill status-pill-muted">لا وصول — الحساب معطل</span>
              <?php elseif ($hasAccess): ?>
                <span class="status-pill status-pill-success" title="تم المنح في <?= e($student['granted_at']) ?>">
                  ✓ مخوّل بالوصول
                </span>
                <small class="granted-date">منذ: <?= e(date('Y-m-d', strtotime($student['granted_at']))) ?></small>
              <?php else: ?>
                <span class="status-pill status-pill-warning">غير مخوّل (بانتظار الموافقة)</span>
              <?php endif; ?>
            </td>
            <td data-label="إجراء الإدارة">
              <form class="access-action" method="post" action="<?= e(url('content_access')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="student_id" value="<?= (int) $student['student_id'] ?>">
                <?php if ($hasAccess): ?>
                  <input type="hidden" name="action" value="content_access_revoke">
                  <label class="visually-hidden" for="reason-revoke-<?= (int) $student['student_id'] ?>">سبب سحب الصلاحية</label>
                  <input id="reason-revoke-<?= (int) $student['student_id'] ?>" name="reason" maxlength="500" placeholder="سبب السحب (اختياري)" class="reason-input">
                  <button class="button button-danger button-small" type="submit">سحب الوصول</button>
                <?php elseif ($accountActive): ?>
                  <input type="hidden" name="action" value="content_access_grant">
                  <label class="visually-hidden" for="reason-grant-<?= (int) $student['student_id'] ?>">سبب منح الصلاحية</label>
                  <input id="reason-grant-<?= (int) $student['student_id'] ?>" name="reason" maxlength="500" placeholder="سبب المنح (اختياري)" class="reason-input">
                  <button class="button button-primary button-small" type="submit">✓ منح الوصول</button>
                <?php else: ?>
                  <span class="muted action-disabled-text">فعّل الحساب أولًا</span>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($data['page_count'] > 1): ?>
        <nav class="pagination" aria-label="صفحات الطلاب">
          <?php if ($data['page'] > 1): ?>
            <a class="button button-small button-secondary" href="<?= e(url('content_access', ['page_num' => $data['page'] - 1])) ?>">← الصفحة السابقة</a>
          <?php endif; ?>
          <span class="pagination-info">صفحة <?= (int) $data['page'] ?> من <?= (int) $data['page_count'] ?> · (إجمالي <?= (int) $data['total'] ?> طالب)</span>
          <?php if ($data['page'] < $data['page_count']): ?>
            <a class="button button-small button-secondary" href="<?= e(url('content_access', ['page_num' => $data['page'] + 1])) ?>">الصفحة التالية →</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
