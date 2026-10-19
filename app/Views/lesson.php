<?php
$lesson = $data['lesson'];
$media = $data['activeMedia'];
$supplementary = array_values(array_filter(
    $lesson['resources'],
    static fn(array $resource): bool => !in_array($resource['resource_type'], ['VIDEO', 'AUDIO'], true)
));
?>
<section class="section page-section lesson-page" aria-labelledby="lesson-title">
  <nav class="lesson-breadcrumbs" aria-label="مسار التنقل">
    <a href="<?= e(url('search')) ?>">البحث</a><span aria-hidden="true">‹</span>
    <a href="<?= e(url('subjects')) ?>"><?= e($lesson['subject_name']) ?></a><span aria-hidden="true">‹</span>
    <span><?= e($lesson['title']) ?></span>
  </nav>

  <header class="lesson-page-header">
    <span class="eyebrow"><?= e($lesson['stage_name'] . ' · ' . $lesson['class_name'] . ' · ' . $lesson['subject_name']) ?></span>
    <h1 id="lesson-title"><?= e($lesson['title']) ?></h1>
    <?php if (!empty($lesson['description'])): ?><p class="page-intro"><?= e($lesson['description']) ?></p><?php endif; ?>
  </header>

  <div class="lesson-player-layout">
    <main class="lesson-main-column">
      <?php if ($media): ?>
        <section class="learning-player" aria-label="مشغل <?= $media['resource_type'] === 'VIDEO' ? 'الفيديو' : 'الصوت' ?>">
          <?php if ($media['resource_type'] === 'VIDEO'): ?>
            <div class="learning-video-frame">
              <iframe title="<?= e($media['title']) ?>" src="about:blank" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="no-referrer" data-media-player hidden></iframe>
              <button class="learning-video-start" type="button" data-media-video-start
                      data-src="/api/v1/content/resources/<?= (int) $media['id'] ?>/playback"
                      data-resource-id="<?= (int) $media['id'] ?>">
                <span aria-hidden="true">▶</span><strong>تشغيل الدرس</strong>
              </button>
              <noscript><p>يلزم تفعيل JavaScript لتشغيل الفيديو داخل الصفحة.</p></noscript>
            </div>
          <?php else: ?>
            <div class="learning-audio-player">
              <span class="audio-player-icon" aria-hidden="true">♫</span>
              <div><strong><?= e($media['title']) ?></strong><span>درس صوتي</span></div>
              <audio controls preload="none" src="/api/v1/content/resources/<?= (int) $media['id'] ?>/playback"
                     data-media-audio data-resource-id="<?= (int) $media['id'] ?>">
                لا يدعم متصفحك تشغيل الصوت المضمن.
              </audio>
            </div>
          <?php endif; ?>
          <p class="visually-hidden" role="status" aria-live="polite" data-view-status></p>
          <div class="learning-player-caption">
            <strong><?= e($media['title']) ?></strong>
            <span><?= (int) $media['view_count'] ?> مشاهدة</span>
          </div>
        </section>
      <?php else: ?>
        <div class="learning-player-empty">
          <span class="eyebrow">محتوى الدرس</span>
          <h2>لا يوجد فيديو أو تسجيل صوتي لهذا الدرس بعد</h2>
          <p>يمكنك متابعة شرح الدرس والمواد المساعدة المتاحة أدناه.</p>
        </div>
      <?php endif; ?>

      <section class="detail-card lesson-notes" aria-labelledby="lesson-notes-title">
        <div class="section-head">
          <div><span class="eyebrow">شرح الدرس</span><h2 id="lesson-notes-title">الملاحظات والمحتوى</h2></div>
          <?php if ($lesson['teachers']): ?>
            <div class="lesson-teacher-inline">
              <?php foreach ($lesson['teachers'] as $teacher): ?>
                <a href="<?= e(url('teacher', ['id' => $teacher['employee_id']])) ?>">
                  <span class="teacher-avatar teacher-avatar-small" aria-hidden="true">
                    <span class="teacher-avatar-initial"><?= e(mb_substr($teacher['full_name'], 0, 1)) ?></span>
                    <?php if (!empty($teacher['profile_image_key'])): ?>
                      <img class="teacher-avatar-image" src="/api/v1/teachers/<?= (int) $teacher['employee_id'] ?>/photo" alt="" loading="lazy">
                    <?php endif; ?>
                  </span>
                  <span><?= e($teacher['full_name']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php if (!empty($lesson['body'])): ?>
          <div class="lesson-content-text"><?= nl2br(e($lesson['body'])) ?></div>
        <?php else: ?>
          <p class="muted">لا توجد ملاحظات نصية مضافة لهذا الدرس.</p>
        <?php endif; ?>
      </section>

      <section class="detail-card lesson-resources" aria-labelledby="lesson-resources-title">
        <div class="section-head"><div><span class="eyebrow">للتوسع والمراجعة</span><h2 id="lesson-resources-title">الكتب والمواد المساعدة</h2></div></div>
        <?php if ($supplementary): ?>
          <div class="supplementary-resource-list">
            <?php foreach ($supplementary as $resource): ?>
              <article class="supplementary-resource-card">
                <span class="supplementary-resource-icon" aria-hidden="true"><?= $resource['resource_type'] === 'PDF' ? '▤' : '↗' ?></span>
                <div><strong><?= e($resource['title']) ?></strong><?php if (!empty($resource['description'])): ?><p><?= e($resource['description']) ?></p><?php endif; ?></div>
                <?php if ($resource['resource_type'] === 'LINK' && !empty($resource['external_url'])): ?>
                  <a class="button button-small button-secondary" href="<?= e($resource['external_url']) ?>" target="_blank" rel="noopener noreferrer">فتح المورد</a>
                <?php else: ?>
                  <a class="button button-small button-secondary" href="/api/v1/content/resources/<?= (int) $resource['id'] ?>/download">فتح الملف</a>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-state compact-empty"><p>لا توجد كتب أو ملفات مساعدة مرتبطة بهذا الدرس بعد.</p></div>
        <?php endif; ?>
        <?php if (user_has_permission('content.upload')): ?>
          <a class="button button-small button-secondary lesson-upload-link" href="<?= e(url('library')) ?>">إضافة مادة مساعدة</a>
        <?php endif; ?>
      </section>

      <section class="detail-card lesson-youtube-resources" aria-labelledby="youtube-resources-title">
        <div class="section-head"><div><span class="eyebrow">روابط خارجية</span><h2 id="youtube-resources-title">فيديوهات موصى بها على YouTube</h2></div></div>
        <?php if ($lesson['youtube_recommendations']): ?>
          <ul class="youtube-recommendation-list">
            <?php foreach ($lesson['youtube_recommendations'] as $recommendation): ?>
              <li><a href="<?= e($recommendation['youtube_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($recommendation['title']) ?><span aria-hidden="true">↗</span></a></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-state compact-empty"><p>لا توجد توصيات مضافة لهذا الدرس.</p></div>
        <?php endif; ?>
        <p class="field-hint">تفتح الروابط موقع YouTube عند اختيارها؛ لا يتم تشغيل محتوى خارجي أو تحميل أدوات تتبع داخل الصفحة.</p>
        <?php if ($data['canAddYoutubeRecommendations']): ?>
          <details class="lesson-youtube-add">
            <summary class="button button-small button-secondary">إضافة رابط YouTube</summary>
            <form method="post" action="<?= e(url('lesson', ['id' => $lesson['id']])) ?>" class="lesson-recommendation-form">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="lesson_youtube_recommendation_create">
              <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
              <div class="form-field"><label for="youtube_title">عنوان التوصية</label><input id="youtube_title" name="title" maxlength="200" required></div>
              <div class="form-field"><label for="youtube_url">رابط فيديو YouTube</label><input id="youtube_url" name="youtube_url" type="url" inputmode="url" placeholder="https://youtu.be/..." maxlength="2048" required></div>
              <button class="button button-primary" type="submit">حفظ التوصية</button>
            </form>
          </details>
        <?php endif; ?>
      </section>
    </main>

    <aside class="lesson-media-sidebar" aria-labelledby="lesson-media-list-title">
      <div class="detail-card">
        <h2 id="lesson-media-list-title">محتوى الدرس</h2>
        <?php $lessonMedia = array_values(array_filter($lesson['resources'], static fn(array $resource): bool => in_array($resource['resource_type'], ['VIDEO', 'AUDIO'], true))); ?>
        <?php if ($lessonMedia): ?>
          <ol class="lesson-media-list">
            <?php foreach ($lessonMedia as $resource): ?>
              <li>
                <a class="<?= $media && (int) $media['id'] === (int) $resource['id'] ? 'active' : '' ?>"
                   href="<?= e(url('lesson', ['id' => $lesson['id'], 'resource_id' => $resource['id']])) ?>">
                  <span class="lesson-media-type" aria-hidden="true"><?= $resource['resource_type'] === 'VIDEO' ? '▶' : '♫' ?></span>
                  <span><strong><?= e($resource['title']) ?></strong><small><?= $resource['resource_type'] === 'VIDEO' ? 'فيديو' : 'صوت' ?></small></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php else: ?>
          <p class="muted">لا توجد وسائط لهذا الدرس بعد.</p>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</section>
