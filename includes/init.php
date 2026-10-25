<?php
// Core: config, session, PDO, helpers, auth guards. Compatible with PHP 7.4+.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function cfg($key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/../config.php';
        if (!file_exists($file)) {
            $file = __DIR__ . '/../config.sample.php';
        }
        $config = file_exists($file) ? require $file : [];
    }
    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? $default;
}

if ($tz = cfg('timezone')) {
    date_default_timezone_set($tz);
}

function db()
{
    static $pdo = null;
    static $schemaReady = false;
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    if (!$schemaReady) {
        $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified_at'")->fetch();
        if (!$columns) {
            $pdo->exec('ALTER TABLE users ADD email_verified_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
        }
        $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verification_token_hash'")->fetch();
        if (!$columns) {
            $pdo->exec('ALTER TABLE users ADD email_verification_token_hash CHAR(64) DEFAULT NULL');
        }
        $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verification_expires_at'")->fetch();
        if (!$columns) {
            $pdo->exec('ALTER TABLE users ADD email_verification_expires_at DATETIME DEFAULT NULL');
        }
        $schemaReady = true;
    }
    return $pdo;
}

function e($str)
{
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function flash($msg = null, $type = 'ok')
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['m' => $msg, 't' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect($url)
{
    header("Location: $url");
    exit;
}

function app_url($path = '')
{
    $base = rtrim((string)cfg('base_url'), '/');
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $applicationRoot = realpath(__DIR__ . '/..');

    if ($documentRoot && $applicationRoot && strpos($applicationRoot, $documentRoot) === 0) {
        $relativeRoot = str_replace('\\', '/', substr($applicationRoot, strlen($documentRoot)));
        $base = rtrim($base, '/');
        $configuredPath = parse_url($base, PHP_URL_PATH) ?: '';
        if ($configuredPath !== $relativeRoot) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? parse_url($base, PHP_URL_HOST);
            $base = $scheme . '://' . $host . rtrim($relativeRoot, '/');
        }
    }

    return $base . '/' . ltrim($path, '/');
}

function current_user()
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) {
        $user = null;
        return null;
    }
    try {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$uid]);
        $user = $st->fetch() ?: null;
    } catch (Exception $ex) {
        $user = null;
    }
    return $user;
}

function require_login()
{
    $u = current_user();
    if (!$u) {
        flash('Please sign in first.', 'err');
        redirect(cfg('base_url') . '/index.php');
    }
    if (empty($u['email_verified_at'])) {
        unset($_SESSION['uid']);
        flash('Please verify your email address before accessing appointments.', 'err');
        redirect(app_url('verify_email.php'));
    }
    return $u;
}

function require_role($roles)
{
    $u = require_login();
    $roles = (array)$roles;
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied. Required role: ' . implode(' or ', $roles));
    }
    return $u;
}

function require_patient()
{
    $u = require_login();
    if ($u['role'] !== 'patient') {
        flash('Only patients can create or manage appointments.', 'err');
        redirect(cfg('base_url') . '/index.php');
    }
    return $u;
}

function local_login_url()
{
    return cfg('base_url') . '/login.php';
}

function role_destination($role)
{
    return $role === 'admin' ? '/admin/dashboard.php' : ($role === 'worker' ? '/queue.php' : '/appointments/index.php');
}

function http_request($method, $url, $body = null, array $headers = [])
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);
    return [
        'ok'   => ($code >= 200 && $code < 300),
        'code' => $code,
        'body' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function post_json($url, array $data, array $headers = [])
{
    $headers[] = 'Content-Type: application/json';
    return http_request('POST', $url, json_encode($data), $headers);
}