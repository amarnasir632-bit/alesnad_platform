<section class="section page-section teacher-profile-page" aria-labelledby="teacher-profile-title">
  <a class="button button-small button-secondary" href="<?= e(url('search')) ?>">العودة إلى البحث</a>
  <header class="teacher-profile-header detail-card">
    <span class="teacher-avatar teacher-avatar-large" aria-hidden="true">
      <span class="teacher-avatar-initial"><?= e(mb_substr($data['teacher']['full_name'], 0, 1)) ?></span>
      <?php if (!empty($data['teacher']['profile_image_key'])): ?>
        <img class="teacher-avatar-image" src="/api/v1/teachers/<?= (int) $data['teacher']['employee_id'] ?>/photo" alt="" loading="lazy">
      <?php endif; ?>
    </span>
    <div>
      <span class="eyebrow">ملف المعلم</span>
      <h1 id="teacher-profile-title"><?= e($data['teacher']['full_name']) ?></h1>
      <p><?= e($data['teacher']['job_title']) ?></p>
      <?php if (!empty($data['teacher']['subjects'])): ?><small><?= e($data['teacher']['subjects']) ?></small><?php endif; ?>
    </div>
  </header>

  <section class="discovery-section" aria-labelledby="teacher-lessons-title">
    <div class="section-head"><div><span class="eyebrow">المحتوى المتاح</span><h2 id="teacher-lessons-title">دروس هذا المعلم</h2></div></div>
    <?php if ($data['teacherLessons']): ?>
      <div class="lesson-result-list">
        <?php foreach ($data['teacherLessons'] as $lesson): ?>
          <a class="lesson-result-card" href="<?= e(url('lesson', ['id' => $lesson['id']])) ?>">
            <span class="lesson-result-icon" aria-hidden="true">▶</span>
            <span class="lesson-result-info">
              <strong><?= e($lesson['title']) ?></strong>
              <small><?= e($lesson['class_name'] . ' · ' . $lesson['subject_name']) ?></small>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><p>لا توجد دروس منشورة متاحة لهذا المعلم ضمن صلاحيات حسابك.</p></div>
    <?php endif; ?>
  </section>
</section>
