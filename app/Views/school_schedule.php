<?php
$days = school_schedule_days();
$assignments = $data['schedule_assignments'] ?? [];
$seenClassSubjects = [];
$weeklyEntries = array_values(array_filter(
    $data['school_schedule_entries'] ?? [],
    static fn(array $entry): bool => $entry['recurrence_type'] === 'WEEKLY'
));
$oneTimeEntries = array_values(array_filter(
    $data['school_schedule_entries'] ?? [],
    static fn(array $entry): bool => $entry['recurrence_type'] === 'ONCE'
));
$weeklyGrid = school_schedule_weekly_grid($weeklyEntries);
$lessonPeriod = 0;
?>
<section class="section page-section school-schedule-page">
  <div class="section-head">
    <div>
      <span class="eyebrow">التقويم المدرسي</span>
      <h1 class="page-title">الجدول الدراسي والإجازات</h1>
    </div>
    <?php if (!empty($data['canManageSchedule'])): ?>
      <a class="button button-secondary button-small" href="<?= e(url('home')) ?>">العودة للرئيسية</a>
    <?php endif; ?>
  </div>
  <p class="page-intro">مواعيد الحصص والفسح والإجازات بحسب صفك، مع إظهار الإعلانات المدرسية السارية.</p>

  <?php if (!empty($data['canManageSchedule'])): ?>
    <section class="schedule-admin-grid" aria-label="إدارة التقويم المدرسي">
      <form class="detail-card admin-form" method="post" action="<?= e(url('school_schedule')) ?>" id="school-schedule-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="school_schedule_create">
        <h2>إضافة حصة أو فسحة</h2>
        <div class="form-field">
          <label for="schedule-entry-type">نوع الموعد</label>
          <select id="schedule-entry-type" name="entry_type" required>
            <option value="LESSON">حصة دراسية</option>
            <option value="BREAK">فسحة أو نشاط (صلاة، إفطار، وغيرها)</option>
          </select>
        </div>
        <div class="form-field" id="schedule-class-field">
          <label for="schedule-class">الصف</label>
          <select id="schedule-class" name="class_id">
            <option value="">كل الصفوف (للفسحة العامة)</option>
            <?php foreach ($data['schedule_classes'] as $class): ?>
              <option value="<?= (int) $class['id'] ?>"><?= e($class['stage_name'] . ' · ' . $class['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-field" id="schedule-subject-field">
          <label for="schedule-subject">المادة والصف</label>
          <select id="schedule-subject" name="class_subject_id" required>
            <option value="">اختر الصف أولًا</option>
            <?php foreach ($assignments as $assignment): ?>
              <?php if (isset($seenClassSubjects[$assignment['class_subject_id']])) continue; ?>
              <?php $seenClassSubjects[$assignment['class_subject_id']] = true; ?>
              <option value="<?= (int) $assignment['class_subject_id'] ?>" data-class-id="<?= (int) $assignment['class_id'] ?>">
                <?= e($assignment['class_name'] . ' · ' . $assignment['subject_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="field-hint">تظهر المواد التي لها أستاذ مسند إليها من صفحة إدارة الفريق.</small>
        </div>
        <div class="form-field" id="schedule-teacher-field">
          <label for="schedule-teacher">الأستاذ المسند للمادة</label>
          <select id="schedule-teacher" name="teacher_id" required>
            <option value="">اختر المادة أولًا</option>
            <?php foreach ($assignments as $assignment): ?>
              <option value="<?= (int) $assignment['teacher_id'] ?>"
                      data-class-subject-id="<?= (int) $assignment['class_subject_id'] ?>">
                <?= e($assignment['teacher_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-field" id="schedule-break-title-field" hidden>
          <label for="schedule-break-title">اسم الفسحة أو النشاط</label>
          <input id="schedule-break-title" name="title" maxlength="180" placeholder="مثل: صلاة الظهر أو الإفطار">
        </div>
        <div class="form-field">
          <label for="schedule-recurrence">التكرار</label>
          <select id="schedule-recurrence" name="recurrence_type" required>
            <option value="WEEKLY">موعد أسبوعي متكرر</option>
            <option value="ONCE">موعد بتاريخ واحد</option>
          </select>
        </div>
        <div class="form-field" id="schedule-day-field">
          <label for="schedule-day">اليوم</label>
          <select id="schedule-day" name="day_of_week" required>
            <?php foreach ($days as $day => $label): ?>
              <option value="<?= $day ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-field" id="schedule-date-field" hidden>
          <label for="schedule-date">تاريخ الحصة</label>
          <input id="schedule-date" name="occurrence_date" type="date" disabled>
        </div>
        <div class="form-field">
          <label for="schedule-start">من الساعة</label>
          <input id="schedule-start" name="starts_at" type="time" required>
        </div>
        <div class="form-field">
          <label for="schedule-end">إلى الساعة</label>
          <input id="schedule-end" name="ends_at" type="time" required>
        </div>
        <button class="button button-primary button-large" type="submit">إضافة إلى الجدول</button>
      </form>

      <form class="detail-card admin-form" method="post" action="<?= e(url('school_schedule')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="school_holiday_create">
        <h2>إضافة إجازة وإعلانها</h2>
        <p class="form-desc">تظهر الإجازة تلقائيًا في الصفحة الرئيسية وإعلانات التقويم حتى نهاية مدتها.</p>
        <div class="form-field">
          <label for="holiday-title">اسم الإجازة</label>
          <input id="holiday-title" name="title" maxlength="180" required placeholder="مثل: إجازة عيد الفطر">
        </div>
        <div class="form-field">
          <label for="holiday-class">نطاق الإجازة</label>
          <select id="holiday-class" name="class_id">
            <option value="">إجازة عامة لكل المنصة</option>
            <?php foreach ($data['schedule_classes'] as $class): ?>
              <option value="<?= (int) $class['id'] ?>"><?= e($class['stage_name'] . ' · ' . $class['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-field">
          <label for="holiday-start">تاريخ بداية الإجازة</label>
          <input id="holiday-start" name="starts_on" type="date" required>
        </div>
        <div class="form-field">
          <label for="holiday-end">تاريخ نهاية الإجازة</label>
          <input id="holiday-end" name="ends_on" type="date" required>
        </div>
        <div class="form-field">
          <label for="holiday-description">تفاصيل الإعلان (اختياري)</label>
          <textarea id="holiday-description" name="description" rows="3" maxlength="2000"></textarea>
        </div>
        <button class="button button-primary button-large" type="submit">نشر الإجازة والإعلان</button>
      </form>
    </section>
  <?php endif; ?>

  <section class="schedule-list-section" aria-labelledby="weekly-schedule-title">
    <div class="section-head">
      <div>
        <span class="eyebrow">الأسبوع الدراسي</span>
        <h2 id="weekly-schedule-title">الجدول الأسبوعي</h2>
      </div>
    </div>
    <?php if (empty($weeklyGrid['slots'])): ?>
      <div class="empty-state"><h3>لا يوجد جدول أسبوعي بعد</h3><p>ستظهر أيام الدراسة والحصص هنا عند إضافتها من إدارة التقويم.</p></div>
    <?php else: ?>
      <div class="schedule-timetable-scroll" role="region" aria-label="الجدول الأسبوعي؛ اسحب أفقيًا لرؤية بقية الأيام" tabindex="0">
        <table class="schedule-timetable">
          <caption class="visually-hidden">مواعيد الحصص والفسح موزعة حسب أيام الأسبوع</caption>
          <thead>
            <tr>
              <th class="schedule-timetable-time-heading" scope="col">الحصة والوقت</th>
              <?php foreach ($weeklyGrid['days'] as $dayName): ?>
                <th scope="col"><?= e($dayName) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($weeklyGrid['slots'] as $slot): ?>
              <?php
                $slotHasLesson = false;
                foreach ($slot['entries_by_day'] as $dayEntries) {
                    foreach ($dayEntries as $entry) {
                        if ($entry['entry_type'] === 'LESSON') {
                            $slotHasLesson = true;
                            break 2;
                        }
                    }
                }
                if ($slotHasLesson) $lessonPeriod++;
              ?>
              <tr>
                <th class="schedule-timetable-time" scope="row">
                  <span><?= $slotHasLesson ? 'الحصة ' . $lessonPeriod : 'استراحة' ?></span>
                  <time dir="ltr"><?= e($slot['starts_at']) ?>–<?= e($slot['ends_at']) ?></time>
                </th>
                <?php foreach ($weeklyGrid['days'] as $day => $dayName): ?>
                  <?php $cellEntries = $slot['entries_by_day'][$day] ?? []; ?>
                  <td class="<?= $cellEntries && !$slotHasLesson ? 'is-break' : '' ?>">
                    <?php if (!$cellEntries): ?>
                      <span class="schedule-timetable-empty" aria-label="لا توجد حصة">—</span>
                    <?php else: ?>
                      <ul class="schedule-timetable-cell-list">
                        <?php foreach ($cellEntries as $entry): ?>
                          <?php $isLesson = $entry['entry_type'] === 'LESSON'; ?>
                          <li class="<?= $isLesson ? 'is-lesson' : 'is-break' ?>">
                            <strong><?= e($isLesson ? ($entry['subject_name'] ?? $entry['title']) : $entry['title']) ?></strong>
                            <?php if ($isLesson && !empty($entry['teacher_name'])): ?>
                              <span><?= e($entry['teacher_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($data['canManageSchedule']) && $entry['class_name'] !== null): ?>
                              <small><?= e(($entry['stage_name'] ?? '') . ' · ' . $entry['class_name']) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($data['canManageSchedule'])): ?>
                              <form method="post" action="<?= e(url('school_schedule')) ?>" class="schedule-cell-delete-form" data-confirm="هل تريد حذف هذا الموعد من الجدول؟">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="school_schedule_delete">
                                <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                                <button class="button button-small button-danger" type="submit" aria-label="حذف <?= e($isLesson ? ($entry['subject_name'] ?? $entry['title']) : $entry['title']) ?>">حذف</button>
                              </form>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($oneTimeEntries): ?>
    <section class="schedule-list-section" aria-labelledby="one-time-schedule-title">
      <div class="section-head">
        <div>
          <span class="eyebrow">مواعيد بتاريخ محدد</span>
          <h2 id="one-time-schedule-title">حصص وفسح غير متكررة</h2>
        </div>
      </div>
      <div class="schedule-entries-list">
        <?php foreach ($oneTimeEntries as $entry): ?>
          <?php $isLesson = $entry['entry_type'] === 'LESSON'; ?>
          <article class="schedule-entry-card <?= $isLesson ? 'is-lesson' : 'is-break' ?>">
            <div class="schedule-entry-time">
              <strong><?= e(substr((string) $entry['starts_at'], 0, 5)) ?>–<?= e(substr((string) $entry['ends_at'], 0, 5)) ?></strong>
              <span><?= e(date('Y-m-d', strtotime($entry['occurrence_date']))) ?></span>
            </div>
            <div class="schedule-entry-details">
              <h3><?= e($isLesson ? ($entry['subject_name'] ?? $entry['title']) : $entry['title']) ?></h3>
              <p>
                <?= e($entry['class_name'] === null ? 'كل الصفوف' : (($entry['stage_name'] ?? '') . ' · ' . $entry['class_name'])) ?>
                <?php if ($isLesson && !empty($entry['teacher_name'])): ?> · الأستاذ <?= e($entry['teacher_name']) ?><?php endif; ?>
              </p>
            </div>
            <?php if (!empty($data['canManageSchedule'])): ?>
              <form method="post" action="<?= e(url('school_schedule')) ?>" class="inline-form" data-confirm="هل تريد حذف هذا الموعد من الجدول؟">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="school_schedule_delete">
                <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                <button class="button button-small button-danger" type="submit">حذف</button>
              </form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="schedule-list-section" id="calendar-announcements">
    <div class="section-head">
      <div>
        <span class="eyebrow">إعلانات التقويم</span>
        <h2>الإجازات المعلنة</h2>
      </div>
    </div>
    <?php if (empty($data['school_holidays'])): ?>
      <div class="empty-state"><h3>لا توجد إجازات معلنة</h3><p>سيظهر أي إعلان إجازة موجه لصفك أو لجميع المنصة هنا.</p></div>
    <?php else: ?>
      <div class="holiday-announcements-list">
        <?php foreach ($data['school_holidays'] as $holiday): ?>
          <article class="holiday-announcement-card">
            <span class="status-chip chip-scheduled">إجازة معلنة</span>
            <h3><?= e($holiday['title']) ?></h3>
            <p><?= e(date('Y-m-d', strtotime($holiday['starts_on']))) ?> — <?= e(date('Y-m-d', strtotime($holiday['ends_on']))) ?></p>
            <?php if ($holiday['class_id'] === null): ?>
              <span class="scope-tag scope-general">عامة لكل المنصة</span>
            <?php else: ?>
              <span class="scope-tag">موجهة إلى <?= e(($holiday['stage_name'] ?? '') . ' · ' . ($holiday['class_name'] ?? 'الصف')) ?></span>
            <?php endif; ?>
            <?php if ($holiday['description'] !== ''): ?><p><?= nl2br(e($holiday['description'])) ?></p><?php endif; ?>
            <?php if (!empty($data['canManageSchedule'])): ?>
              <form method="post" action="<?= e(url('school_schedule')) ?>" class="inline-form" data-confirm="سيُحذف إعلان الإجازة من الموقع. هل تريد المتابعة؟">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="school_holiday_delete">
                <input type="hidden" name="holiday_id" value="<?= (int) $holiday['id'] ?>">
                <button class="button button-small button-danger" type="submit">حذف الإجازة والإعلان</button>
              </form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</section>
