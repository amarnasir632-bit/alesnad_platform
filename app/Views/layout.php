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
</head>
<body>
  <header class="site-header">
    <a class="brand" href="<?= e(url('home')) ?>">
      <img class="brand-logo" src="/assets/brand/al-warith-logo.jpg" alt="AL WARITH">
      <span>الإسناد الأكاديمي<small>منصة التعليم السودانية</small></span>
    </a>
    <nav aria-label="القائمة الرئيسية">
      <a href="<?= e(url('home')) ?>">الرئيسية</a>
      <a href="<?= e(url('subjects')) ?>">المواد الدراسية</a>
      <?php if ($user): ?>
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
  <footer class="site-footer">
    <span>منصة الإسناد الأكاديمي · AL WARITH</span>
    <span>خطوتك القادمة تبدأ بالتعلّم</span>
  </footer>
</body>
</html>
