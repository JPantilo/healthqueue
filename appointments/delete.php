<?php
// DELETE (cancel): sets status=cancelled (keeps history for dashboard), removes Calendar event, emails patient.
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/google.php';
require_once __DIR__ . '/../includes/mailer.php';

$u = require_patient();
$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$st = $pdo->prepare('SELECT a.*, c.name AS center_name, s.name AS service_name FROM appointments a JOIN centers c ON a.center_id=c.id JOIN services s ON a.service_id=s.id WHERE a.id=?');
$st->execute([$id]);
$appt = $st->fetch();

if (!$appt || ($u['role'] === 'patient' && $appt['patient_id'] != $u['id'])) {
    flash('Appointment not found or permission denied.', 'err');
    redirect('index.php');
}
if (!in_array($appt['status'], ['pending', 'waiting'], true)) {
    flash('This appointment is already closed and cannot be cancelled again.', 'err');
    redirect('index.php');
}

$pdo->prepare('UPDATE appointments SET status="cancelled" WHERE id=?')->execute([$id]);

$pst = $pdo->prepare('SELECT * FROM users WHERE id=?');
$pst->execute([$appt['patient_id']]);
$patient = $pst->fetch() ?: $u;

if (!empty($appt['gcal_event_id'])) {
    gcal_delete($patient, $appt['gcal_event_id']);
}

appointment_email('cancelled', $patient, $appt, $appt['center_name'], $appt['service_name']);

flash('Appointment has been cancelled.', 'ok');
redirect('index.php');