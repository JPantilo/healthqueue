<?php
// UPDATE: reschedule. Updates MySQL, Google Calendar event, and sends Resend email.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/appointments.php';
require_once __DIR__ . '/../includes/google.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/weather.php';

$u = require_patient();
$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$st = $pdo->prepare('SELECT * FROM appointments WHERE id=?');
$st->execute([$id]);
$appt = $st->fetch();

if (!$appt || ($u['role'] === 'patient' && $appt['patient_id'] != $u['id'])) {
    flash('Appointment not found or permission denied.', 'err');
    redirect('index.php');
}
if ($appt['status'] !== 'pending') {
    flash('Only pending appointments can be rescheduled.', 'err');
    redirect('index.php');
}

$centers = $pdo->query('SELECT * FROM centers ORDER BY name')->fetchAll();
$services = $pdo->query('SELECT * FROM services ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$data, $err] = validate_appointment($_POST);
    if ($err) {
        flash($err, 'err');
    } elseif (appointment_slot_taken($data['center_id'], $data['service_id'], $data['appt_date'], $data['appt_time'], $id)) {
        flash('That department already has a queue within one hour of this time. Choose a time at least one hour apart.', 'err');
    } else {
        $dateChanged = ($data['appt_date'] !== $appt['appt_date'] || $data['center_id'] != $appt['center_id']);
        $qno = $appt['queue_no'];
        if ($dateChanged) {
            $st = $pdo->prepare('SELECT COALESCE(MAX(queue_no), 0) AS m FROM appointments WHERE center_id=? AND appt_date=?');
            $st->execute([$data['center_id'], $data['appt_date']]);
            $qno = (int)$st->fetchColumn() + 1;
        }

        $pdo->prepare('UPDATE appointments SET center_id=?, service_id=?, appt_date=?, appt_time=?, notes=?, queue_no=? WHERE id=?')
            ->execute([$data['center_id'], $data['service_id'], $data['appt_date'], $data['appt_time'], $data['notes'], $qno, $id]);

        $row = array_merge($data, ['queue_no' => $qno]);
        [$cn, $sn] = names_for($data['center_id'], $data['service_id']);

        $pst = $pdo->prepare('SELECT * FROM users WHERE id=?');
        $pst->execute([$appt['patient_id']]);
        $patient = $pst->fetch() ?: $u;

        if (!empty($appt['gcal_event_id'])) {
            gcal_update($patient, $appt['gcal_event_id'], $row, $cn, $sn);
        }

        $advisory = weather_advisory(weather_for($data['center_id'], $data['appt_date'], $data['appt_time']));
        appointment_email('rescheduled', $patient, $row, $cn, $sn, $advisory);

        flash("Appointment updated! Queue number is #{$qno}." . ($advisory ? ' Weather: ' . $advisory : ''), 'ok');
        redirect('index.php');
    }
}

page_header('Reschedule Appointment');
?>
<h2>Reschedule Appointment #<?= e($appt['queue_no']) ?></h2>
<?php require __DIR__ . '/_form.php'; ?>
<?php page_footer(); ?>