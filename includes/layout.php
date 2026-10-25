<?php
require_once __DIR__ . '/init.php';

function page_header($title, $extraHead = '')
{
    $u    = current_user();
    $base = cfg('base_url');
    $f    = flash();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> — HealthQueue</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="<?= e($base) ?>/assets/style.css">
<?= $extraHead ?>
</head>
<body>
<header class="top"><div class="wrap bar">
  <a class="brand" href="<?= e($base) ?>/index.php"><span class="brand-mark" aria-hidden="true">+</span>HealthQueue</a>
  <nav>
  <?php if ($u): ?>
    <?php if ($u['role'] === 'patient'): ?><a href="<?= e($base) ?>/appointments/index.php">Appointments</a><?php endif; ?>
    <?php if (in_array($u['role'], ['worker','admin'], true)): ?><a href="<?= e($base) ?>/queue.php">Queue</a><?php endif; ?>
    <?php if ($u['role'] === 'admin'): ?>
      <a href="<?= e($base) ?>/admin/dashboard.php">Dashboard</a>
      <a href="<?= e($base) ?>/admin/users.php">Users</a>
      <a href="<?= e($base) ?>/staff.php">Staff</a>
    <?php endif; ?>
    <span class="who"><span class="who-dot" aria-hidden="true"></span><?= e($u['name']) ?> <em><?= e($u['role']) ?></em></span>
    <a href="<?= e($base) ?>/logout.php">Logout</a>
  <?php else: ?>
    <?php require_once __DIR__ . '/google.php'; ?>
    <a href="<?= e(cfg('base_url')) ?>/login.php">Sign in</a>
    <a class="btn primary" href="<?= e(google_login_url()) ?>">Sign in with Google</a>
  <?php endif; ?>
  </nav>
</div></header>
<main class="wrap">
<?php if ($f): ?><div class="flash <?= e($f['t']) ?>"><?= e($f['m']) ?></div><?php endif;
}

function page_footer()
{
    ?>
</main>
<footer class="foot wrap">
  <p>&copy; <?= date('Y') ?> HealthQueue &middot; Clinic Appointment &amp; Queue Management System &middot; Iligan City</p>
</footer>
</body></html>
<?php
}
