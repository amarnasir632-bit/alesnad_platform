<section class="section page-section">
  <span class="eyebrow">محتوى المنهج السوداني</span>
  <h1 class="page-title">المكتبة والامتحانات السابقة</h1>
  <p class="page-intro">تظهر الملفات المعتمدة فقط للطلاب الذين منحهم المدير أو المراجع صلاحية المحتوى.</p>

  <?php if ($data['can_upload']): ?>
    <details class="detail-card library-upload" open>
      <summary>إضافة كتاب أو امتحان للمراجعة</summary>
      <form method="post" action="<?= e(url('library')) ?>" enctype="multipart/form-data" class="library-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="library_upload">
        <div class="library-form-grid">
          <label>نوع المحتوى<select name="kind" id="library_kind" required><option value="resource">كتاب أو مورد تعليمي</option><option value="exam">امتحان سابق</option></select></label>
          <label>الصف والمادة<select name="class_subject_id" required><option value="">اختر المادة</option><?php foreach ($data['subjects'] as $subject): ?><option value="<?= (int)$subject['class_subject_id'] ?>"><?= e($subject['class_name'] . ' · ' . $subject['subject_name']) ?></option><?php endforeach; ?></select></label>
          <label class="library-wide">العنوان<input name="title" maxlength="200" required></label>
          <label class="library-wide">وصف مختصر<textarea name="description" rows="3" maxlength="5000"></textarea></label>
          <label class="library-wide">ملف PDF<input type="file" name="document" accept="application/pdf,.pdf" id="library_document"><small>حد الحجم الافتراضي 25 ميغابايت. الامتحانات السابقة تتطلب PDF.</small></label>
          <label class="library-wide" id="library_link_wrap">أو رابط مورد خارجي آمن (HTTPS)<input type="url" name="external_url" placeholder="https://"></label>
          <label>مصدر المحتوى<input name="source_owner" maxlength="200"></label>
          <label>الترخيص أو الإذن<input name="copyright_license" maxlength="200"></label>
        </div>
        <button class="button" type="submit">إرسال للمراجعة</button>
      </form>
    </details>
  <?php endif; ?>

  <?php if (!empty($data['access_denied'])): ?>
    <div class="empty-state"><h2>لا تملك صلاحية عرض المكتبة</h2><p>تواصل مع إدارة المنصة إذا كنت تحتاج الوصول.</p></div>
  <?php elseif (!$data['items']): ?>
    <div class="empty-state"><span>⌕</span><h2>لا توجد ملفات هنا بعد</h2><p>ستظهر المواد بعد رفعها واعتمادها، أو بعد منح صلاحية الوصول لحساب الطالب.</p></div>
  <?php else: ?>
    <div class="library-list">
      <?php foreach ($data['items'] as $item): ?>
        <article class="detail-card library-item">
          <div class="library-item-head"><span class="status-pill"><?= e(match ($item['status']) {'PENDING_REVIEW'=>'بانتظار المراجعة','APPROVED'=>'معتمد','REJECTED'=>'مرفوض','DRAFT'=>'يحتاج تعديلًا',default=>$item['status']}) ?></span><span><?= e($item['item_type'] === 'exam' ? 'امتحان سابق' : ($item['resource_type'] === 'LINK' ? 'رابط تعليمي' : 'ملف PDF')) ?></span></div>
          <h2><?= e($item['title']) ?></h2>
          <p class="muted"><?= e($item['class_name'] . ' · ' . $item['subject_name']) ?><?php if (!empty($item['uploader_name'])): ?> · <?= e($item['uploader_name']) ?><?php endif; ?></p>
          <?php if (!empty($item['latest_feedback'])): ?><p class="question-feedback"><strong>ملاحظة المراجع:</strong> <?= e($item['latest_feedback']) ?></p><?php endif; ?>
          <?php if ($item['status'] === 'APPROVED'): ?>
            <?php if ($item['resource_type'] === 'LINK' && !empty($item['external_url'])): ?><a class="button button-small" href="<?= e($item['external_url']) ?>" target="_blank" rel="noopener noreferrer">فتح المورد</a>
            <?php else: ?><a class="button button-small" href="<?= e(url('library_download', ['kind'=>$item['item_type'],'id'=>(int)$item['id']])) ?>">تنزيل PDF</a><?php endif; ?>
          <?php elseif ($data['can_review']): ?>
            <?php if ($item['resource_type'] === 'LINK' && !empty($item['external_url'])): ?><a href="<?= e($item['external_url']) ?>" target="_blank" rel="noopener noreferrer">معاينة الرابط</a><?php else: ?><a class="text-link" href="<?= e(url('library_download', ['kind'=>$item['item_type'],'id'=>(int)$item['id']])) ?>">معاينة PDF</a><?php endif; ?>
            <form class="question-review-form library-review" method="post" action="<?= e(url('library')) ?>">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="library_review"><input type="hidden" name="kind" value="<?= e($item['item_type']) ?>"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
              <label for="library_feedback_<?= e($item['item_type'] . '_' . (string)$item['id']) ?>">ملاحظة المراجع</label><input id="library_feedback_<?= e($item['item_type'] . '_' . (string)$item['id']) ?>" name="feedback" maxlength="2000" placeholder="مطلوبة عند الرفض أو طلب التعديل">
              <button class="button button-small" name="decision" value="APPROVED">اعتماد</button><button class="button button-small button-danger" name="decision" value="REJECTED">رفض</button><button class="button button-small" name="decision" value="NEEDS_MODIFICATION">طلب تعديل</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
