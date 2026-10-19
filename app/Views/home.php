<?php
$user = current_user();
$isAdmin = $user && user_has_role('admin');
$isReviewer = $user && user_has_role('content_reviewer') && !$isAdmin;
$isTeacher = $user && user_has_role('teacher') && !$isAdmin && !$isReviewer;
$isStudent = $user && user_has_role('student') && !$isAdmin && !$isReviewer && !$isTeacher;
$announcements = $data['published_announcements'] ?? [];
$topAnnouncement = $announcements[0] ?? null;

$adminActions = [];
if ($isAdmin && user_has_permission('academics.manage')) {
    $adminActions[] = ['icon' => 'book', 'title' => 'المنهج والمواد', 'description' => 'إدارة المراحل والصفوف والمواد الدراسية.', 'label' => 'إدارة المنهج', 'page' => 'academics'];
}
if (user_has_permission('content_access.manage')) {
    $adminActions[] = ['icon' => 'shield', 'title' => 'صلاحيات وصول الطلاب', 'description' => 'منح أهلية المحتوى أو سحبها ومتابعة حالتها.', 'label' => 'إدارة الصلاحيات', 'page' => 'content_access'];
}
if ($isAdmin && user_has_permission('users.manage')) {
    $adminActions[] = ['icon' => 'users', 'title' => 'فريق العمل', 'description' => 'إدارة دعوات أعضاء الفريق وإسناد المواد.', 'label' => 'إدارة الفريق', 'page' => 'staff'];
}
if ($isAdmin && user_has_permission('roles.manage')) {
    $adminActions[] = ['icon' => 'shield', 'title' => 'الحسابات والأدوار', 'description' => 'إدارة الحسابات وصلاحيات الأدوار.', 'label' => 'إدارة الحسابات', 'page' => 'accounts'];
}
if (($isAdmin || $isReviewer) && user_has_permission('content.review')) {
    $adminActions[] = ['icon' => 'library', 'title' => 'مراجعة المكتبة', 'description' => 'مراجعة موارد المكتبة واعتماد المحتوى.', 'label' => 'فتح المكتبة', 'page' => 'library'];
}
if (($isAdmin || $isReviewer) && user_has_permission('questions.manage')) {
    $adminActions[] = ['icon' => 'question', 'title' => 'بنك الأسئلة', 'description' => 'مراجعة الأسئلة وإدارة ما تسمح به صلاحياتك.', 'label' => 'فتح بنك الأسئلة', 'page' => 'questions'];
}
if ($isAdmin && user_has_permission('quizzes.manage')) {
    $adminActions[] = ['icon' => 'clipboard', 'title' => 'الاختبارات', 'description' => 'متابعة الاختبارات وإدارتها.', 'label' => 'إدارة الاختبارات', 'page' => 'quizzes'];
}
if ($isAdmin && user_has_permission('sessions.manage')) {
    $adminActions[] = ['icon' => 'video', 'title' => 'الحصص', 'description' => 'متابعة الحصص والتسجيلات.', 'label' => 'إدارة الحصص', 'page' => 'sessions'];
}
if ($isAdmin) {
    $adminActions[] = ['icon' => 'message', 'title' => 'الرسائل الأكاديمية', 'description' => 'الوصول إلى أدوات متابعة الرسائل المتاحة للمدير.', 'label' => 'متابعة الرسائل', 'page' => 'messages'];
}
if ($isAdmin && user_has_permission('users.manage')) {
    $adminActions[] = ['icon' => 'message', 'title' => 'الإعلانات', 'description' => 'إدارة الإعلانات المنشورة للطلاب.', 'label' => 'إدارة الإعلانات', 'page' => 'announcements'];
}

$studentUpcomingSessions = [];
foreach ($data['sessions'] ?? [] as $session) {
    $availability = sm_session_availability($session);
    if (in_array($availability['state'], ['upcoming', 'live', 'joinable'], true)) {
        $session['availability'] = $availability;
        $studentUpcomingSessions[] = $session;
        if (count($studentUpcomingSessions) === 3) break;
    }
}
?>

