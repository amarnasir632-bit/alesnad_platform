<?php
$user = current_user();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#102b52">
  <meta name="description" content="منصة الإسناد الأكاديمي للتعلم ومتابعة المحتوى الدراسي.">
  <title><?= e($title) ?> | الإسناد الأكاديمي</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <?php if (($page ?? '') === 'home'): ?><link rel="stylesheet" href="/assets/css/version3.css"><?php endif; ?>
</head>
<body class="<?= ($page ?? '') === 'home' ? 'submitted-home' : '' ?>">
  <?php if (($page ?? '') === 'home'): ?>
    <header class="topbar">
      <nav class="nav container" aria-label="القائمة الرئيسية">
        <a href="<?= e(url('home')) ?>"><img src="/assets/brand/logo.png" alt="شعار الإسناد الأكاديمي" class="logo-full"></a>
        <button class="menu-toggle" type="button" aria-label="فتح القائمة"><span></span><span></span><span></span></button>
        <div class="menu">
          <a href="<?= e(url('home')) ?>">الرئيسية</a>
          <a href="<?= e(url('subjects')) ?>">المواد الدراسية</a>
          <?php if ($user): ?><a href="<?= e(url('questions')) ?>">بنك الأسئلة</a><?php endif; ?>
          <?php if ($user && (user_has_permission('quizzes.manage') || user_has_permission('quizzes.attempt'))): ?><a href="<?= e(url('quizzes')) ?>">الاختبارات</a><?php elseif ($user && (user_has_permission('content.read') || user_has_permission('content.upload') || user_has_permission('content.review'))): ?><a href="<?= e(url('library')) ?>">الامتحانات السابقة</a><?php endif; ?>
          <?php if ($user && (user_has_permission('sessions.manage') || user_has_permission('sessions.join'))): ?><a href="<?= e(url('sessions')) ?>">الحصص</a><?php endif; ?>
          <?php if ($user && user_has_permission('academics.manage')): ?><a href="<?= e(url('academics')) ?>">إدارة المنهج</a><?php endif; ?>
          <?php if ($user && user_has_permission('content_access.manage')): ?><a href="<?= e(url('content_access')) ?>">صلاحيات الطلاب</a><?php endif; ?>
          <?php if ($user && user_has_permission('users.manage')): ?><a href="<?= e(url('staff')) ?>">حسابات الفريق</a><?php endif; ?>
          <?php if ($user && user_has_permission('roles.manage')): ?><a href="<?= e(url('accounts')) ?>">إدارة الحسابات</a><?php endif; ?>
          <?php if ($user && user_has_permission('messages.use')): ?><a href="<?= e(url('messages')) ?>">الرسائل</a><?php endif; ?>
          <?php if ($user && (user_has_permission('content.read') || user_has_permission('content.upload') || user_has_permission('content.review'))): ?><a href="<?= e(url('library')) ?>">المكتبة والامتحانات</a><?php endif; ?>
          <?php if ($user): ?>
            <span><?= e($user['full_name']) ?></span>
            <form class="inline-form" method="post" action="<?= e(url('home')) ?>">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="logout">
              <button class="nav-link" type="submit">تسجيل الخروج</button>
            </form>
          <?php else: ?>
            <a href="<?= e(url('login')) ?>">دخول</a>
            <a href="<?= e(url('register')) ?>">إنشاء حساب</a>
          <?php endif; ?>
        </div>
      </nav>
    </header>
  <?php else: ?>
  <header class="site-header">
    <a class="brand" href="<?= e(url('home')) ?>">
      <img class="brand-logo" src="/assets/brand/icon.png" alt="أيقونة الإسناد الأكاديمي">
      <span>الإسناد الأكاديمي<small>منصة التعليم السودانية</small></span>
    </a>
    <nav aria-label="القائمة الرئيسية">
      <a href="<?= e(url('home')) ?>">الرئيسية</a>
      <a href="<?= e(url('subjects')) ?>">المواد الدراسية</a>
      <?php if ($user): ?><a href="<?= e(url('questions')) ?>">بنك الأسئلة</a><?php endif; ?>
      <?php if ($user): ?>
        <?php if (user_has_permission('academics.manage')): ?><a href="<?= e(url('academics')) ?>">إدارة المنهج</a><?php endif; ?>
        <?php if (user_has_permission('content_access.manage')): ?><a href="<?= e(url('content_access')) ?>">صلاحيات الطلاب</a><?php endif; ?>
        <?php if (user_has_permission('users.manage')): ?><a href="<?= e(url('staff')) ?>">حسابات الفريق</a><?php endif; ?>
        <?php if (user_has_permission('roles.manage')): ?><a href="<?= e(url('accounts')) ?>">إدارة الحسابات</a><?php endif; ?>
        <?php if (user_has_permission('messages.use')): ?><a href="<?= e(url('messages')) ?>">الرسائل</a><?php endif; ?>
        <?php if (user_has_permission('sessions.manage') || user_has_permission('sessions.join')): ?><a href="<?= e(url('sessions')) ?>">الحصص</a><?php endif; ?>
        <?php if (user_has_permission('content.read') || user_has_permission('content.upload') || user_has_permission('content.review')): ?><a href="<?= e(url('library')) ?>">المكتبة والامتحانات</a><?php endif; ?>
        <?php if (user_has_permission('quizzes.manage') || user_has_permission('quizzes.attempt')): ?><a href="<?= e(url('quizzes')) ?>">الاختبارات</a><?php endif; ?>
        <span class="nav-user">مرحباً <?= e($user['full_name']) ?></span>
        <form class="inline-form" method="post" action="<?= e(url('home')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="logout">
          <button class="nav-link" type="submit">تسجيل الخروج</button>
        </form>
      <?php else: ?>
        <a href="<?= e(url('login')) ?>">دخول</a>
        <a class="button button-small" href="<?= e(url('register')) ?>">إنشاء حساب</a>
      <?php endif; ?>
    </nav>
  </header>
  <?php endif; ?>

  <?php if ($flashes): ?>
    <div class="messages" aria-live="polite">
      <?php foreach ($flashes as $item): ?>
        <p class="message <?= e($item['type']) ?>"><?= e($item['message']) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="messages" role="alert">
      <?php foreach ($errors as $error): ?>
        <p class="message error"><?= e($error) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <main><?php require $contentView; ?></main>
  <?php if (($page ?? '') === 'home'): ?><script src="/assets/js/version3.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'questions'): ?><script src="/assets/js/questions.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'library'): ?><script src="/assets/js/library.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'quizzes'): ?><script src="/assets/js/quizzes.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'quiz_attempt'): ?><script src="/assets/js/quiz-attempt.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'sessions'): ?><script src="/assets/js/sessions.js" defer></script><?php endif; ?>
  <?php if (($page ?? '') === 'accounts'): ?><script src="/assets/js/accounts.js" defer></script><?php endif; ?>
  <footer class="site-footer">
    <span class="developer-brand"><img src="/assets/brand/al-warith-logo.jpg" alt="شعار شركة الوارث المطورة للموقع"> شركة الوارث · الشركة المطورة للموقع</span>
    <span>خطوتك القادمة تبدأ بالتعلّم</span>
  </footer>
</body>
</html>
