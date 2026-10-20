<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/mailer.php';

if (current_user()) {
    redirect(cfg('base_url') . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Enter your name and a valid email address.', 'err');
    } elseif (strlen($password) < 8) {
        flash('Password must be at least 8 characters.', 'err');
    } elseif ($password !== $confirm) {
        flash('Passwords do not match.', 'err');
    } else {
        $st = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            flash('That email is already registered. Please sign in instead.', 'err');
        } else {
            $token = bin2hex(random_bytes(32));
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo = db();
            $pdo->prepare('INSERT INTO users (google_id,email,name,password_hash,role,email_verified,verification_token,verification_expires) VALUES (?,?,?,?, "patient",0,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute(['local-' . bin2hex(random_bytes(16)), $email, $name, $hash, $token]);
            $user = ['name' => $name, 'email' => $email];
            $sent = verification_email($user, $token);
            if ($sent) {
                flash('Account created. Check your email to verify your account before signing in.', 'ok');
            } else {
                $pdo->prepare('DELETE FROM users WHERE email=? AND email_verified=0')->execute([$email]);
                flash('We could not send the verification email. Please check the Resend configuration and try again.', 'err');
            }
            redirect('login.php');
        }
    }
}

page_header('Create account');
?>
<div class="auth-card card">
  <div class="auth-heading">
    <h1>Create your account</h1>
    <p>Register as a patient to book and track appointments.</p>
  </div>
  <form method="POST" class="form">
    <div class="field"><label for="name">Full name</label><input type="text" name="name" id="name" autocomplete="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>
    <div class="field"><label for="email">Email address</label><input type="email" name="email" id="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
    <div class="field"><label for="password">Password</label><input type="password" name="password" id="password" minlength="8" autocomplete="new-password" required></div>
    <div class="field"><label for="confirm_password">Confirm password</label><input type="password" name="confirm_password" id="confirm_password" minlength="8" autocomplete="new-password" required></div>
    <button class="btn primary lg" type="submit">Create patient account</button>
  </form>
  <p class="auth-footer">Already have an account? <a href="login.php">Sign in</a></p>
</div>
<?php page_footer(); ?>
