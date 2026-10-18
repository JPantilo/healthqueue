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
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
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