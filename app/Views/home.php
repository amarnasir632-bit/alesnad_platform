<?php
$user = current_user();
$isAdmin = $user && user_has_role('admin');
$isReviewer = $user && user_has_role('content_reviewer');
$isTeacher = $user && user_has_role('teacher');
$isStudent = $user && user_has_role('student');
?>

<!-- =========================================================================
     لوحة الإعلانات والتنبيهات المدرسية الأكاديمية (School Announcements & Noticeboard)
     ========================================================================= -->
<?php 
$announcementsList = $data['published_announcements'] ?? [];
$topAnnouncement = !empty($announcementsList) ? $announcementsList[0] : null;
$announcementCover = $topAnnouncement ? get_announcement_cover_data($topAnnouncement['cover_style'] ?? 'gradient_blue', $topAnnouncement['priority'] ?? 'NORMAL') : null;
?>

<!-- =========================================================================
     لوحة الإعلانات والتنبيهات المدرسية الأكاديمية (School Announcements & Noticeboard)
     ========================================================================= -->
<section class="announcements-banner" aria-label="لوحة الإعلانات والتنبيهات" style="<?= $announcementCover ? 'border-right: 6px solid ' . e($announcementCover['accent']) . ';' : '' ?>">
  <div class="announcement-content">
    <div class="announcement-badge-wrap">
      <span class="announcement-pulse-dot" aria-hidden="true"></span>
      <span class="announcement-tag"><?= e($topAnnouncement['badge_label'] ?? '📢 لوحة الإعلانات والتنبيهات المدرسية') ?></span>
      <span class="announcement-date">
        <?= $topAnnouncement ? e(date('Y-m-d', strtotime($topAnnouncement['created_at']))) : 'العام الدراسي 2026 / 2027' ?>
      </span>
      <?php if ($topAnnouncement && $topAnnouncement['priority'] === 'URGENT'): ?>
        <span class="status-pill status-pill-danger">🚨 تنبيه عاجل</span>
      <?php endif; ?>
    </div>
    <div class="announcement-body">
      <h2 class="announcement-title">
        <?= e($topAnnouncement['title'] ?? 'انطلاق جدول الحصص المباشرة والاختبارات التجريبية للمنهج السوداني') ?>
      </h2>
      <p class="announcement-desc">
        <?= nl2br(e($topAnnouncement['content'] ?? 'نحيطكم علمًا بأن مواعيد الحصص التفاعلية وبنك الأسئلة المعتمد متاحة الآن لكافة الطلاب والأساتذة. يمكنكم متابعة التحديثات وجدول البث الأسبوعي.')) ?>
      </p>
    </div>
  </div>
  <div class="announcement-actions">
    <?php if ($isAdmin || $isTeacher): ?>
      <a href="<?= e(url('announcements')) ?>" class="button button-small button-outline">📢 إدارة الإعلانات</a>
    <?php endif; ?>
    <a href="<?= e(url('sessions')) ?>" class="button button-small button-primary">📅 جدول الحصص المباشرة</a>
    <a href="<?= e(url('subjects')) ?>" class="button button-small button-secondary">📚 المقررات الدراسية</a>
  </div>
</section>

