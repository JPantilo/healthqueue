<?php
// Copy to config.php and fill in. NEVER commit config.php to GitHub.
return [
    'base_url' => 'http://localhost/healthqueue',

    'db' => [
        'host' => '127.0.0.1',
        'name' => 'healthqueue',
        'user' => 'root',
        'pass' => '',
    ],

    'google' => [
        'client_id'     => 'YOUR_GOOGLE_CLIENT_ID',
        'client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET',
    ],

    'resend' => [
        'api_key' => 're_xxxx',
        'from'    => 'HealthQueue <onboarding@resend.dev>',
    ],

    'mailer' => [
        'driver' => 'resend',
        'host'   => '127.0.0.1',
        'port'   => 1025,
        'from'   => 'HealthQueue <no-reply@localhost>',
    ],

    'smtp' => [
        'host'     => 'smtp.gmail.com',
        'port'     => 587,
        'username' => 'your-gmail@gmail.com',
        'password' => 'your-google-app-password',
    ],

    'openweather' => [
        'api_key' => 'YOUR_OPENWEATHER_API_KEY',
    ],

    'admin_emails' => ['your_email@gmail.com'],

    'timezone' => 'Asia/Manila',
    'daily_limit_per_center' => 40,
];