<?php if ($topAnnouncement): ?>
  <section class="announcements-banner" aria-label="إعلان منشور">
    <div class="announcement-content">
      <div class="announcement-badge-wrap">
        <span class="announcement-pulse-dot" aria-hidden="true"></span>
        <span class="announcement-tag"><?= e($topAnnouncement['badge_label'] ?: 'إعلان المنصة') ?></span>
        <span class="announcement-date"><?= e(date('Y-m-d', strtotime($topAnnouncement['created_at']))) ?></span>
        <?php if ($topAnnouncement['priority'] === 'URGENT'): ?>
          <span class="status-pill status-pill-danger">تنبيه عاجل</span>
        <?php endif; ?>
      </div>
      <div class="announcement-body">
        <h2 class="announcement-title"><?= e($topAnnouncement['title']) ?></h2>
        <p class="announcement-desc"><?= nl2br(e($topAnnouncement['content'])) ?></p>
      </div>
    </div>
    <div class="announcement-actions">
      <?php if ($isAdmin || $isTeacher): ?>
        <a href="<?= e(url('announcements')) ?>" class="button button-small button-outline">إدارة الإعلانات</a>
      <?php endif; ?>
      <?php if (user_has_permission('sessions.manage') || user_has_permission('sessions.join')): ?>
        <a href="<?= e(url('sessions')) ?>" class="button button-small button-primary">جدول الحصص</a>
      <?php endif; ?>
      <a href="<?= e(url('subjects')) ?>" class="button button-small button-secondary">المواد الدراسية</a>
    </div>
  </section>
<?php endif; ?>

<?php if ($isAdmin || $isReviewer): ?>
  <section class="dashboard-panel admin-dashboard">
    <div class="dashboard-welcome">
      <div class="welcome-text">
        <span class="eyebrow"><?= $isAdmin ? 'مساحة إدارة المنصة' : 'مساحة مراجعة المحتوى' ?></span>
        <h1>مرحبًا، <?= e($user['full_name']) ?></h1>
        <p class="dashboard-intro"><?= $isAdmin ? 'اختر المهمة الإدارية التي تريد إنجازها.' : 'تابع مراجعة المحتوى وإدارة الأذونات الممنوحة لدورك.' ?></p>
      </div>
    </div>

    <?php if ($adminActions): ?>
      <div class="admin-quick-actions-grid">
        <?php foreach ($adminActions as $action): ?>
          <article class="quick-action-card">
            <div class="action-icon" aria-hidden="true"><?= ui_icon($action['icon']) ?></div>
            <div class="action-info">
              <h2><?= e($action['title']) ?></h2>
              <p><?= e($action['description']) ?></p>
              <a class="button button-small button-secondary" href="<?= e(url($action['page'])) ?>"><?= e($action['label']) ?></a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <h2>لا توجد مهام متاحة لهذا الدور</h2>
        <p>لا تعرض هذه الصفحة أدوات لا يملك حسابك صلاحية استخدامها.</p>
      </div>
    <?php endif; ?>
  </section>

