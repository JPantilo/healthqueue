<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/mailer.php';

$existingUser = current_user();
if ($existingUser && !empty($existingUser['email_verified_at'])) {
    redirect(app_url(role_destination($existingUser['role'])));
}
if ($existingUser) {
    unset($_SESSION['uid']);
}

$verified = false;
$tokenError = false;

if (!empty($_GET['registered']) && !empty($_SESSION['verification_token']) && !empty($_SESSION['verification_email'])) {
    $sent = send_verification_email([
        'email' => $_SESSION['verification_email'],
        'name' => $_SESSION['verification_name'] ?? 'there',
    ], $_SESSION['verification_token']);
    unset($_SESSION['verification_token']);
    flash(
        $sent
            ? 'A verification email has been sent.'
            : 'We could not send the verification email. Use the resend option below.',
        $sent ? 'ok' : 'err'
    );
}

if (!empty($_GET['token'])) {
    $tokenHash = hash('sha256', (string)$_GET['token']);
    $st = db()->prepare('SELECT id FROM users WHERE email_verification_token_hash = ? AND email_verification_expires_at > NOW() AND email_verified_at IS NULL LIMIT 1');
    $st->execute([$tokenHash]);
    $user = $st->fetch();
    if ($user) {
        db()->prepare('UPDATE users SET email_verified_at = NOW(), email_verification_token_hash = NULL, email_verification_expires_at = NULL WHERE id = ?')
            ->execute([(int)$user['id']]);
        unset($_SESSION['verification_email'], $_SESSION['verification_name']);
        $verified = true;
    } else {
        $tokenError = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ($_SESSION['verification_email'] ?? '')));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $st = db()->prepare('SELECT id,email,name FROM users WHERE email = ? AND email_verified_at IS NULL LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            db()->prepare('UPDATE users SET email_verification_token_hash = ?, email_verification_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?')
                ->execute([hash('sha256', $token), (int)$user['id']]);
            if (!send_verification_email($user, $token)) {
                flash('We could not send the verification email. Please try again later.', 'err');
            } else {
                flash('A new verification email has been sent.', 'ok');
            }
        } else {
            flash('If that account needs verification, a new email has been sent.', 'ok');
        }
    } else {
        flash('Enter a valid email address.', 'err');
    }
}

page_header('Verify your email');
?>
<?php $pendingEmail = $_SESSION['verification_email'] ?? ''; ?>
<div class="auth-card card verification-card">
  <?php if (!$verified): ?>
    <p class="verification-label">Email verification required</p>
  <?php endif; ?>
  <div class="auth-heading">
    <h1><?= $verified ? 'Email verified' : 'Check your inbox' ?></h1>
    <?php if ($verified): ?>
      <p>Your email address is verified. You can now sign in to HealthQueue.</p>
    <?php elseif ($tokenError): ?>
      <p>This verification link is invalid or has expired. Request a new one below.</p>
    <?php else: ?>
      <p>Hello <?= e($_SESSION['verification_name'] ?? 'there') ?>. We sent a verification link to:</p>
      <?php if ($pendingEmail): ?><div class="verification-email"><?= e($pendingEmail) ?></div><?php endif; ?>
      <p>Please open the email from HealthQueue and click <strong>"Verify email address"</strong> to activate your account.</p>
    <?php endif; ?>
  </div>
  <?php if ($verified): ?>
    <a class="btn primary lg" href="login.php">Sign in</a>
  <?php else: ?>
    <form method="POST" class="<?= $pendingEmail ? 'verification-resend' : 'form' ?>">
      <?php if ($pendingEmail): ?>
        <input type="hidden" name="email" value="<?= e($pendingEmail) ?>">
      <?php else: ?>
        <div class="field">
          <label for="email">Email address</label>
          <input type="email" name="email" id="email" autocomplete="email" required>
        </div>
      <?php endif; ?>
      <button class="btn primary lg" type="submit">Resend verification email</button>
    </form>
    <a class="auth-footer verification-signout" href="logout.php">Sign out</a>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
