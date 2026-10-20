<?php
require_once __DIR__ . '/includes/google.php';

if (!isset($_GET['state']) || !hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'])) {
    exit('Invalid login state. Please try signing in again.');
}
if (isset($_GET['error']) || empty($_GET['code'])) {
    flash('Google sign-in was cancelled.', 'err');
    redirect(cfg('base_url') . '/index.php');
}

$tok = google_exchange_code($_GET['code']);
$access = $tok['json']['access_token'] ?? null;
if (!$access) {
    exit('Could not complete Google sign-in. Check client ID, secret and redirect URI in config.php.');
}
$info = google_userinfo($access)['json'] ?? null;
if (empty($info['sub']) || empty($info['email'])) {
    exit('Could not read your Google profile.');
}

$refresh = $tok['json']['refresh_token'] ?? null;
$st = db()->prepare('SELECT * FROM users WHERE google_id = ?');
$st->execute([$info['sub']]);
$user = $st->fetch();

if (!$user) {
    $role = in_array(strtolower($info['email']), array_map('strtolower', cfg('admin_emails')), true) ? 'admin' : 'patient';
    db()->prepare('INSERT INTO users (google_id,email,name,picture,role,google_refresh_token) VALUES (?,?,?,?,?,?)')
        ->execute([$info['sub'], $info['email'], $info['name'] ?? $info['email'], $info['picture'] ?? null, $role, $refresh]);
    $uid = (int)db()->lastInsertId();
} else {
    $uid = (int)$user['id'];
    db()->prepare('UPDATE users SET name=?, picture=?, google_refresh_token=COALESCE(?, google_refresh_token) WHERE id=?')
        ->execute([$info['name'] ?? $user['name'], $info['picture'] ?? null, $refresh, $uid]);
}

session_regenerate_id(true);
$_SESSION['uid'] = $uid;
redirect(cfg('base_url') . '/appointments/index.php');