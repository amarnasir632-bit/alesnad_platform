<section class="section page-section">
  <span class="eyebrow">إدارة وصول الطلاب</span>
  <h1 class="page-title">صلاحية المحتوى</h1>
  <p class="page-intro">يُمنح الوصول إلى محتوى المنصة أو يُسحب يدويًا. كل تغيير يُحفظ في سجل التدقيق؛ لا تسجل معلومات حساسة في خانة السبب.</p>
  <div class="detail-card access-table-wrap">
    <?php if (!$data['students']): ?>
      <div class="empty-state"><h2>لا توجد حسابات طلاب</h2><p>ستظهر حسابات الطلاب هنا بعد إنشائها.</p></div>
    <?php else: ?>
      <table class="access-table">
        <thead><tr><th>الطالب</th><th>البريد</th><th>حالة الحساب</th><th>أهلية المحتوى</th><th>الإجراء</th></tr></thead>
        <tbody>
        <?php foreach ($data['students'] as $student): ?>
          <?php $hasAccess = $student['access_id'] !== null; $accountActive = (int) $student['account_active'] === 1; ?>
          <tr>
            <td><?= e($student['full_name']) ?></td>
            <td dir="ltr"><?= e($student['email']) ?></td>
            <td><?= $accountActive ? 'نشط' : 'معطل' ?></td>
            <td><?php if (!$accountActive): ?>لا وصول — الحساب معطل<?php elseif ($hasAccess): ?>مخوّل منذ <?= e($student['granted_at']) ?><?php else: ?>غير مخوّل<?php endif; ?></td>
            <td>
              <form class="access-action" method="post" action="<?= e(url('content_access')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="student_id" value="<?= (int) $student['student_id'] ?>">
                <?php if ($hasAccess): ?>
                  <input type="hidden" name="action" value="content_access_revoke">
                  <label class="visually-hidden" for="reason-revoke-<?= (int) $student['student_id'] ?>">سبب سحب الصلاحية</label>
                  <input id="reason-revoke-<?= (int) $student['student_id'] ?>" name="reason" maxlength="500" placeholder="سبب السحب (اختياري)">
                  <button class="button button-danger" type="submit">سحب الوصول</button>
                <?php elseif ($accountActive): ?>
                  <input type="hidden" name="action" value="content_access_grant">
                  <label class="visually-hidden" for="reason-grant-<?= (int) $student['student_id'] ?>">سبب منح الصلاحية</label>
                  <input id="reason-grant-<?= (int) $student['student_id'] ?>" name="reason" maxlength="500" placeholder="سبب المنح (اختياري)">
                  <button class="button" type="submit">منح الوصول</button>
                <?php else: ?><span class="muted">فعّل الحساب أولًا</span><?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($data['page_count'] > 1): ?>
        <nav class="pagination" aria-label="صفحات الطلاب">
          <?php if ($data['page'] > 1): ?><a class="button button-small" href="<?= e(url('content_access', ['page_num' => $data['page'] - 1])) ?>">السابق</a><?php endif; ?>
          <span>صفحة <?= (int) $data['page'] ?> من <?= (int) $data['page_count'] ?> · <?= (int) $data['total'] ?> طالب</span>
          <?php if ($data['page'] < $data['page_count']): ?><a class="button button-small" href="<?= e(url('content_access', ['page_num' => $data['page'] + 1])) ?>">التالي</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
