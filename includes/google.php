<?php
// Google OAuth 2.0 (login) + Google Calendar API, via raw cURL.
require_once __DIR__ . '/init.php';

function google_login_url($intent = 'login')
{
    static $urls = [];
    if (isset($urls[$intent])) {
        return $urls[$intent];
    }
    $g = cfg('google');
    $_SESSION['oauth_state'] = bin2hex(random_bytes(12));
    $_SESSION['oauth_intent'] = $intent;
    $q = http_build_query([
        'client_id'     => $g['client_id'],
        'redirect_uri'  => cfg('base_url') . '/oauth_callback.php',
        'response_type' => 'code',
        'scope'         => 'openid email profile https://www.googleapis.com/auth/calendar.events',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $_SESSION['oauth_state'],
    ]);
    $urls[$intent] = 'https://accounts.google.com/o/oauth2/v2/auth?' . $q;
    return $urls[$intent];
}

function google_calendar_url()
{
    return google_login_url('calendar_sync');
}

function google_exchange_code($code)
{
    $g = cfg('google');
    return http_request('POST', 'https://oauth2.googleapis.com/token', http_build_query([
        'code'          => $code,
        'client_id'     => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'redirect_uri'  => cfg('base_url') . '/oauth_callback.php',
        'grant_type'    => 'authorization_code',
    ]), ['Content-Type: application/x-www-form-urlencoded']);
}

function google_userinfo($accessToken)
{
    return http_request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', null, [
        'Authorization: Bearer ' . $accessToken,
    ]);
}

function google_access_token(array $user)
{
    $GLOBALS['google_calendar_error'] = null;
    if (empty($user['google_refresh_token'])) {
        $GLOBALS['google_calendar_error'] = 'Google Calendar is not connected. Reconnect your Google account.';
        return null;
    }
    $g = cfg('google');
    $r = http_request('POST', 'https://oauth2.googleapis.com/token', http_build_query([
        'client_id'     => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'refresh_token' => $user['google_refresh_token'],
        'grant_type'    => 'refresh_token',
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    if (!$r['ok'] || empty($r['json']['access_token'])) {
        $message = $r['json']['error_description'] ?? $r['json']['error'] ?? 'Google rejected the refresh token.';
        $GLOBALS['google_calendar_error'] = 'Google authorization failed: ' . $message;
        return null;
    }
    return $r['json']['access_token'];
}

function google_calendar_error()
{
    return $GLOBALS['google_calendar_error'] ?? null;
}

function google_calendar_api_error(array $response)
{
    $error = $response['json']['error'] ?? null;
    if (is_array($error) && !empty($error['message'])) {
        return $error['message'];
    }
    return 'Google Calendar rejected the request (HTTP ' . (int)$response['code'] . ').';
}

function gcal_body(array $appt, $centerName, $serviceName)
{
    $start = new DateTime($appt['appt_date'] . ' ' . $appt['appt_time']);
    $end = (clone $start)->modify('+30 minutes');
    return [
        'summary'     => "Health visit: {$serviceName}",
        'location'    => $centerName,
        'description' => "Queue no. {$appt['queue_no']}. Booked via HealthQueue.",
        'start'       => ['dateTime' => $start->format('c'), 'timeZone' => cfg('timezone', 'Asia/Manila')],
        'end'         => ['dateTime' => $end->format('c'), 'timeZone' => cfg('timezone', 'Asia/Manila')],
        'reminders'   => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 60]]],
    ];
}

function gcal_create(array $user, array $appt, $centerName, $serviceName)
{
    $tok = google_access_token($user);
    if (!$tok) {
        return null;
    }
    $r = post_json('https://www.googleapis.com/calendar/v3/calendars/primary/events', gcal_body($appt, $centerName, $serviceName), [
        'Authorization: Bearer ' . $tok,
    ]);
    if (!$r['ok'] || empty($r['json']['id'])) {
        $GLOBALS['google_calendar_error'] = google_calendar_api_error($r);
        return null;
    }
    return $r['json']['id'];
}

function gcal_update(array $user, $eventId, array $appt, $centerName, $serviceName)
{
    $tok = google_access_token($user);
    if (!$tok || !$eventId) {
        return false;
    }
    $r = http_request('PUT', 'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($eventId),
        json_encode(gcal_body($appt, $centerName, $serviceName)),
        ['Authorization: Bearer ' . $tok, 'Content-Type: application/json']
    );
    return $r['code'] === 200;
}

function gcal_delete(array $user, $eventId)
{
    $tok = google_access_token($user);
    if (!$tok || !$eventId) {
        return false;
    }
    $r = http_request('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($eventId), null, [
        'Authorization: Bearer ' . $tok,
    ]);
    return $r['code'] === 204 || $r['code'] === 200 || $r['code'] === 404;
}