<?php if ($isAdmin || $isReviewer): ?>
  <!-- =========================================================================
       لوحة تحكم مدير النظام ومراجع المحتوى (Admin / Reviewer Dashboard)
       ========================================================================= -->
  <section class="dashboard-panel admin-dashboard">
    <div class="dashboard-welcome">
      <div class="welcome-text">
        <span class="eyebrow"><?= $isAdmin ? 'لوحة القيادة الإدارية' : 'لوحة مراجعة المحتوى والوصول' ?></span>
        <h1>مرحبًا بك، <?= e($user['full_name']) ?></h1>
        <p class="dashboard-intro">مركز الإدارة السريع للمنهج، وصول الطلاب، المحتوى، وحسابات فريق العمل في منصة الإسناد الأكاديمي.</p>
      </div>
      <div class="welcome-actions">
        <?php if ($isAdmin): ?>
          <a class="button button-primary" href="<?= e(url('academics')) ?>">⚙️ إدارة المنهج والمواد</a>
          <a class="button button-secondary" href="<?= e(url('content_access')) ?>">🔐 صلاحيات الطلاب</a>
        <?php else: ?>
          <a class="button button-primary" href="<?= e(url('content_access')) ?>">🔐 إدارة صلاحيات الطلاب</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- شبكة الإجراءات الإدارية المباشرة (Direct Admin Actions) -->
    <div class="admin-quick-actions-grid">
      <div class="quick-action-card">
        <div class="action-icon" aria-hidden="true">⚙️</div>
        <div class="action-info">
          <h3>إدارة المنهج والصفوف</h3>
          <p>إضافة المراحل التعليمية، الصفوف، وتخصيص المواد الدراسية لكل صف وفق المنهج السوداني.</p>
          <a class="button button-small button-secondary" href="<?= e(url('academics')) ?>">فتح الهيكل الدراسي ←</a>
        </div>
      </div>

      <div class="quick-action-card">
        <div class="action-icon" aria-hidden="true">🔐</div>
        <div class="action-info">
          <h3>صلاحيات وصول الطلاب</h3>
          <p>مراجعة قائمة الطلاب، ومنح أو سحب أهلية الوصول للمحتوى والدروس والامتحانات يدويًا.</p>
          <a class="button button-small button-primary" href="<?= e(url('content_access')) ?>">إدارة صلاحيات الوصول ←</a>
        </div>
      </div>

      <div class="quick-action-card">
        <div class="action-icon" aria-hidden="true">👥</div>
        <div class="action-info">
          <h3>حسابات الفريق والدعوات</h3>
          <p>إرسال روابط دعوات تفعيل حسابات الأساتذة والمراجعين والمدراء وإسناد المواد للأساتذة.</p>
          <a class="button button-small button-secondary" href="<?= e(url('staff')) ?>">إدارة فريق العمل ←</a>
        </div>
      </div>

      <div class="quick-action-card">
        <div class="action-icon" aria-hidden="true">📖</div>
        <div class="action-info">
          <h3>اعتماد المكتبة والامتحانات</h3>
          <p>مراجعة الكتب المرفوعة، ملفات PDF، تسجيلات الفيديوهات واعتماد نشرها للطلاب.</p>
          <a class="button button-small button-secondary" href="<?= e(url('library')) ?>">مراجعة المحتوى ←</a>
        </div>
      </div>

      <div class="quick-action-card">
        <div class="action-icon" aria-hidden="true">❓</div>
        <div class="action-info">
          <h3>مراجعة بنك الأسئلة</h3>
          <p>فحص الأسئلة المضافة من الأساتذة واعتمادها لتكون جاهزة للاختبارات الإلكترونية.</p>
          <a class="button button-small button-secondary" href="<?= e(url('questions')) ?>">فحص بنك الأسئلة ←</a>
        </div>
      </div>

      <div class="quick-action-card">
        <div class="action-icon" aria-hidden="true">📝</div>
        <div class="action-info">
          <h3>اعتماد نتائج الاختبارات</h3>
          <p>متابعة تقييمات الطلاب، واعتماد الدرجات النهائية، والبت في محاولات الإعادة.</p>
          <a class="button button-small button-secondary" href="<?= e(url('quizzes')) ?>">مراجعة الاختبارات ←</a>
        </div>
      </div>
    </div>
  </section>

<?php elseif ($isTeacher): ?>
  <!-- =========================================================================
       لوحة تحكم الأستاذ والمدرس (Teacher Dashboard)
       ========================================================================= -->
  <section class="dashboard-panel teacher-dashboard">
    <div class="dashboard-welcome">
      <div class="welcome-text">
        <span class="eyebrow">مساحة عمل الأستاذ</span>
        <h1>مرحبًا بك أستاذ <?= e($user['full_name']) ?></h1>
        <p class="dashboard-intro">منصتك اليومية لإدارة المواد المسندة، إضافة الأسئلة، إعداد الاختبارات وتصحيحها، وبث الحصص المباشرة.</p>
      </div>
      <div class="welcome-actions">
        <a class="button button-primary" href="<?= e(url('questions')) ?>">➕ إضافة سؤال جديد</a>
        <a class="button button-secondary" href="<?= e(url('quizzes')) ?>">📝 إنشاء / تصحيح اختبار</a>
      </div>
    </div>

    <!-- شبكة مهام الأستاذ اليومية -->
    <div class="teacher-tasks-grid">
      <div class="task-card">
        <div class="task-icon" aria-hidden="true">❓</div>
        <h3>بنك الأسئلة المدرسية</h3>
        <p>أنشئ أسئلة موضوعية ومقالية لموادك المسندة وقدّمها للمراجعة والاعتماد.</p>
        <a class="button button-small button-primary" href="<?= e(url('questions')) ?>">فتح بنك الأسئلة ←</a>
      </div>

      <div class="task-card">
        <div class="task-icon" aria-hidden="true">📝</div>
        <h3>الاختبارات وتصحيح المقالي</h3>
        <p>تجهيز اختبارات إلكترونية من الأسئلة المعتمدة، وتصحيح إجابات الطلاب المقالية.</p>
        <a class="button button-small button-secondary" href="<?= e(url('quizzes')) ?>">متابعة الاختبارات ←</a>
      </div>

      <div class="task-card">
        <div class="task-icon" aria-hidden="true">📚</div>
        <h3>رفع الموارد والامتحانات</h3>
        <p>رفع الكتب بصيغة PDF ومقاطع الفيديو التعليمية لموادك لتدخل مسار المراجعة.</p>
        <a class="button button-small button-secondary" href="<?= e(url('library')) ?>">إدارة المكتبة ←</a>
      </div>

      <div class="task-card">
        <div class="task-icon" aria-hidden="true">🎥</div>
        <h3>الحصص المباشرة والمسجلة</h3>
        <p>جدولة الحصص المباشرة وإعداد مفاتيح البث المباشر ومزامنة التسجيلات للطلاب.</p>
        <a class="button button-small button-secondary" href="<?= e(url('sessions')) ?>">جدولة الحصص ←</a>
      </div>

      <div class="task-card">
        <div class="task-icon" aria-hidden="true">💬</div>
        <h3>الرسائل مع الطلاب</h3>
        <p>تواصل فردي وتوجيه تعليمي مباشر مع طلاب صفك الدراسي للإجابة عن استفساراتهم.</p>
        <a class="button button-small button-secondary" href="<?= e(url('messages')) ?>">صندوق الرسائل ←</a>
      </div>
    </div>
  </section>

