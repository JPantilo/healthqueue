<?php
// Same-domain JSON endpoint used by the booking form (browser -> this file -> OpenWeatherMap).
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/weather.php';

header('Content-Type: application/json; charset=utf-8');

$centerId = (int)($_GET['center_id'] ?? 0);
$date     = trim($_GET['date'] ?? date('Y-m-d'));
$time     = trim($_GET['time'] ?? '09:00:00');

if (!$centerId) {
    echo json_encode(['available' => false, 'message' => 'Center not specified.']);
    exit;
}

$wx = weather_for($centerId, $date, $time);
if (!$wx) {
    echo json_encode(['available' => false, 'message' => 'Forecast not available for this time.']);
    exit;
}

$advisory = weather_advisory($wx);
echo json_encode([
    'available' => true,
    'temp'      => $wx['temp'],
    'desc'      => $wx['desc'],
    'icon'      => $wx['icon'],
    'pop'       => $wx['pop'],
    'advisory'  => $advisory,
]);