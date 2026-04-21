<?php
/** @var string $view */
$flash = $flash ?? pullFlash();
$viewSlug = str_replace('/', '-', (string) ($view ?? ''));
$isHome = $viewSlug === 'home';
$activeCinemaTab = (($_GET['tab'] ?? 'now') === 'soon') ? 'soon' : 'now';
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Кінотеатр') ?></title>
<link rel="icon" type="image/png" href="/favicon.png">
<link rel="shortcut icon" href="/favicon.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=PT+Sans:ital,wght@0,400;0,700;1,400;1,700&display=swap" rel="stylesheet">
<link href="/assets/app.css" rel="stylesheet">
<link href="/public/assets/app.css" rel="stylesheet">
</head>
<body class="<?= e(trim(($isHome ? 'home-page ' : '') . 'page-' . $viewSlug)) ?>">

<!-- Toast Container for Notifications -->
<div class="toast-container" id="toast-container"></div>

<header class="site-header">
  <div class="main-nav-wrap">
    <div class="container multiplex-topbar<?= $isHome ? ' home-tabs-layout' : '' ?>">
      <div class="topbar-left">
        <a href="/" class="brand-link">Кінотеатр</a>
      </div>

      <?php if ($isHome): ?>
        <nav class="header-cinema-tabs" aria-label="Фільм-вкладки">
          <a href="/?tab=now" class="<?= $activeCinemaTab === 'now' ? 'active' : '' ?>">Зараз у кіно</a>
          <a href="/?tab=soon" class="<?= $activeCinemaTab === 'soon' ? 'active' : '' ?>">Скоро у прокаті</a>
        </nav>
      <?php endif; ?>

      <div class="topbar-right">
        <?php if ($currentUser): ?>
          <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
            <a class="login-link" href="/admin/movies"><i class="bi bi-shield-lock"></i> Адмін-панель</a>
          <?php else: ?>
            <a class="login-link" href="/profile"><i class="bi bi-person-circle"></i> Кабінет</a>
          <?php endif; ?>
          <form method="post" action="/auth/logout" class="m-0">
            <button class="login-link logout-link" type="submit"><i class="bi bi-box-arrow-right"></i> Вийти</button>
          </form>
        <?php else: ?>
          <a class="login-link" href="/login"><i class="bi bi-box-arrow-in-right"></i> Увійти</a>
        <?php endif; ?>
      </div>
        </div>
    </div>
</header>

<main class="main-wrap">
  <?php if (is_array($flash) && ($flash['message'] ?? '') !== ''): ?>
    <div class="container pt-4">
      <div class="alert<?= ($flash['type'] ?? 'success') === 'error' ? ' alert-danger' : ' alert-success' ?>">
        <?= e($flash['message'] ?? '') ?>
      </div>
    </div>
  <?php endif; ?>

  <?php include __DIR__ . '/' . $view . '.php'; ?>
</main>

<footer class="site-footer">
  <div class="container py-3 text-center">
    <p class="mb-0">&copy; <?= e(date('Y')) ?> Кінотеатр. Усі права захищені.</p>
  </div>
</footer>
</body>
</html>