<?php elseif ($isStudent): ?>
  <!-- =========================================================================
       لوحة تعلم الطالب (Student Learning Hub)
       تجيب فوراً عن: ماذا أدرس الآن؟ ما الخطوة التالية؟ كيف أصل لمادتي بأقل مجهود؟
       ========================================================================= -->
  <section class="dashboard-panel student-dashboard">
    <div class="student-welcome-banner">
      <div class="student-welcome-text">
        <span class="eyebrow">مرحباً بك في صفك الدراسي</span>
        <h1>واصل رحلتك التعليمية، يا <?= e($user['full_name']) ?></h1>
        <p class="student-prompt">
          اختر مادتك المقررة وابدأ بمشاهدة الدروس، حل أسئلة بنك التمارين، والاستعداد للامتحانات.
        </p>
      </div>
      <div class="student-quick-start">
        <a class="button button-primary button-large" href="<?= e(url('subjects')) ?>">📚 تصفح موادي الدراسية</a>
        <a class="button button-secondary" href="<?= e(url('quizzes')) ?>">📝 الاختبارات الإلكترونية</a>
      </div>
    </div>

    <!-- خطوتك التالية: الوصول المباشر لأهم 3 محاور للطالب -->
    <div class="student-triad-grid">
      <div class="triad-card highlight-step">
        <div class="step-num-badge">1</div>
        <div class="triad-icon" aria-hidden="true">📚</div>
        <h3>ماذا أدرس الآن؟</h3>
        <p>تصفح قائمة المواد المقررة لصفك، وافتح الدروس المنشورة بالترتيب لمتابعة الشرح والملفات.</p>
        <a class="card-action-btn" href="<?= e(url('subjects')) ?>">دخول المواد المقررة ←</a>
      </div>

      <div class="triad-card">
        <div class="step-num-badge">2</div>
        <div class="triad-icon" aria-hidden="true">📝</div>
        <h3>ما خطوتي التالية؟</h3>
        <p>اختبر فهمك عبر خوض الاختبارات الإلكترونية المصححة آليًا والتدرّب على أسئلة بنك التمارين.</p>
        <a class="card-action-btn" href="<?= e(url('quizzes')) ?>">بدء التدريب والاختبارات ←</a>
      </div>

      <div class="triad-card">
        <div class="step-num-badge">3</div>
        <div class="triad-icon" aria-hidden="true">📖</div>
        <h3>المكتبة والامتحانات</h3>
        <p>تحميل الكتب الدراسية والامتحانات السابقة للشهادة السودانية ومتابعة الحصص المسجلة.</p>
        <a class="card-action-btn" href="<?= e(url('library')) ?>">فتح المكتبة والامتحانات ←</a>
      </div>
    </div>
  </section>

