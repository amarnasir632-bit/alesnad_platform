<section class="section page-section sessions-container-page" aria-labelledby="sessions-title">
  <div class="section-head">
    <div>
      <span class="eyebrow">الفصول الافتراضية والتعليم المباشر</span>
      <h1 id="sessions-title" class="page-title">الحصص المباشرة وتسجيلات المحاضرات</h1>
    </div>
    <div class="head-live-indicator">
      <span class="live-pulse-ring" aria-hidden="true"></span>
      <span class="live-status-tag">بث مباشر وتفاعلي</span>
    </div>
  </div>
  <p class="page-intro">
    تظهر هنا الحصص المرتبطة بصفك الدراسي أو بالمواد المسندة إليك. يحتاج الطالب إلى صلاحية محتوى فعالة يمنحها مدير المنصة للمشاركة ومشاهدة التسجيلات.
  </p>

  <div id="session-feedback" class="messages" aria-live="polite"></div>

  <?php if ($data['sessionCanManage']): ?>
    <!-- بطاقة جدولة حصة تفاعلية جديدة للمدرس والمدير -->
    <details class="detail-card session-create-accordion" open>
      <summary class="session-create-summary">
        <div class="summary-title-wrap">
          <span class="summary-icon" aria-hidden="true">🎥</span>
          <div>
            <h2>جدولة حصة افتراضية جديدة</h2>
            <p class="form-desc">حدد المادة وتوقيت البدء والمدة لتجهيز غرفة البث ومزامنة تسجيلها لاحقاً.</p>
          </div>
        </div>
        <span class="summary-chevron" aria-hidden="true">▾</span>
      </summary>

      <form id="session-create-form" class="session-form-body">
        <div class="session-form-grid">
          <div class="form-field form-span-2">
            <label for="session_title">عنوان الحصة <span class="required-mark">*</span></label>
            <input id="session_title" name="title" maxlength="150" placeholder="مثال: مراجعة قوانين نيوتن وحل التمارين النموذجية" required>
          </div>

          <div class="form-field">
            <label for="session_time">موعد بداية الحصة <span class="required-mark">*</span></label>
            <input id="session_time" name="scheduled_at" type="datetime-local" required>
          </div>

          <div class="form-field">
            <label for="session_duration">المدة المقدرة (بالدقائق) <span class="required-mark">*</span></label>
            <input id="session_duration" name="duration_minutes" type="number" min="1" max="32767" value="60" required>
          </div>

          <div class="form-field form-span-2">
            <label for="session_subject">الصف والمادة المسندة <span class="required-mark">*</span></label>
            <?php if (!in_array('admin', current_user()['roles'] ?? [], true)): ?>
              <select id="session_subject" name="class_subject_id" required>
                <option value="">-- اختر مادة من موادك المسندة --</option>
                <?php foreach ($data['teachingAssignments'] as $assignment): ?>
                  <option value="<?= (int) $assignment['class_subject_id'] ?>">
                    <?= e($assignment['stage_name'] . ' · ' . $assignment['class_name'] . ' · ' . $assignment['subject_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <select id="admin-class-subject" name="class_subject_id" required>
                <option value="">جارٍ تحميل الصفوف والمواد المعتمدة…</option>
              </select>
            <?php endif; ?>
          </div>

          <?php if (in_array('admin', current_user()['roles'] ?? [], true)): ?>
            <div class="form-field">
              <label for="session_teacher_id">رقم ملف الأستاذ (Teacher ID) <span class="required-mark">*</span></label>
              <input id="session_teacher_id" name="teacher_id" type="number" min="1" placeholder="مثال: 1" required>
            </div>
          <?php endif; ?>

          <div class="form-field">
            <label for="session_lesson_id">معرف الدرس المرتبط (اختياري)</label>
            <input id="session_lesson_id" name="lesson_id" type="number" min="1" placeholder="مثال: 5">
          </div>

          <div class="form-field form-span-2">
            <label for="session_desc">وصف المحاضرة والمحاور المستهدفة</label>
            <textarea id="session_desc" name="description" maxlength="5000" rows="3" placeholder="ملاحظات وتوجيهات للطلاب للاستعداد للحصة..."></textarea>
          </div>
        </div>

        <div id="session-form-feedback" class="messages" aria-live="polite" style="margin-top: 14px;"></div>

        <div class="form-actions-bar">
          <button id="session-submit-btn" class="button button-primary button-large" type="submit">
            <span>🎥 اعتماد وجدولة الحصة الآن</span>
          </button>
        </div>
      </form>
    </details>
  <?php endif; ?>

  <!-- قائمة الحصص والتسجيلات -->
  <div class="sessions-feed-header">
    <span class="eyebrow">جدول المحاضرات</span>
    <h2>الحصص المقررة والتسجيلات</h2>
  </div>

  <div id="session-list" class="sessions-feed-grid">
    <?php if (empty($data['sessions'])): ?>
      <div class="empty-state">
        <span class="empty-icon" aria-hidden="true">📡</span>
        <h3>لا توجد حصص مجدولة حالياً</h3>
        <p>ستظهر الحصص القادمة والتسجيلات المتاحة فور قيام الأساتذة بجدولتها للمواد المقررة.</p>
      </div>
    <?php else: ?>
      <?php foreach ($data['sessions'] as $session): ?>
        <?php 
          $status = $session['status'];
          $isLive = in_array($status, ['LIVE', 'IN_PROGRESS'], true);
          $isCompleted = in_array($status, ['COMPLETED'], true);
          $subName = $session['subject_name'] ?? 'مادة دراسية';
          $coverData = get_subject_cover_data($subName);
        ?>
        <article class="detail-card session-modern-card <?= $isLive ? 'is-live-card' : '' ?>" data-session-id="<?= (int) $session['id'] ?>">
          <div class="session-card-banner" style="background: <?= e($coverData['gradient']) ?>;">
            <div class="banner-top-tags">
              <?php if ($isLive): ?>
                <span class="status-chip chip-live">
                  <span class="pulse-dot" aria-hidden="true"></span>
                  🔴 بث حي مباشر الآن
                </span>
              <?php elseif ($isCompleted): ?>
                <span class="status-chip chip-completed">✓ حصة مكتملة ومسجلة</span>
              <?php else: ?>
                <span class="status-chip chip-scheduled">⏰ موعد مجدول</span>
              <?php endif; ?>
              <span class="session-duration-tag">⏱️ <?= (int) ($session['duration_minutes'] ?? 0) ?> دقيقة</span>
            </div>
            <span class="session-cover-icon" aria-hidden="true"><?= e($coverData['icon']) ?></span>
          </div>

          <div class="session-card-content">
            <span class="session-subject-eyebrow"><?= e($subName) ?></span>
            <h2 class="session-card-title"><?= e($session['title']) ?></h2>

            <div class="session-meta-row">
              <span class="meta-item">📅 <?= e(date('Y-m-d · H:i', strtotime($session['scheduled_at']))) ?></span>
              <?php if (!empty($session['teacher_name'])): ?>
                <span class="meta-item">👨‍🏫 أ. <?= e($session['teacher_name']) ?></span>
              <?php endif; ?>
            </div>

            <?php if (!empty($session['description'])): ?>
              <p class="session-card-desc"><?= nl2br(e($session['description'])) ?></p>
            <?php endif; ?>

            <div class="session-actions-toolbar">
              <?php if ($data['sessionCanManage']): ?>
                <button class="button button-primary button-small" type="button" data-action="provision">
                  <span>🎥 استوديو وبدء البث المباشر</span>
                </button>
                <button class="button button-outline button-small" type="button" data-action="join">
                  <span>👁️ معاينة المشاهدة (كطالب)</span>
                </button>
              <?php else: ?>
                <button class="button button-primary button-small" type="button" data-action="join">
                  <span>▶ انضمام / مشاهدة البث</span>
                </button>
              <?php endif; ?>

              <button class="button button-secondary button-small" type="button" data-action="recordings">
                <span>📼 عرض التسجيلات</span>
              </button>

              <?php if ($data['sessionCanManage']): ?>
                <div class="manager-session-actions">
                  <button class="button button-small button-outline" type="button" data-action="sync">
                    <span>🔄 مزامنة التسجيلات</span>
                  </button>
                  <?php if (!in_array($session['status'], ['COMPLETED', 'CANCELLED'], true)): ?>
                    <button class="button button-small button-danger" type="button" data-action="complete">
                      <span>⏹ إنهاء الحصة</span>
                    </button>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="session-output" aria-live="polite"></div>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