<?php elseif ($isTeacher): ?>
  <section class="dashboard-panel teacher-dashboard">
    <div class="dashboard-welcome">
      <div class="welcome-text">
        <span class="eyebrow">مساحة عمل الأستاذ</span>
        <h1>مرحبًا، <?= e($user['full_name']) ?></h1>
        <p class="dashboard-intro">موادك المسندة والحصص والمهام التعليمية المتاحة لحسابك.</p>
      </div>
      <div class="welcome-actions">
        <?php if (user_has_permission('questions.manage')): ?>
          <a class="button button-primary" href="<?= e(url('questions')) ?>">إدارة بنك الأسئلة</a>
        <?php endif; ?>
        <?php if (user_has_permission('sessions.manage')): ?>
          <a class="button button-secondary" href="<?= e(url('sessions')) ?>">جدول الحصص</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="teacher-tasks-grid">
      <?php if (user_has_permission('content.read')): ?>
        <article class="task-card">
          <div class="task-icon" aria-hidden="true"><?= ui_icon('book') ?></div>
          <h2>موادي المسندة</h2>
          <?php if (!empty($data['teacher_assignments'])): ?>
            <ul class="dashboard-link-list">
              <?php foreach (array_slice($data['teacher_assignments'], 0, 5) as $assignment): ?>
                <li>
                  <a href="<?= e(url('subject', ['id' => $assignment['class_subject_id']])) ?>">
                    <?= e($assignment['class_name'] . ' · ' . $assignment['subject_name']) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p>لا توجد مواد مسندة نشطة حاليًا.</p>
          <?php endif; ?>
          <a class="button button-small button-secondary" href="<?= e(url('subjects')) ?>">عرض المواد</a>
        </article>
      <?php endif; ?>

      <?php if (user_has_permission('quizzes.manage')): ?>
        <article class="task-card">
          <div class="task-icon" aria-hidden="true"><?= ui_icon('clipboard') ?></div>
          <h2>الاختبارات</h2>
          <p>إنشاء الاختبارات المسندة إليك ومتابعة التصحيح.</p>
          <a class="button button-small button-secondary" href="<?= e(url('quizzes')) ?>">متابعة الاختبارات</a>
        </article>
      <?php endif; ?>

      <?php if (user_has_permission('content.upload')): ?>
        <article class="task-card">
          <div class="task-icon" aria-hidden="true"><?= ui_icon('library') ?></div>
          <h2>المكتبة والموارد</h2>
          <p>إدارة الموارد التعليمية ضمن مسار المراجعة المعتمد.</p>
          <a class="button button-small button-secondary" href="<?= e(url('library')) ?>">إدارة المكتبة</a>
        </article>
      <?php endif; ?>

      <?php if (user_has_permission('sessions.manage')): ?>
        <article class="task-card">
          <div class="task-icon" aria-hidden="true"><?= ui_icon('video') ?></div>
          <h2>الحصص المباشرة</h2>
          <?php if (!empty($data['sessions'])): ?>
            <p>جدول الحصص المرتبطة بموادك المسندة متاح للمراجعة.</p>
          <?php else: ?>
            <p>لا توجد حصص ضمن موادك حاليًا.</p>
          <?php endif; ?>
          <a class="button button-small button-secondary" href="<?= e(url('sessions')) ?>">عرض الحصص</a>
        </article>
      <?php endif; ?>

      <?php if (user_has_permission('messages.use')): ?>
        <article class="task-card">
          <div class="task-icon" aria-hidden="true"><?= ui_icon('message') ?></div>
          <h2>الرسائل</h2>
          <p>الوصول إلى الرسائل الفردية المتاحة لحسابك.</p>
          <a class="button button-small button-secondary" href="<?= e(url('messages')) ?>">فتح الرسائل</a>
        </article>
      <?php endif; ?>
    </div>
  </section>

