<section class="section page-section announcements-admin-page">
  <div class="section-head">
    <div>
      <span class="eyebrow">التواصل والإشعارات</span>
      <h1 class="page-title"><?= $data['is_admin'] ? 'إدارة لوحة الإعلانات المدرسية' : 'إعلانات المواد والطلاب' ?></h1>
    </div>
    <a class="button button-secondary button-small" href="<?= e(url('home')) ?>">← العودة للرئيسية</a>
  </div>
  <p class="page-intro">
    <?= $data['is_admin'] 
      ? 'نشر وتعديل الإعلانات المدرسية العامة للمنصة أو الموجهة لمراحل وصفوف ومواد محددة.' 
      : 'نشر وتوجيه الإعلانات والتنبيهات المدرسية لطلاب المواد والصفوف المسندة إليك فقط.' ?>
  </p>

  <!-- نموذج نشر إعلان جديد -->
  <form class="detail-card announcement-form-card" method="post" action="<?= e(url('announcements')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="announcement_create">

    <div class="form-card-head">
      <span class="form-head-icon" aria-hidden="true">✍️</span>
      <div>
        <h2>نشر إعلان أكاديمي جديد</h2>
        <p class="form-desc">املأ تفاصيل الإعلان ليظهر فوراً في الشريط الإعلاني بالصفحة الرئيسية للمستهدفين.</p>
      </div>
    </div>

    <div class="announcement-form-grid">
      <div class="form-field form-span-2">
        <label for="announcement_title">عنوان الإعلان <span class="required-mark">*</span></label>
        <input id="announcement_title" name="title" maxlength="255" placeholder="مثال: تنويه بخصوص موعد الاختبار التجريبي الأول لمادة الرياضيات" required>
      </div>

      <div class="form-field">
        <label for="announcement_badge">شارة ووسم الإعلان</label>
        <select id="announcement_badge" name="badge_label">
          <option value="📢 تنبيه أكاديمي">📢 تنبيه أكاديمي</option>
          <option value="⏰ مواعيد الحصص والاختبارات">⏰ مواعيد الحصص والاختبارات</option>
          <option value="⭐ إعلان هام">⭐ إعلان هام</option>
          <option value="🏆 تكريم وتفوق">🏆 تكريم وتفوق</option>
          <option value="📚 مذكرات ومصادر">📚 مذكرات ومصادر</option>
          <option value="🎉 مناسبة مدرسية">🎉 مناسبة مدرسية</option>
        </select>
      </div>

      <div class="form-field">
        <label for="announcement_priority">مستوى الأهمية والظهور</label>
        <select id="announcement_priority" name="priority">
          <option value="NORMAL">عادي (ظهور قياسي)</option>
          <option value="IMPORTANT">هام (أولوية مرتفعة)</option>
          <option value="URGENT">عاجل (يظهر بأعلى الشريط بلون تنبيهي بارز)</option>
        </select>
      </div>

      <div class="form-field">
        <label for="announcement_target">نطاق الإعلان والمادة المستهدفة</label>
        <?php if ($data['is_admin']): ?>
          <select id="announcement_target" name="class_subject_id">
            <option value="">🌐 إعلان عام لجميع مستخدمي وطلاب المنصة</option>
            <?php foreach ($data['allSubjects'] as $sub): ?>
              <option value="<?= (int) $sub['class_subject_id'] ?>">
                <?= e($sub['stage_name'] . ' · ' . $sub['class_name'] . ' · ' . $sub['subject_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="field-hint">بإمكان المدير اختيار إعلان عام للجميع أو حصر الإعلان في مادة محددة.</small>
        <?php else: ?>
          <select id="announcement_target" name="class_subject_id" required>
            <option value="">-- اختر مادة من موادك المسندة --</option>
            <?php foreach ($data['teachingAssignments'] as $as): ?>
              <option value="<?= (int) $as['class_subject_id'] ?>">
                <?= e($as['stage_name'] . ' · ' . $as['class_name'] . ' · ' . $as['subject_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="field-hint">يصل الإعلان فقط للطلاب المسجلين في هذا الصف والمادة المسندة إليك.</small>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label for="announcement_cover">نمط الغلاف البصري المولد</label>
        <select id="announcement_cover" name="cover_style">
          <option value="gradient_blue">أزرق ملكي أكاديمي (Royal Blue)</option>
          <option value="gradient_emerald">أخضر زمردي هادئ (Emerald Green)</option>
          <option value="gradient_amber">ذهبي شروق دافئ (Warm Amber)</option>
          <option value="gradient_purple">أرجواني تقني فخم (Deep Purple)</option>
          <option value="gradient_rose">وردي احتفالي مشرق (Bright Rose)</option>
        </select>
        <small class="field-hint">أغلفة ذكية خفيفة جداً لا تستهلك السيرفر وتمنح الإعلان مظهراً متميزاً.</small>
      </div>

      <div class="form-field form-span-2">
        <label for="announcement_content">نص ومحتوى الإعلان التفصيلي <span class="required-mark">*</span></label>
        <textarea id="announcement_content" name="content" rows="4" maxlength="5000" placeholder="اكتب تفاصيل التنبيه أو التوجيهات للطلاب وأولياء الأمور..." required></textarea>
      </div>
    </div>

    <div class="form-actions-bar">
      <button class="button button-primary button-large" type="submit">
        <span>📢 اعتماد ونشر الإعلان فوراً</span>
      </button>
    </div>
  </form>

  <!-- قائمة الإعلانات المنشورة -->
  <section class="announcements-list-section">
    <div class="section-head">
      <div>
        <span class="eyebrow">سجل المنشورات</span>
        <h2>الإعلانات الحالية (<?= count($data['announcements']) ?>)</h2>
      </div>
    </div>

    <?php if (empty($data['announcements'])): ?>
      <div class="empty-state">
        <span class="empty-icon" aria-hidden="true">📭</span>
        <h3>لا توجد إعلانات منشورة بعد</h3>
        <p>استخدم النموذج أعلاه لنشر أول إعلان مدرسي في المنصة.</p>
      </div>
    <?php else: ?>
      <div class="announcements-cards-grid">
        <?php foreach ($data['announcements'] as $item): ?>
          <?php 
            $cover = get_announcement_cover_data($item['cover_style'] ?? 'gradient_blue', $item['priority'] ?? 'NORMAL');
            $isPublished = (bool) $item['is_published'];
          ?>
          <article class="detail-card announcement-admin-card <?= !$isPublished ? 'is-disabled' : '' ?>">
            <div class="announcement-card-banner" style="background: <?= e($cover['gradient']) ?>;">
              <span class="announcement-badge-pill"><?= e($item['badge_label'] ?: $cover['badge']) ?></span>
              <span class="announcement-priority-pill priority-<?= strtolower(e($item['priority'])) ?>">
                <?= $item['priority'] === 'URGENT' ? '🚨 عاجل' : ($item['priority'] === 'IMPORTANT' ? '⭐ هام' : 'عادي') ?>
              </span>
              <span class="cover-icon-watermark" aria-hidden="true"><?= e($cover['icon']) ?></span>
            </div>

            <div class="announcement-card-body">
              <h3 class="announcement-item-title"><?= e($item['title']) ?></h3>
              <p class="announcement-item-desc"><?= nl2br(e($item['content'])) ?></p>

              <div class="announcement-item-meta">
                <span>👤 بواسطة: <?= e($item['author_name']) ?></span>
                <span>📅 <?= e(date('Y-m-d H:i', strtotime($item['created_at']))) ?></span>
                <?php if (!empty($item['subject_name'])): ?>
                  <span class="scope-tag">🎯 مادة: <?= e($item['class_name'] . ' · ' . $item['subject_name']) ?></span>
                <?php else: ?>
                  <span class="scope-tag scope-general">🌐 إعلان عام لكافة المنصة</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="announcement-card-footer">
              <span class="status-pill <?= $isPublished ? 'status-pill-success' : 'status-pill-muted' ?>">
                <?= $isPublished ? '✓ منشور ونشط' : 'معطّل ومخفي' ?>
              </span>

              <div class="card-action-btns">
                <form method="post" action="<?= e(url('announcements')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="announcement_toggle">
                  <input type="hidden" name="announcement_id" value="<?= (int) $item['id'] ?>">
                  <button class="button button-small button-secondary" type="submit">
                    <?= $isPublished ? 'إخفاء الإعلان' : 'إعادة التفعيل' ?>
                  </button>
                </form>

                <form method="post" action="<?= e(url('announcements')) ?>" class="inline-form" onsubmit="return confirm('هل أنت متأكد من حذف هذا الإعلان نهائياً؟');">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="announcement_delete">
                  <input type="hidden" name="announcement_id" value="<?= (int) $item['id'] ?>">
                  <button class="button button-small button-danger" type="submit">حذف</button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</section>
