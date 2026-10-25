<?php
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect(cfg('base_url') . role_destination(current_user()['role']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        flash('Email or password is incorrect.', 'err');
    } elseif (empty($user['email_verified_at'])) {
        flash('Please verify your email address before signing in.', 'err');
        redirect(app_url('verify_email.php'));
    } else {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        redirect(cfg('base_url') . role_destination($user['role']));
    }
}

page_header('Sign in');
?>
<div class="auth-card card">
  <div class="auth-heading">
    <h1>Welcome back</h1>
    <p>Sign in to manage your HealthQueue appointments.</p>
  </div>
  <form method="POST" class="form">
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" name="email" id="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" name="password" id="password" autocomplete="current-password" required>
    </div>
    <button class="btn primary lg" type="submit">Sign in with email</button>
  </form>
  <div class="auth-divider"><span>or</span></div>
  <?php require_once __DIR__ . '/includes/google.php'; ?>
  <a class="btn secondary auth-google" href="<?= e(google_login_url()) ?>">Continue with Google</a>
  <p class="auth-footer">New patient? <a href="register.php">Create an account</a></p>
</div>
<?php page_footer(); ?>
