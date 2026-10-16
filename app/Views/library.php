<section class="section page-section">
  <span class="eyebrow">محتوى المنهج السوداني</span>
  <h1 class="page-title">المكتبة والامتحانات السابقة</h1>
  <p class="page-intro">تظهر الملفات المعتمدة فقط للطلاب الذين منحهم المدير أو المراجع صلاحية المحتوى.</p>

  <?php if ($data['can_upload']): ?>
    <details class="detail-card library-upload" open>
      <summary class="button button-primary">إضافة كتاب أو امتحان أو فيديو للمراجعة</summary>
      <form method="post" action="<?= e(url('library')) ?>" enctype="multipart/form-data" class="library-form multi-step-form" id="library-upload-form">
        <div id="library-upload-feedback" class="messages" aria-live="polite"></div>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="library_upload">

        <!-- الخطوة 1: تصنيف المحتوى والعنوان -->
        <fieldset class="form-step" data-step="1" data-step-title="تصنيف المحتوى">
          <legend class="step-legend">الخطوة الأولى: تحديد نوع المحتوى والمادة</legend>
          <p class="step-desc">حدد إن كان المحتوى كتاباً أو امتحاناً سابقاً والمادة التي يتبع لها.</p>

          <div class="form-grid-2">
            <div class="form-field">
              <label for="library_kind">نوع المحتوى</label>
              <select name="kind" id="library_kind" required>
                <option value="resource">كتاب أو مورد تعليمي (PDF / فيديو)</option>
                <option value="exam">امتحان شهادة / امتحان سابق (PDF)</option>
              </select>
            </div>

            <div class="form-field">
              <label for="library_class_subject">الصف الدراسي والمادة</label>
              <select name="class_subject_id" id="library_class_subject" required>
                <option value="">-- اضغط لاختيار المادة الدراسية --</option>
                <?php foreach ($data['subjects'] as $subject): ?>
                  <option value="<?= (int)$subject['class_subject_id'] ?>"><?= e($subject['class_name'] . ' · ' . $subject['subject_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-field">
            <label for="library_title">عنوان المورد أو الامتحان</label>
            <input id="library_title" name="title" maxlength="200" placeholder="مثال: كتاب الفيزياء للصف الثالث الثانوي - الباب الأول" required>
          </div>

          <div class="form-field">
            <label for="library_description">وصف مختصر ومحتويات المورد</label>
            <textarea id="library_description" name="description" rows="3" maxlength="5000" placeholder="ملاحظات توضيحية للطلاب حول موضوعات هذا المورد..."></textarea>
          </div>

          <div class="form-field">
            <label for="library_lesson_id">معرف الدرس المرتبط (اختياري)</label>
            <input type="number" id="library_lesson_id" name="lesson_id" min="1" placeholder="مثال: 12 (اتركه فارغاً إن كان المورد عاماً لكامل المادة)">
          </div>

          <div class="step-actions"></div>
        </fieldset>

        <!-- الخطوة 2: ملف المحتوى أو الرابط الخارجي -->
        <fieldset class="form-step" data-step="2" data-step-title="الملف أو الرابط">
          <legend class="step-legend">الخطوة الثانية: رفع الملف أو إدراج الرابط</legend>
          <p class="step-desc">اختر ملف PDF أو ملف فيديو أو ضع رابطاً لمورد خارجي موثوق.</p>

          <div class="form-field">
            <label for="library_document">ملف المحتوى (PDF أو فيديو)</label>
            <input type="file" name="document" accept="application/pdf,.pdf,video/mp4,video/quicktime,video/webm" id="library_document">
            <small class="field-hint">الفيديو للمورد التعليمي يُرفع مباشرة إلى مزود البث، والامتحانات تتطلب صيغة PDF.</small>
          </div>

          <div class="form-field" id="library_link_wrap">
            <label for="library_external_url">أو رابط مورد تعليمي خارجي آمن (HTTPS)</label>
            <input type="url" name="external_url" id="library_external_url" placeholder="https://example.com/resource">
            <small class="field-hint">يُتاح للمصادر الخارجية مثل المواقع والمنصات التعليمية الرسمية.</small>
          </div>

          <div class="step-actions"></div>
        </fieldset>

        <!-- الخطوة 3: المصدر وحقوق النشر والتسليم -->
        <fieldset class="form-step" data-step="3" data-step-title="المصدر والترخيص">
          <legend class="step-legend">الخطوة الثالثة: المصدر وحقوق النشر</legend>
          <p class="step-desc">أدخل بيانات مالك المورد وجهة إصداره لاعتماده من المراجع.</p>

          <div class="form-field">
            <label for="library_source_owner">مصدر المحتوى أو المؤلف</label>
            <input id="library_source_owner" name="source_owner" maxlength="200" placeholder="مثال: وزارة التربية والتعليم السودانية">
          </div>

          <div class="form-field">
            <label for="library_source_reference">مرجع المصدر (رابط أو طبعة الكتاب)</label>
            <input id="library_source_reference" name="source_reference" maxlength="2000" placeholder="مثال: الطبعة المعتمدة لسنة 2024">
          </div>

          <div class="form-field">
            <label for="library_copyright_license">الترخيص أو إذن الاستخدام</label>
            <input id="library_copyright_license" name="copyright_license" maxlength="200" placeholder="مثال: متاح للاستخدام التعليمي المجاني">
          </div>

          <div class="step-actions">
            <button class="button button-primary button-large" type="submit">
              <span>✓ إرسال المورد للمراجعة والاعتماد</span>
            </button>
          </div>
        </fieldset>
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
        <?php $rc = get_resource_cover_data($item['title'], $item['resource_type'] ?? ''); ?>
        <article class="detail-card library-item">
          <div class="card-smart-cover library-card-cover" style="background: <?= e($rc['gradient']) ?>;">
            <span class="cover-symbol-watermark" aria-hidden="true"><?= e($rc['symbol']) ?></span>
            <span class="cover-icon-badge" aria-hidden="true"><?= e($rc['icon']) ?></span>
          </div>
          <div class="library-item-head"><span class="status-pill"><?= e(match ($item['status']) {'PENDING_REVIEW'=>'بانتظار المراجعة','APPROVED'=>'معتمد','REJECTED'=>'مرفوض','DRAFT'=>'يحتاج تعديلًا',default=>$item['status']}) ?></span><span><?= e($item['item_type'] === 'exam' ? 'امتحان سابق' : ($item['resource_type'] === 'LINK' ? 'رابط تعليمي' : ($item['resource_type'] === 'VIDEO' ? 'فيديو' : 'ملف PDF'))) ?></span></div>
          <h2><?= e($item['title']) ?></h2>
          <p class="muted"><?= e($item['class_name'] . ' · ' . $item['subject_name']) ?><?php if (!empty($item['uploader_name'])): ?> · <?= e($item['uploader_name']) ?><?php endif; ?></p>
          <?php if (!empty($item['latest_feedback'])): ?><p class="question-feedback"><strong>ملاحظة المراجع:</strong> <?= e($item['latest_feedback']) ?></p><?php endif; ?>
          <?php if ($item['status'] === 'APPROVED'): ?>
            <?php if ($item['resource_type'] === 'LINK' && !empty($item['external_url'])): ?><a class="button button-small" href="<?= e($item['external_url']) ?>" target="_blank" rel="noopener noreferrer">فتح المورد</a>
            <?php else: ?><a class="button button-small" href="<?= e(url('library_download', ['kind'=>$item['item_type'],'id'=>(int)$item['id']])) ?>"><?= $item['resource_type'] === 'VIDEO' ? 'مشاهدة الفيديو' : 'تنزيل PDF' ?></a><?php endif; ?>
          <?php elseif ($data['can_review']): ?>
            <?php if ($item['resource_type'] === 'LINK' && !empty($item['external_url'])): ?><a href="<?= e($item['external_url']) ?>" target="_blank" rel="noopener noreferrer">معاينة الرابط</a><?php else: ?><a class="text-link" href="<?= e(url('library_download', ['kind'=>$item['item_type'],'id'=>(int)$item['id']])) ?>"><?= $item['resource_type'] === 'VIDEO' ? 'معاينة الفيديو' : 'معاينة PDF' ?></a><?php endif; ?>
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
