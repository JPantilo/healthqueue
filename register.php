<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/mailer.php';

if (current_user()) {
    redirect(cfg('base_url') . role_destination(current_user()['role']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $mobile = preg_replace('/\s+/', '', trim($_POST['mobile'] ?? ''));
    $network = trim($_POST['mobile_network'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $networks = ['Smart', 'TNT', 'Globe', 'TM', 'DITO', 'GOMO', 'Other'];

    if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Enter your name and a valid email address.', 'err');
    } elseif (!preg_match('/^09\d{9}$/', $mobile)) {
        flash('Enter a valid Philippine mobile number, such as 09171234567.', 'err');
    } elseif (!in_array($network, $networks, true)) {
        flash('Select your mobile network.', 'err');
    } elseif (strlen($password) < 8) {
        flash('Password must be at least 8 characters.', 'err');
    } elseif ($password !== $confirm) {
        flash('Passwords do not match.', 'err');
    } else {
        $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            flash('That email is already registered. Please sign in instead.', 'err');
        } else {
            $googleId = 'local-' . bin2hex(random_bytes(16));
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32));
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO users (google_id,email,name,mobile,mobile_network,password_hash,role,email_verified_at,email_verification_token_hash,email_verification_expires_at) VALUES (?,?,?,?,?,?, "patient",NULL,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                    ->execute([$googleId, $email, $name, $mobile, $network, $hash, hash('sha256', $token)]);
                $pdo->commit();
                $_SESSION['verification_email'] = $email;
                $_SESSION['verification_name'] = $name;
                $_SESSION['verification_token'] = $token;
                flash('Account created. Please verify your email.', 'ok');
                redirect(app_url('verify_email.php?registered=1'));
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('Your account could not be created. Please try again.', 'err');
            }
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
    <div class="field">
      <label for="name">Full name</label>
      <input type="text" name="name" id="name" autocomplete="name" required value="<?= e($_POST['name'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" name="email" id="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="mobile">Mobile number</label>
      <input type="tel" name="mobile" id="mobile" autocomplete="tel" inputmode="numeric" minlength="11" maxlength="11" pattern="09[0-9]{9}" placeholder="09171234567" required value="<?= e($_POST['mobile'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="mobile_network">Mobile network</label>
      <select name="mobile_network" id="mobile_network" required>
        <option value="">Select network</option>
        <?php foreach (['Smart', 'TNT', 'Globe', 'TM', 'DITO', 'GOMO', 'Other'] as $network): ?>
          <option value="<?= e($network) ?>" <?= ($_POST['mobile_network'] ?? '') === $network ? 'selected' : '' ?>><?= e($network) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" name="password" id="password" autocomplete="new-password" minlength="8" required>
    </div>
    <div class="field">
      <label for="confirm_password">Confirm password</label>
      <input type="password" name="confirm_password" id="confirm_password" autocomplete="new-password" minlength="8" required>
    </div>
    <button class="btn primary lg" type="submit">Create patient account</button>
  </form>
  <p class="auth-footer">Already have an account? <a href="login.php">Sign in</a></p>
</div>
<?php page_footer(); ?>