<?php else: ?>
  <!-- =========================================================================
       الواجهة الترحيبية للزوار والضيوف (Public Guest Hero)
       ========================================================================= -->
  <section id="hero" class="hero container">
    <div class="hero-grid">
      <div class="hero-content">
        <span class="eyebrow">منصة الإسناد الأكاديمي · المنهج السوداني</span>
        <h1>تعلّم بسهولة،<br>وتقدّم بثقة وإتقان.</h1>
        <p class="hero-lead">بيئة تعليمية عربية حديثة تجمع المواد والدروس، بنك الأسئلة، الامتحانات السابقة، والحصص التفاعلية لمساعدتك في تحقيق أعلى النتائج.</p>
        <div class="hero-actions">
          <a class="button button-primary button-large" href="<?= e(url('subjects')) ?>">تصفح المواد الدراسية ←</a>
          <a class="button button-secondary button-large" href="<?= e(url('register')) ?>">إنشاء حساب طالب جديد</a>
        </div>
      </div>
      <div class="visual-box" aria-label="شعار منصة الإسناد الأكاديمي">
        <div class="visual-circle">
          <img src="/assets/brand/icon.png" alt="أيقونة الإسناد الأكاديمي" class="logo-icon">
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =========================================================================
     عرض المواد الدراسية المقررة (الكتالوج المتاح)
     ========================================================================= -->
<section id="library" class="section container">
  <div class="section-head">
    <div>
      <span class="eyebrow">المنهج الدراسي</span>
      <h2>المواد الدراسية المقررة</h2>
    </div>
    <a href="<?= e(url('subjects')) ?>" class="link-more">عرض كل المواد المقررة ←</a>
  </div>

  <?php if (!empty($data['subjects'])): ?>
    <div class="course-grid">
      <?php foreach ($data['subjects'] as $subject): ?>
        <?php $sc = get_subject_cover_data($subject['subject_name']); ?>
        <article class="course-card">
          <div class="subject-card-cover" style="background: <?= e($sc['gradient']) ?>;">
            <span class="cover-symbol-watermark" aria-hidden="true"><?= e($sc['symbol']) ?></span>
            <span class="cover-icon-badge" aria-hidden="true"><?= e($sc['icon']) ?></span>
          </div>
          <div class="course-body">
            <span class="course-tag"><?= e($subject['stage_name'] . ' · ' . $subject['class_name']) ?></span>
            <h3><?= e($subject['subject_name']) ?></h3>
            <p class="course-lesson-count"><?= (int) $subject['lesson_count'] ?> درس منشور ومعتمد</p>
            <a class="button button-primary button-full" href="<?= e(url('subject', ['id' => $subject['class_subject_id']])) ?>">عرض المادة والدروس</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <h3>لا توجد مواد منشورة حاليًا</h3>
      <p>ستظهر المواد المعتمدة لصفك الدراسي فور تسجيلها في المنصة.</p>
    </div>
  <?php endif; ?>
</section>

<!-- =========================================================================
     أقسام التعلم السريع (بنك الأسئلة، الامتحانات، الحصص)
     ========================================================================= -->
<section class="section container sections-overview-grid">
  <div class="overview-box">
    <div class="overview-icon" aria-hidden="true">❓</div>
    <h3>بنك الأسئلة الشامل</h3>
    <p>تدرب على أسئلة موضوعية ومقالية مصنفة بحسب المادة والدرس ومستوى الصعوبة.</p>
    <a class="button button-secondary button-full" href="<?= e(url('questions')) ?>">دخول بنك الأسئلة</a>
  </div>

  <div class="overview-box">
    <div class="overview-icon" aria-hidden="true">📖</div>
    <h3>المكتبة والامتحانات السابقة</h3>
    <p>مراجع وكتب دراسية بصيغة PDF وأرشيف امتحانات الشهادة السودانية مع الإجابات النموذجية.</p>
    <?php if ($user && (user_has_permission('content.read') || user_has_permission('content.upload') || user_has_permission('content.review'))): ?>
      <a class="button button-secondary button-full" href="<?= e(url('library')) ?>">فتح المكتبة</a>
    <?php else: ?>
      <a class="button button-secondary button-full" href="<?= e(url('login')) ?>">تسجيل الدخول للمكتبة</a>
    <?php endif; ?>
  </div>

  <div class="overview-box">
    <div class="overview-icon" aria-hidden="true">🎥</div>
    <h3>الحصص المباشرة والمسجلة</h3>
    <p>حضور حصص الشرح التفاعلية مع الأساتذة ومراجعة التسجيلات بجودة عالية في أي وقت.</p>
    <?php if ($user && (user_has_permission('sessions.manage') || user_has_permission('sessions.join'))): ?>
      <a class="button button-secondary button-full" href="<?= e(url('sessions')) ?>">جدول الحصص والتسجيلات</a>
    <?php else: ?>
      <a class="button button-secondary button-full" href="<?= e(url('login')) ?>">تسجيل الدخول للحصص</a>
    <?php endif; ?>
  </div>
</section>
