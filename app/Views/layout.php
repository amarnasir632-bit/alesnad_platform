<?php
require_once PROJECT_ROOT . '/app/Support/icons.php';
$user = current_user();
$currentPage = $page ?? 'home';
$hasStudentContentAccess = !$user || !user_has_role('student')
    || (array_key_exists('student_has_content_access', $data ?? [])
        ? (bool) $data['student_has_content_access']
        : student_has_manual_content_access((int) $user['id']));
$isTeacherOnly = $user && user_has_role('teacher') && !user_has_role('admin') && !user_has_role('content_reviewer');
$homeLabel = !$user ? 'الصفحة الرئيسية'
    : (user_has_role('admin') ? 'لوحة الإدارة'
        : (user_has_role('content_reviewer') ? 'لوحة المراجعة'
            : (user_has_role('teacher') ? 'لوحة العمل'
                : (user_has_role('student') ? 'مساحتي التعليمية' : 'الرئيسية'))));

// تحديد مسمى الدور باللغة العربية لعرضه بوضوح
$roleBadge = 'زائر';
if ($user) {
    if (user_has_role('admin')) {
        $roleBadge = 'مدير النظام';
    } elseif (user_has_role('content_reviewer')) {
        $roleBadge = 'مراجع محتوى';
    } elseif (user_has_role('teacher')) {
        $roleBadge = 'أستاذ';
    } elseif (user_has_role('student')) {
        $roleBadge = 'طالب';
    } else {
        $roleBadge = 'عضو';
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
  <meta name="theme-color" content="#0d2744">
  <meta name="description" content="منصة الإسناد الأكاديمي للتعلم ومتابعة المحتوى الدراسي المنهج السوداني.">
  <title><?= e($title) ?> | الإسناد الأكاديمي</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="<?= ($page ?? '') === 'home' ? 'submitted-home' : '' ?>">

  <!-- رابط الانتقال السريع للمحتوى لمستخدمي قارئات الشاشة ولوحة المفاتيح -->
  <a href="#main-content" class="skip-link">انتقل إلى المحتوى الرئيسي</a>

  <div class="app-layout-wrapper">

    <!-- القائمة الجانبية (Sidebar للكمبيوتر / Drawer يختفي ويظهر في الهاتف) -->
    <aside class="app-sidebar" id="app-sidebar" aria-label="القائمة الجانبية للمنصة">
      <div class="sidebar-header">
        <a class="sidebar-brand" href="<?= e(url('home')) ?>">
          <img class="brand-logo" src="/assets/brand/icon.png" alt="شعار الإسناد الأكاديمي">
          <div class="brand-text">
            <span class="brand-title">الإسناد الأكاديمي</span>
            <small class="brand-subtitle">المنهج السوداني · AL WARITH</small>
          </div>
        </a>
        <button class="sidebar-close-btn" id="sidebar-close-btn" type="button" aria-label="إغلاق القائمة">✕</button>
      </div>

      <!-- بطاقة المستخدم السريعة داخل القائمة الجانبية -->
      <?php if ($user): ?>
        <a href="<?= e(url('account')) ?>" class="sidebar-user-card-link" title="عرض وتعديل الملف الشخصي والحساب">
          <div class="sidebar-user-card">
            <div class="user-avatar" aria-hidden="true"><?= mb_substr($user['full_name'], 0, 1) ?></div>
            <div class="user-details">
              <strong class="user-name"><?= e($user['full_name']) ?></strong>
              <span class="user-role-badge"><?= e($roleBadge) ?></span>
              <span class="user-account-subtext">حسابي والملف الشخصي</span>
            </div>
          </div>
        </a>
      <?php else: ?>
        <div class="sidebar-guest-card">
          <p class="guest-text">مرحباً بك في منصة الإسناد الأكاديمي</p>
          <div class="guest-actions">
            <a class="button button-primary button-full" href="<?= e(url('login')) ?>">تسجيل الدخول</a>
            <a class="button button-secondary button-full" href="<?= e(url('register')) ?>">إنشاء حساب طالب</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- روابط التنقل مقسمة إلى مجموعات منطقية -->
      <nav class="sidebar-nav" aria-label="روابط المنصة">

        <!-- القسم الأول: التصفح والمحتوى -->
        <div class="nav-group">
          <span class="nav-group-title">التصفح والمحتوى</span>
          <ul class="nav-list">
            <li>
              <a class="nav-item-link <?= $currentPage === 'home' ? 'active' : '' ?>" href="<?= e(url('home')) ?>">
                <span class="nav-icon"><?= ui_icon('home') ?></span>
                <span class="nav-label"><?= e($homeLabel) ?></span>
              </a>
            </li>
            <?php if ($user): ?>
              <li>
                <a class="nav-item-link <?= ($currentPage === 'account' || $currentPage === 'profile') ? 'active' : '' ?>" href="<?= e(url('account')) ?>">
                  <span class="nav-icon"><?= ui_icon('user') ?></span>
                  <span class="nav-label">الملف الشخصي والحساب</span>
                </a>
              </li>
            <?php endif; ?>
            <li>
              <a class="nav-item-link <?= ($currentPage === 'subjects' || $currentPage === 'subject') ? 'active' : '' ?>" href="<?= e(url('subjects')) ?>">
                <span class="nav-icon"><?= ui_icon('book') ?></span>
                <span class="nav-label">المواد الدراسية</span>
              </a>
            </li>
            <?php if ($user && $hasStudentContentAccess && (user_has_permission('content.read') || user_has_permission('content.upload') || user_has_permission('content.review'))): ?>
              <li>
                <a class="nav-item-link <?= $currentPage === 'library' ? 'active' : '' ?>" href="<?= e(url('library')) ?>">
                  <span class="nav-icon"><?= ui_icon('library') ?></span>
                  <span class="nav-label">المكتبة والامتحانات</span>
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </div>

        <!-- القسم الثاني: التعلم والأنشطة -->
        <?php if ($user): ?>
          <div class="nav-group">
            <span class="nav-group-title">التعلّم والاختبارات</span>
            <ul class="nav-list">
              <?php if (user_has_permission('questions.manage') || user_has_permission('content.review') || (user_has_role('student') && $hasStudentContentAccess)): ?><li>
                <a class="nav-item-link <?= $currentPage === 'questions' ? 'active' : '' ?>" href="<?= e(url('questions')) ?>">
                  <span class="nav-icon"><?= ui_icon('question') ?></span>
                  <span class="nav-label">بنك الأسئلة</span>
                </a>
              </li><?php endif; ?>
              <?php if (user_has_permission('quizzes.manage') || user_has_permission('quizzes.attempt')): ?>
                <li>
                  <a class="nav-item-link <?= ($currentPage === 'quizzes' || $currentPage === 'quiz_attempt') ? 'active' : '' ?>" href="<?= e(url('quizzes')) ?>">
                    <span class="nav-icon"><?= ui_icon('clipboard') ?></span>
                    <span class="nav-label">الاختبارات الإلكترونية</span>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (user_has_permission('sessions.manage') || user_has_permission('sessions.join')): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'sessions' ? 'active' : '' ?>" href="<?= e(url('sessions')) ?>">
                    <span class="nav-icon"><?= ui_icon('video') ?></span>
                    <span class="nav-label">الحصص المباشرة والمسجلة</span>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (user_has_role('student') || user_has_role('teacher') || (user_has_role('admin') && user_has_permission('academics.manage'))): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'school_schedule' ? 'active' : '' ?>" href="<?= e(url('school_schedule')) ?>">
                    <span class="nav-icon"><?= ui_icon('calendar') ?></span>
                    <span class="nav-label">الجدول الدراسي والإجازات</span>
                  </a>
                </li>
              <?php endif; ?>
              <?php if ($user && (user_has_permission('messages.use') || user_has_role('admin'))): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'messages' ? 'active' : '' ?>" href="<?= e(url('messages')) ?>">
                    <span class="nav-icon"><?= ui_icon('message') ?></span>
                    <span class="nav-label"><?= user_has_role('admin') ? 'رقابة الرسائل الأكاديمية' : 'الرسائل الفردية' ?></span>
                  </a>
                </li>
              <?php endif; ?>
              <?php if ($isTeacherOnly): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'announcements' ? 'active' : '' ?>" href="<?= e(url('announcements')) ?>">
                    <span class="nav-icon"><?= ui_icon('megaphone') ?></span>
                    <span class="nav-label">إعلانات المواد والطلاب</span>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>

        <!-- القسم الثالث: إدارة المنصة (للمسؤولين فقط) -->
        <?php if ($user && (user_has_permission('academics.manage') || user_has_permission('content_access.manage') || user_has_permission('users.manage') || user_has_permission('roles.manage'))): ?>
          <div class="nav-group">
            <span class="nav-group-title"><?= user_has_role('admin') ? 'إدارة المنصة' : 'مراجعة المحتوى' ?></span>
            <ul class="nav-list">
              <?php if (user_has_role('admin')): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'announcements' ? 'active' : '' ?>" href="<?= e(url('announcements')) ?>">
                    <span class="nav-icon"><?= ui_icon('megaphone') ?></span>
                    <span class="nav-label">إدارة لوحة الإعلانات</span>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (user_has_permission('academics.manage')): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'academics' ? 'active' : '' ?>" href="<?= e(url('academics')) ?>">
                    <span class="nav-icon"><?= ui_icon('settings') ?></span>
                    <span class="nav-label">إدارة المنهج والمواد</span>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (user_has_permission('content_access.manage')): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'content_access' ? 'active' : '' ?>" href="<?= e(url('content_access')) ?>">
                    <span class="nav-icon"><?= ui_icon('shield') ?></span>
                    <span class="nav-label">صلاحيات وصول الطلاب</span>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (user_has_permission('users.manage')): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'staff' ? 'active' : '' ?>" href="<?= e(url('staff')) ?>">
                    <span class="nav-icon"><?= ui_icon('users') ?></span>
                    <span class="nav-label">حسابات الفريق والدعوات</span>
                  </a>
                </li>
              <?php endif; ?>
              <?php if (user_has_permission('roles.manage')): ?>
                <li>
                  <a class="nav-item-link <?= $currentPage === 'accounts' ? 'active' : '' ?>" href="<?= e(url('accounts')) ?>">
                    <span class="nav-icon"><?= ui_icon('shield') ?></span>
                    <span class="nav-label">إدارة الحسابات والأدوار</span>
                  </a>
                </li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>

        <!-- دليل المنصة والشاشات (متاح لجميع الأدوار) -->
        <div class="nav-group">
          <span class="nav-group-title">المساعدة والإرشاد</span>
          <ul class="nav-list">
            <li>
              <a class="nav-item-link <?= $currentPage === 'guide' ? 'active' : '' ?>" href="<?= e(url('guide')) ?>">
                <span class="nav-icon"><?= ui_icon('question') ?></span>
                <span class="nav-label">دليل واجهات المنصة</span>
              </a>
            </li>
          </ul>
        </div>

      </nav>

      <!-- تذييل القائمة الجانبية (تسجيل الخروج) -->
      <?php if ($user): ?>
        <div class="sidebar-footer">
          <form class="logout-form" method="post" action="<?= e(url('home')) ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="button button-logout button-full" type="submit">
              <span class="logout-icon" aria-hidden="true"><?= ui_icon('logout') ?></span>
              <span>تسجيل الخروج</span>
            </button>
          </form>
        </div>
      <?php endif; ?>
    </aside>

    <!-- طبقة التعتيم الخلفية عند فتح القائمة بالهاتف -->
    <div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true"></div>

    <!-- المحتوى الرئيسي للموقع -->
    <div class="app-main-layout">

      <!-- شريط علوي للهاتف والشاشات الصغيرة مع زر القائمة اللمسي -->
      <header class="app-mobile-topbar" aria-label="الشريط العلوي السريع">
        <button class="sidebar-toggle-btn" id="sidebar-toggle-btn" type="button" aria-label="فتح القائمة الرئيسية" aria-expanded="false" aria-controls="app-sidebar">
          <span class="toggle-icon-bars" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
          </span>
          <span class="toggle-text">القائمة</span>
        </button>

        <a class="mobile-brand" href="<?= e(url('home')) ?>">
          <img src="/assets/brand/icon.png" alt="الإسناد الأكاديمي" class="mobile-logo-img">
          <span class="mobile-brand-title">الإسناد الأكاديمي</span>
        </a>

        <div class="mobile-quick-actions">
          <?php if ($user): ?>
            <a href="<?= e(url('account')) ?>" class="mobile-account-link" title="الملف الشخصي والحساب">
              <span class="mobile-user-avatar" aria-label="<?= e('الملف الشخصي لـ ' . $user['full_name']) ?>"><?= mb_substr($user['full_name'], 0, 1) ?></span>
            </a>
          <?php else: ?>
            <a class="button button-small button-primary" href="<?= e(url('login')) ?>">دخول</a>
          <?php endif; ?>
        </div>
      </header>

      <!-- تنبيهات النظام ورسائل النجاح والخطأ -->
      <?php if (!empty($flashes)): ?>
        <div class="messages" aria-live="polite">
          <?php foreach ($flashes as $item): ?>
            <div class="message <?= e($item['type']) ?>">
              <span class="message-icon" aria-hidden="true"><?= $item['type'] === 'success' ? '✓' : 'ℹ' ?></span>
              <span><?= e($item['message']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="messages" role="alert">
          <?php foreach ($errors as $error): ?>
            <div class="message error">
              <span class="message-icon" aria-hidden="true">⚠</span>
              <span><?= e($error) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- صفحة المحتوى المطلوبة -->
      <main id="main-content" class="main-body">
        <?php require $contentView; ?>
      </main>

      <!-- تذييل الصفحة المطور والمختصر (Modern Centered Minimal Footer) -->
      <footer class="site-footer" role="contentinfo">
        <div class="footer-centered-wrap">
          <div class="footer-brand-centered">
            <img src="/assets/brand/icon.png" alt="شعار منصة الإسناد الأكاديمي" class="footer-mini-logo">
            <strong class="footer-brand-title">منصة الإسناد الأكاديمي</strong>
            <span class="footer-dot-sep">·</span>
            <span class="footer-brand-subtitle">المنهج السوداني المعتمد</span>
          </div>

          <nav class="footer-quick-links" aria-label="روابط سريعة">
            <a href="<?= e(url('home')) ?>">الرئيسية</a>
            <span class="sep" aria-hidden="true">•</span>
            <a href="<?= e(url('subjects')) ?>">المواد والدروس</a>
            <?php if ($user && (user_has_permission('questions.manage') || user_has_permission('content.review') || (user_has_role('student') && $hasStudentContentAccess))): ?>
              <span class="sep" aria-hidden="true">•</span>
              <a href="<?= e(url('questions')) ?>">بنك الأسئلة</a>
            <?php endif; ?>
            <?php if ($user && $hasStudentContentAccess && (user_has_permission('content.read') || user_has_permission('content.upload') || user_has_permission('content.review'))): ?>
              <span class="sep" aria-hidden="true">•</span>
              <a href="<?= e(url('library')) ?>">المكتبة الرقمية</a>
            <?php endif; ?>
            <?php if ($user && (user_has_permission('sessions.manage') || user_has_permission('sessions.join'))): ?>
              <span class="sep" aria-hidden="true">•</span>
              <a href="<?= e(url('sessions')) ?>">الحصص المباشرة</a>
            <?php endif; ?>
            <span class="sep" aria-hidden="true">•</span>
            <a href="<?= e(url('guide')) ?>">دليل المنصة والواجهات</a>
          </nav>

          <div class="footer-meta-centered">
            <span class="footer-copyright">جميع الحقوق محفوظة © <?= date('Y') ?> منصة الإسناد الأكاديمي</span>
            <span class="footer-dot-sep">·</span>
            <span class="footer-dev-credit">
              إشراف وتطوير <strong>شركة AL WARITH</strong>
              <img src="/assets/brand/al-warith-logo.jpg" alt="شعار شركة الوارث" class="footer-dev-logo">
            </span>
          </div>
        </div>
      </footer>
    </div>
  </div>

  <!-- السكربتات التفاعلية العامة -->
  <script src="/assets/js/main.js" defer></script>
  <?php if (($page ?? '') === 'home' && !empty($data['sessions']) && user_has_permission('sessions.join')): ?><script src="/assets/js/sessions.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'questions'): ?><script src="/assets/js/questions.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'library'): ?><script src="/assets/js/library.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'quizzes'): ?><script src="/assets/js/quizzes.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'quiz_attempt'): ?><script src="/assets/js/quiz-attempt.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'sessions'): ?><script src="/assets/js/sessions.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'school_schedule' && !empty($data['canManageSchedule'])): ?><script src="/assets/js/school-schedule.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'accounts'): ?><script src="/assets/js/accounts.js" defer></script><?php endif; ?>
</body>
</html>
