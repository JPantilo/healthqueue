<?php
// Validate posted booking fields. Returns [data|null, error|null]
require_once __DIR__ . '/init.php';

function validate_appointment(array $post)
{
    $centerId  = (int)($post['center_id'] ?? 0);
    $serviceId = (int)($post['service_id'] ?? 0);
    $date      = trim($post['appt_date'] ?? '');
    $time      = trim($post['appt_time'] ?? '');
    $notes     = substr(trim($post['notes'] ?? ''), 0, 255);

    if (!$centerId || !$serviceId) {
        return [null, 'Please select a health center and service.'];
    }
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [null, 'Please provide a valid appointment date (YYYY-MM-DD).'];
    }
    if ($date < date('Y-m-d')) {
        return [null, 'Appointment date cannot be in the past.'];
    }
    if (!$time || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        return [null, 'Please select a valid appointment time.'];
    }
    if (strlen($time) === 5) {
        $time .= ':00';
    }

    return [[
        'center_id'  => $centerId,
        'service_id' => $serviceId,
        'appt_date'  => $date,
        'appt_time'  => $time,
        'notes'      => $notes ?: null,
    ], null];
}

function names_for($centerId, $serviceId)
{
    $pdo = db();
    $c = $pdo->prepare('SELECT name FROM centers WHERE id=?');
    $c->execute([$centerId]);
    $centerName = $c->fetchColumn() ?: 'Health Center';

    $s = $pdo->prepare('SELECT name FROM services WHERE id=?');
    $s->execute([$serviceId]);
    $serviceName = $s->fetchColumn() ?: 'Consultation';

    return [$centerName, $serviceName];
}