<?php elseif ($isStudent): ?>
  <section class="dashboard-panel student-dashboard">
    <div class="student-welcome-banner">
      <div class="student-welcome-text">
        <span class="eyebrow">مساحتك التعليمية</span>
        <h1>مرحبًا، <?= e($user['full_name']) ?></h1>
        <p class="student-prompt">تابع حصصك القادمة وافتح المواد المقررة لصفك.</p>
      </div>
      <div class="student-quick-start">
        <a class="button button-primary button-large" href="<?= e(url('subjects')) ?>">موادي الدراسية</a>
        <?php if (user_has_permission('sessions.join')): ?>
          <a class="button button-secondary" href="<?= e(url('sessions')) ?>">جدول الحصص</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if (user_has_permission('sessions.join')): ?>
      <section class="student-dashboard-section" aria-labelledby="student-upcoming-title">
        <div class="section-head">
          <div>
            <span class="eyebrow">الجدول الدراسي</span>
            <h2 id="student-upcoming-title">حصصك القادمة</h2>
          </div>
          <a class="link-more" href="<?= e(url('sessions')) ?>">عرض كل الحصص</a>
        </div>
        <?php if ($studentUpcomingSessions): ?>
          <div id="session-list" class="student-upcoming-list">
            <?php foreach ($studentUpcomingSessions as $session): ?>
              <?php $canJoin = $session['availability']['can_join']; ?>
              <article class="student-upcoming-card" data-session-id="<?= (int) $session['id'] ?>">
                <div>
                  <span class="course-tag"><?= e($session['subject_name'] ?? 'مادة دراسية') ?></span>
                  <h3><?= e($session['title']) ?></h3>
                  <p><?= e(date('Y-m-d · H:i', strtotime($session['scheduled_at']))) ?><?php if (!empty($session['duration_minutes'])): ?> · <?= (int) $session['duration_minutes'] ?> دقيقة<?php endif; ?></p>
                </div>
                <?php if ($canJoin): ?>
                  <button class="button button-primary button-small" type="button" data-action="join">انضم الآن</button>
                <?php else: ?>
                  <span class="status-chip chip-scheduled">قادمة</span>
                <?php endif; ?>
                <div class="session-output" aria-live="polite"></div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <h3>لا توجد حصص قادمة</h3>
            <p>ستظهر الحصص المرتبطة بصفك هنا عند جدولتها، إذا كانت صلاحية المحتوى متاحة لحسابك.</p>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section id="student-subjects" class="student-dashboard-section" aria-labelledby="student-subjects-title">
      <div class="section-head">
        <div>
          <span class="eyebrow">المقررات الدراسية</span>
          <h2 id="student-subjects-title">موادي الدراسية</h2>
        </div>
        <a class="link-more" href="<?= e(url('subjects')) ?>">عرض كل المواد</a>
      </div>
      <?php if (!empty($data['subjects'])): ?>
        <div class="course-grid">
          <?php foreach ($data['subjects'] as $subject): ?>
            <?php $cover = get_subject_cover_data($subject['subject_name']); ?>
            <article class="course-card">
              <div class="subject-card-cover cover-theme-<?= e($cover['theme']) ?>">
                <span class="cover-symbol-watermark" aria-hidden="true"><?= e($cover['symbol']) ?></span>
                <span class="cover-icon-badge" aria-hidden="true"><?= e($cover['icon']) ?></span>
              </div>
              <div class="course-body">
                <span class="course-tag"><?= e($subject['stage_name'] . ' · ' . $subject['class_name']) ?></span>
                <h3><?= e($subject['subject_name']) ?></h3>
                <p class="course-lesson-count"><?= (int) $subject['lesson_count'] ?> درس منشور</p>
                <a class="button button-primary button-full" href="<?= e(url('subject', ['id' => $subject['class_subject_id']])) ?>">فتح المادة</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">
          <h3><?= empty($data['has_student_class']) ? 'لم يُسند صف دراسي إلى حسابك' : 'لا توجد مواد نشطة لصفك حاليًا' ?></h3>
          <p><?= empty($data['has_student_class']) ? 'تواصل مع إدارة المنصة لتحديث بيانات صفك.' : 'ستظهر المواد المرتبطة بصفك هنا عند تفعيلها.' ?></p>
        </div>
      <?php endif; ?>
    </section>

    <div class="student-quick-links" aria-label="روابط التعلم السريع">
      <?php if (user_has_permission('quizzes.attempt') && !empty($data['student_has_content_access'])): ?>
        <a class="quick-link-card" href="<?= e(url('quizzes')) ?>"><span class="action-icon" aria-hidden="true"><?= ui_icon('clipboard') ?></span><span>الاختبارات الإلكترونية</span></a>
      <?php endif; ?>
      <?php if (user_has_permission('content.read') && !empty($data['student_has_content_access'])): ?>
        <a class="quick-link-card" href="<?= e(url('library')) ?>"><span class="action-icon" aria-hidden="true"><?= ui_icon('library') ?></span><span>المكتبة والامتحانات</span></a>
      <?php endif; ?>
      <?php if (user_has_permission('messages.use')): ?>
        <a class="quick-link-card" href="<?= e(url('messages')) ?>"><span class="action-icon" aria-hidden="true"><?= ui_icon('message') ?></span><span>الرسائل</span></a>
      <?php endif; ?>
    </div>
  </section>

