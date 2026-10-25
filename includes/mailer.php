<?php
// Resend email API. PHP mail() is blocked on InfinityFree, so we use HTTPS.
require_once __DIR__ . '/init.php';

function mailer_configured()
{
    $k = cfg('resend')['api_key'] ?? '';
    return $k !== '' && strpos($k, 'xxxx') === false;
}

function mailer_from()
{
    return cfg('mailer')['from'] ?? cfg('resend')['from'] ?? 'HealthQueue <onboarding@resend.dev>';
}

function smtp_expect($socket, $expected)
{
    do {
        $line = fgets($socket);
        if ($line === false || (int)substr($line, 0, 3) !== $expected) {
            return false;
        }
    } while (isset($line[3]) && $line[3] === '-');
    return true;
}

function send_mailpit_email($to, $subject, $html)
{
    $mailer = cfg('mailer');
    $socket = @fsockopen($mailer['host'] ?? '127.0.0.1', (int)($mailer['port'] ?? 1025), $errno, $error, 5);
    if (!$socket || !smtp_expect($socket, 220)) {
        if ($socket) {
            fclose($socket);
        }
        return false;
    }

    $from = mailer_from();
    $fromAddress = preg_match('/<([^>]+)>/', $from, $match) ? $match[1] : $from;
    foreach ([
        ['EHLO localhost', 250],
        ["MAIL FROM:<{$fromAddress}>", 250],
        ["RCPT TO:<{$to}>", 250],
        ['DATA', 354],
    ] as $command) {
        fwrite($socket, $command[0] . "\r\n");
        if (!smtp_expect($socket, $command[1])) {
            fclose($socket);
            return false;
        }
    }

    $message = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\n";
    $message .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
    $message .= preg_replace('/^\./m', '..', $html) . "\r\n.\r\n";
    fwrite($socket, $message);
    $sent = smtp_expect($socket, 250);
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return $sent;
}

function smtp_expect_code($socket, $expected)
{
    do {
        $line = fgets($socket);
        if ($line === false || (int)substr($line, 0, 3) !== $expected) {
            return false;
        }
    } while (isset($line[3]) && $line[3] === '-');
    return true;
}

function send_smtp_email($to, $subject, $html)
{
    $smtp = cfg('smtp');
    $socket = @stream_socket_client(
        'tcp://' . ($smtp['host'] ?? 'smtp.gmail.com') . ':' . (int)($smtp['port'] ?? 587),
        $errno,
        $error,
        15
    );
    if (!$socket || !smtp_expect_code($socket, 220)) {
        if ($socket) {
            fclose($socket);
        }
        return false;
    }

    fwrite($socket, "EHLO localhost\r\n");
    if (!smtp_expect_code($socket, 250)) {
        fclose($socket);
        return false;
    }
    fwrite($socket, "STARTTLS\r\n");
    if (!smtp_expect_code($socket, 220) || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        return false;
    }
    fwrite($socket, "EHLO localhost\r\n");
    if (!smtp_expect_code($socket, 250)) {
        fclose($socket);
        return false;
    }

    $smtpUser = $smtp['username'] ?? '';
    $smtpPassword = $smtp['password'] ?? '';
    fwrite($socket, "AUTH LOGIN\r\n");
    if (!smtp_expect_code($socket, 334)) {
        fclose($socket);
        return false;
    }
    fwrite($socket, base64_encode($smtpUser) . "\r\n");
    if (!smtp_expect_code($socket, 334)) {
        fclose($socket);
        return false;
    }
    fwrite($socket, base64_encode($smtpPassword) . "\r\n");
    if (!smtp_expect_code($socket, 235)) {
        fclose($socket);
        return false;
    }

    $from = mailer_from();
    $fromAddress = preg_match('/<([^>]+)>/', $from, $match) ? $match[1] : $from;
    foreach ([
        ["MAIL FROM:<{$fromAddress}>", 250],
        ["RCPT TO:<{$to}>", 250],
        ['DATA', 354],
    ] as $command) {
        fwrite($socket, $command[0] . "\r\n");
        if (!smtp_expect_code($socket, $command[1])) {
            fclose($socket);
            return false;
        }
    }

    $message = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\n";
    $message .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
    $message .= preg_replace('/^\./m', '..', $html) . "\r\n.\r\n";
    fwrite($socket, $message);
    $sent = smtp_expect_code($socket, 250);
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return $sent;
}

function send_email($to, $subject, $html)
{
    if ($to === '') {
        return false;
    }

    if ((cfg('mailer')['driver'] ?? 'resend') === 'mailpit') {
        return send_mailpit_email($to, $subject, $html);
    }
    if ((cfg('mailer')['driver'] ?? 'resend') === 'smtp') {
        return send_smtp_email($to, $subject, $html);
    }
    if (!mailer_configured()) {
        return false;
    }

    $r = post_json('https://api.resend.com/emails', [
        'from'    => mailer_from(),
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ], [
        'Authorization: Bearer ' . cfg('resend')['api_key'],
    ]);

    return $r['ok'];
}

function send_verification_email(array $user, $token)
{
    if (empty($user['email'])) {
        return false;
    }

    $url = app_url('verify_email.php?token=' . rawurlencode($token));
    $html = "<div style='font-family:sans-serif;line-height:1.6;color:#333;max-width:550px;margin:auto;'>";
    $html .= "<h2 style='color:#0d9488;'>HealthQueue</h2>";
    $html .= "<p>Hello <b>" . e($user['name']) . "</b>,</p>";
    $html .= "<p>Please verify your email address to activate your HealthQueue account.</p>";
    $html .= "<p><a href='" . e($url) . "' style='display:inline-block;background:#0f766e;color:#fff;padding:12px 18px;border-radius:6px;text-decoration:none;'>Verify email address</a></p>";
    $html .= "<p>This link expires in 24 hours. If you did not create this account, you can ignore this email.</p>";
    $html .= "<p style='font-size:0.85rem;color:#6b7280;margin-top:25px;'>HealthQueue &middot; Clinic Appointment &amp; Queue Management</p>";
    $html .= "</div>";

    return send_email($user['email'], 'Verify your HealthQueue email address', $html);
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
        'no_show'     => "HealthQueue: Appointment Marked No-show",
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
    } elseif ($action === 'no_show') {
        $html .= "<p>Your appointment for <b>" . e($serviceName) . "</b> on {$dateFormatted} at {$timeFormatted} was marked <b>no-show</b> because you did not arrive when called. This appointment is now closed.</p>";
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

    return send_email($patient['email'], $subject, $html);
}