<?php
// Resend email API. PHP mail() is blocked on InfinityFree, so we use HTTPS.
require_once __DIR__ . '/init.php';

function mailer_configured()
{
    $k = cfg('resend')['api_key'] ?? '';
    return $k !== '' && strpos($k, 'xxxx') === false;
}

function verification_email(array $user, $token)
{
    if (!mailer_configured() || empty($user['email'])) {
        return false;
    }
    $url = cfg('base_url') . '/verify_email.php?token=' . rawurlencode($token);
    $html = "<div style='font-family:sans-serif;line-height:1.6;color:#333;max-width:550px;margin:auto;'>";
    $html .= "<h2 style='color:#0d9488;'>HealthQueue</h2>";
    $html .= "<p>Hello <b>" . e($user['name']) . "</b>,</p>";
    $html .= "<p>Click the button below to verify your email address. This link expires in 24 hours.</p>";
    $html .= "<p><a href='" . e($url) . "' style='display:inline-block;padding:12px 18px;background:#0d9488;color:#fff;text-decoration:none;border-radius:6px;'>Verify email</a></p>";
    $html .= "<p>If you did not create this account, you can ignore this message.</p></div>";
    $r = post_json('https://api.resend.com/emails', [
        'from' => cfg('resend')['from'] ?? 'HealthQueue <onboarding@resend.dev>',
        'to' => [$user['email']],
        'subject' => 'Verify your HealthQueue email',
        'html' => $html,
    ], ['Authorization: Bearer ' . cfg('resend')['api_key']]);
    return $r['ok'];
}

function appointment_email($action, array $patient, array $appt, $centerName, $serviceName, $advisory = null)
{
    if (!mailer_configured() || empty($patient['email'])) {
        return false;
    }

    $subjects = [
        'created'     => "HealthQueue: Appointment Confirmed (#{$appt['queue_no']})",
        'rescheduled' => "HealthQueue: Appointment Rescheduled (#{$appt['queue_no']})",
        'cancelled'   => "HealthQueue: Appointment Cancelled",
        'next'        => "HealthQueue: You are next in line! (#{$appt['queue_no']})",
    ];
    $subject = $subjects[$action] ?? "HealthQueue Appointment Update";

    $dateFormatted = date('F j, Y', strtotime($appt['appt_date']));
    $timeFormatted = date('g:i A', strtotime($appt['appt_time']));

    $html = "<div style='font-family:sans-serif;line-height:1.6;color:#333;max-width:550px;margin:auto;'>";
    $html .= "<h2 style='color:#0d9488;'>HealthQueue</h2>";
    $html .= "<p>Hello <b>" . e($patient['name']) . "</b>,</p>";

    if ($action === 'created') {
        $html .= "<p>Your appointment has been successfully booked!</p>";
    } elseif ($action === 'rescheduled') {
        $html .= "<p>Your appointment has been rescheduled with the following new details:</p>";
    } elseif ($action === 'cancelled') {
        $html .= "<p>Your appointment for <b>" . e($serviceName) . "</b> on {$dateFormatted} at {$timeFormatted} has been cancelled.</p>";
    } elseif ($action === 'next') {
        $html .= "<p style='font-size:1.1rem;color:#b45309;'><b>You are next in line!</b> Please prepare to enter consultation shortly.</p>";
    }

    if ($action !== 'cancelled') {
        $html .= "<div style='background:#f3f4f6;padding:15px;border-radius:8px;margin:15px 0;'>";
        $html .= "<p style='margin:4px 0;'><strong>Queue Number:</strong> <span style='font-size:1.2rem;color:#0d9488;'>#" . e($appt['queue_no']) . "</span></p>";
        $html .= "<p style='margin:4px 0;'><strong>Service:</strong> " . e($serviceName) . "</p>";
        $html .= "<p style='margin:4px 0;'><strong>Center:</strong> " . e($centerName) . "</p>";
        $html .= "<p style='margin:4px 0;'><strong>Date & Time:</strong> {$dateFormatted} at {$timeFormatted}</p>";
        $html .= "</div>";
    }

    if ($advisory) {
        $html .= "<p style='background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 12px;border-radius:4px;'><strong>Weather Advisory:</strong> " . e($advisory) . "</p>";
    }

    $html .= "<p style='font-size:0.85rem;color:#6b7280;margin-top:25px;'>HealthQueue &middot; Clinic Appointment &amp; Queue Management</p>";
    $html .= "</div>";

    $r = post_json('https://api.resend.com/emails', [
        'from'    => cfg('resend')['from'] ?? 'HealthQueue <onboarding@resend.dev>',
        'to'      => [$patient['email']],
        'subject' => $subject,
        'html'    => $html,
    ], [
        'Authorization: Bearer ' . cfg('resend')['api_key'],
    ]);

    return $r['ok'];
}