<?php elseif (!$user): ?>
  <section id="hero" class="hero container">
    <div class="hero-grid">
      <div class="hero-content">
        <span class="eyebrow">منصة الإسناد الأكاديمي · المنهج السوداني</span>
        <h1>تعلّم بسهولة،<br>وتقدّم بثقة وإتقان.</h1>
        <p class="hero-lead">بيئة تعليمية عربية تجمع المواد والدروس وبنك الأسئلة والحصص التفاعلية.</p>
        <div class="hero-actions">
          <a class="button button-primary button-large" href="<?= e(url('subjects')) ?>">تصفح المواد الدراسية</a>
          <a class="button button-secondary button-large" href="<?= e(url('register')) ?>">إنشاء حساب طالب</a>
        </div>
      </div>
      <div class="visual-box" aria-label="شعار منصة الإسناد الأكاديمي">
        <div class="visual-circle">
          <img src="/assets/brand/icon.png" alt="أيقونة الإسناد الأكاديمي" class="logo-icon">
        </div>
      </div>
    </div>
  </section>

  <section id="library" class="section container">
    <div class="section-head">
      <div>
        <span class="eyebrow">المنهج الدراسي</span>
        <h2>المواد الدراسية المقررة</h2>
      </div>
      <a href="<?= e(url('subjects')) ?>" class="link-more">عرض المواد</a>
    </div>
    <?php if (!empty($data['subjects'])): ?>
      <div class="course-grid">
        <?php foreach ($data['subjects'] as $subject): ?>
          <?php $cover = get_subject_cover_data($subject['subject_name']); ?>
          <article class="course-card">
            <div class="subject-card-cover cover-theme-<?= e($cover['theme']) ?>">
              <span class="cover-symbol-watermark" aria-hidden="true"><?= e($cover['symbol']) ?></span>
              <span class="cover-icon-badge" aria-hidden="true"><?= e($cover['icon']) ?></span>
            </div>
            <div class="course-body">
              <span class="course-tag"><?= e($subject['stage_name'] . ' · ' . $subject['class_name']) ?></span>
              <h3><?= e($subject['subject_name']) ?></h3>
              <p class="course-lesson-count"><?= (int) $subject['lesson_count'] ?> درس منشور</p>
              <a class="button button-primary button-full" href="<?= e(url('subject', ['id' => $subject['class_subject_id']])) ?>">عرض المادة</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <h3>لا توجد مواد منشورة حاليًا</h3>
        <p>ستظهر المواد المعتمدة هنا عند نشرها.</p>
      </div>
    <?php endif; ?>
  </section>

  <section class="section container sections-overview-grid" aria-label="مزايا المنصة">
    <article class="overview-box">
      <div class="overview-icon" aria-hidden="true"><?= ui_icon('question') ?></div>
      <h2>بنك الأسئلة</h2>
      <p>تدرب على الأسئلة المصنفة بحسب المادة والدرس.</p>
      <a class="button button-secondary button-full" href="<?= e(url('login')) ?>">تسجيل الدخول</a>
    </article>
    <article class="overview-box">
      <div class="overview-icon" aria-hidden="true"><?= ui_icon('library') ?></div>
      <h2>المكتبة والامتحانات</h2>
      <p>موارد تعليمية وامتحانات سابقة وفق صلاحيات الحساب.</p>
      <a class="button button-secondary button-full" href="<?= e(url('login')) ?>">تسجيل الدخول</a>
    </article>
    <article class="overview-box">
      <div class="overview-icon" aria-hidden="true"><?= ui_icon('video') ?></div>
      <h2>الحصص</h2>
      <p>جدول الحصص والتسجيلات المرتبطة بالمواد والصلاحيات.</p>
      <a class="button button-secondary button-full" href="<?= e(url('login')) ?>">تسجيل الدخول</a>
    </article>
  </section>
<?php else: ?>
  <section class="dashboard-panel">
    <h1>مرحبًا، <?= e($user['full_name']) ?></h1>
    <p class="dashboard-intro">لا يوجد دور نشط مرتبط بحسابك حاليًا. تواصل مع إدارة المنصة لمراجعة صلاحيات الحساب.</p>
    <a class="button button-secondary" href="<?= e(url('account')) ?>">عرض حسابي</a>
  </section>
<?php endif; ?>
