<?php
require_once __DIR__ . '/includes/layout.php';

$token = trim($_GET['token'] ?? '');
$st = db()->prepare('SELECT id FROM users WHERE verification_token=? AND email_verified=0 AND verification_expires > NOW() LIMIT 1');
$st->execute([$token]);
$userId = $st->fetchColumn();

if ($userId) {
    db()->prepare('UPDATE users SET email_verified=1, verification_token=NULL, verification_expires=NULL WHERE id=?')->execute([$userId]);
    flash('Email verified. You can now sign in.', 'ok');
} else {
    flash('This verification link is invalid or expired.', 'err');
}
redirect('login.php');
