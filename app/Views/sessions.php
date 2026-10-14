<section class="section container sessions-page" aria-labelledby="sessions-title">
  <div class="section-head"><h1 id="sessions-title">الحصص المباشرة والتسجيلات</h1></div>
  <p class="muted">تظهر الحصص المرتبطة بصفك أو بالمواد المسندة إليك. يحتاج الطالب إلى صلاحية محتوى فعالة يمنحها المدير أو المراجع.</p>
  <div id="session-feedback" class="messages" aria-live="polite"></div>

  <?php if ($data['sessionCanManage']): ?>
    <section class="panel session-create-panel" aria-labelledby="session-create-title">
      <h2 id="session-create-title">جدولة حصة</h2>
      <form id="session-create-form" class="form-grid">
        <label>عنوان الحصة<input name="title" maxlength="150" required></label>
        <label>موعد البداية<input name="scheduled_at" type="datetime-local" required></label>
        <label>المدة بالدقائق<input name="duration_minutes" type="number" min="1" max="32767" value="60" required></label>
        <label>الصف والمادة
          <?php if (!in_array('admin', current_user()['roles'] ?? [], true)): ?>
            <select name="class_subject_id" required><option value="">اختر الإسناد</option><?php foreach ($data['teachingAssignments'] as $assignment): ?><option value="<?= (int) $assignment['class_subject_id'] ?>"><?= e($assignment['stage_name'] . ' · ' . $assignment['class_name'] . ' · ' . $assignment['subject_name']) ?></option><?php endforeach; ?></select>
          <?php else: ?>
            <select name="class_subject_id" id="admin-class-subject" required><option value="">جارٍ تحميل الصفوف والمواد…</option></select>
          <?php endif; ?>
        </label>
        <?php if (in_array('admin', current_user()['roles'] ?? [], true)): ?><label>رقم ملف الأستاذ<input name="teacher_id" type="number" min="1" required></label><?php endif; ?>
        <label class="form-span">الوصف<textarea name="description" maxlength="5000" rows="3"></textarea></label>
        <button class="button" type="submit">جدولة الحصة</button>
      </form>
      <p class="muted">بعد الجدولة اضغط «إعداد البث» مرة واحدة. سيظهر مفتاح البث للأستاذ مرة واحدة فقط؛ خزّنه في برنامج البث ولا تشاركه.</p>
    </section>
  <?php endif; ?>

  <div id="session-list" class="session-list">
    <?php if ($data['sessions'] === []): ?>
      <div class="empty-state"><h2>لا توجد حصص متاحة</h2><p>ستظهر الحصص هنا بعد إسناد المادة ومنح صلاحية المحتوى للطالب.</p></div>
    <?php endif; ?>
    <?php foreach ($data['sessions'] as $session): ?>
      <article class="session-card" data-session-id="<?= (int) $session['id'] ?>">
        <div class="session-card-heading"><div><h2><?= e($session['title']) ?></h2><p><?= e($session['subject_name'] ?? 'المادة') ?> · <?= e($session['scheduled_at']) ?></p></div><span class="status-chip"><?= e($session['status']) ?></span></div>
        <?php if (!empty($session['description'])): ?><p><?= nl2br(e($session['description'])) ?></p><?php endif; ?>
        <p class="muted">المدة: <?= (int) ($session['duration_minutes'] ?? 0) ?> دقيقة</p>
        <div class="session-actions">
          <?php if ($data['sessionCanManage']): ?><button class="button button-small" type="button" data-action="provision">إعداد البث</button><button class="button button-small button-secondary" type="button" data-action="sync">مزامنة التسجيلات</button><?php if (!in_array($session['status'], ['COMPLETED', 'CANCELLED'], true)): ?><button class="button button-small button-secondary" type="button" data-action="complete">إنهاء الحصة</button><?php endif; ?><?php endif; ?>
          <button class="button button-small" type="button" data-action="join">انضمام / مشاهدة</button>
          <button class="button button-small button-secondary" type="button" data-action="recordings">عرض التسجيلات</button>
        </div>
        <div class="session-output" aria-live="polite"></div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
