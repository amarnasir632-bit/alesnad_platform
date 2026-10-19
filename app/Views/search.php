<section class="section page-section discovery-page" aria-labelledby="discovery-title">
  <header class="discovery-header">
    <span class="eyebrow">اكتشف المحتوى التعليمي</span>
    <h1 id="discovery-title">ابحث عن أستاذ أو درس</h1>
    <p class="page-intro">تظهر النتائج المنشورة والمتاحة لدورك وصفك الدراسي فقط.</p>
    <form class="discovery-search-form" method="get" action="/">
      <input type="hidden" name="page" value="search">
      <label class="visually-hidden" for="discovery-query">اسم الأستاذ أو عنوان الدرس</label>
      <input id="discovery-query" name="q" type="search" maxlength="120" value="<?= e($data['searchQuery']) ?>" placeholder="اكتب اسم أستاذ أو درس..." autocomplete="off">
      <button class="button button-primary" type="submit">بحث</button>
    </form>
  </header>

  <?php if (!$data['canSearchLessons']): ?>
    <div class="notice" role="status">
      <span class="notice-icon" aria-hidden="true">🔒</span>
      <div>
        <strong>عرض الدروس يحتاج إلى صلاحية المحتوى</strong>
        <p>يمكنك البحث عن المعلمين، أما الدروس والوسائط فتظهر بعد منح حساب الطالب صلاحية المحتوى.</p>
      </div>
    </div>
  <?php endif; ?>

  <section class="discovery-section" aria-labelledby="teacher-results-title">
    <div class="section-head">
      <div><span class="eyebrow">المعلمون</span><h2 id="teacher-results-title">نتائج البحث عن أستاذ</h2></div>
      <span class="result-count"><?= count($data['teachers']) ?> نتيجة</span>
    </div>
    <?php if ($data['teachers']): ?>
      <div class="teacher-card-grid">
        <?php foreach ($data['teachers'] as $teacher): ?>
          <a class="teacher-result-card" href="<?= e(url('teacher', ['id' => $teacher['employee_id']])) ?>">
            <span class="teacher-avatar" aria-hidden="true">
              <span class="teacher-avatar-initial"><?= e(mb_substr($teacher['full_name'], 0, 1)) ?></span>
              <?php if (!empty($teacher['profile_image_key'])): ?>
                <img class="teacher-avatar-image" src="/api/v1/teachers/<?= (int) $teacher['employee_id'] ?>/photo" alt="" loading="lazy">
              <?php endif; ?>
            </span>
            <span class="teacher-result-info">
              <strong><?= e($teacher['full_name']) ?></strong>
              <span><?= e($teacher['job_title']) ?></span>
              <?php if (!empty($teacher['subjects'])): ?><small><?= e($teacher['subjects']) ?></small><?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><h3>لا توجد نتائج للمعلمين</h3><p>جرّب اسمًا أو مادة مختلفة.</p></div>
    <?php endif; ?>
  </section>

  <?php if ($data['canSearchLessons']): ?>
    <section class="discovery-section" aria-labelledby="lesson-results-title">
      <div class="section-head">
        <div><span class="eyebrow">المنهج والمحتوى</span><h2 id="lesson-results-title">الدروس المنشورة</h2></div>
        <span class="result-count"><?= count($data['lessons']) ?> نتيجة</span>
      </div>
      <?php if ($data['lessons']): ?>
        <div class="lesson-result-list">
          <?php foreach ($data['lessons'] as $lesson): ?>
            <a class="lesson-result-card" href="<?= e(url('lesson', ['id' => $lesson['id']])) ?>">
              <span class="lesson-result-icon" aria-hidden="true">▶</span>
              <span class="lesson-result-info">
                <strong><?= e($lesson['title']) ?></strong>
                <small><?= e($lesson['stage_name'] . ' · ' . $lesson['class_name'] . ' · ' . $lesson['subject_name']) ?></small>
                <?php if (!empty($lesson['description'])): ?><span><?= e($lesson['description']) ?></span><?php endif; ?>
              </span>
              <?php if ((int) $lesson['media_count'] > 0): ?><span class="media-count"><?= (int) $lesson['media_count'] ?> وسائط</span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state"><h3>لا توجد دروس مطابقة</h3><p>تظهر هنا الدروس المنشورة ضمن النطاق المصرح به لحسابك.</p></div>
      <?php endif; ?>
    </section>

    <div class="media-highlights-grid">
      <section class="discovery-section" aria-labelledby="popular-media-title">
        <div class="section-head"><div><span class="eyebrow">حسب المشاهدات</span><h2 id="popular-media-title">الأكثر مشاهدة</h2></div></div>
        <?php if ($data['popularMedia']): ?>
          <ol class="media-highlight-list">
            <?php foreach ($data['popularMedia'] as $media): ?>
              <li>
                <a href="<?= e(url('lesson', ['id' => $media['lesson_id'], 'resource_id' => $media['resource_id']])) ?>">
                  <strong><?= e($media['resource_title']) ?></strong>
                  <span><?= e($media['lesson_title'] ?: $media['subject_name']) ?> · <?= (int) $media['view_count'] ?> مشاهدة</span>
                </a>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php else: ?>
          <div class="empty-state compact-empty"><p>ستظهر الدروس بعد بدء تشغيلها.</p></div>
        <?php endif; ?>
      </section>

      <section class="discovery-section" aria-labelledby="latest-media-title">
        <div class="section-head"><div><span class="eyebrow">أضيفت مؤخرًا</span><h2 id="latest-media-title">آخر الإضافات</h2></div></div>
        <?php if ($data['latestMedia']): ?>
          <ol class="media-highlight-list">
            <?php foreach ($data['latestMedia'] as $media): ?>
              <li>
                <a href="<?= e(url('lesson', ['id' => $media['lesson_id'], 'resource_id' => $media['resource_id']])) ?>">
                  <strong><?= e($media['resource_title']) ?></strong>
                  <span><?= e($media['lesson_title'] ?: $media['subject_name']) ?> · <?= e(date('Y-m-d', strtotime($media['published_at']))) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php else: ?>
          <div class="empty-state compact-empty"><p>لا توجد إضافات جديدة بعد.</p></div>
        <?php endif; ?>
      </section>
    </div>
  <?php endif; ?>
</section>
