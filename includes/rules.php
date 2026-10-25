<?php
// Patient booking rules, shared by the appointments pages.
//  - A patient with an open booking (pending, waiting or in consultation) cannot book again.
//  - A patient can edit/reschedule only while the booking is still pending.
//  - A patient can cancel while it is pending or waiting.
// Only patients may use appointment actions; workers and admins use queue.php.

function rules_user(): array {
    $st = db()->prepare('SELECT id, role FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid'] ?? 0]);
    return $st->fetch() ?: ['id' => 0, 'role' => ''];
}

function rules_back(string $msg): void {
    flash($msg, 'err');
    redirect(cfg('base_url') . '/appointments/index.php');
}

// Call at the top of appointments/create.php (before anything is shown or saved).
function guard_new_booking(): void {
    $u = rules_user();
    if ($u['role'] !== 'patient') { return; }
    $st = db()->prepare('SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status IN ("pending","waiting","in_consultation")');
    $st->execute([$u['id']]);
    if ((int)$st->fetchColumn() > 0) {
        rules_back('You already have a booking in the queue. Cancel it or wait until it is finished before booking again.');
    }
}

// Call at the top of appointments/edit.php ('edit') and appointments/delete.php ('cancel').
function guard_patient_change(string $what): void {
    $u = rules_user();
    if ($u['role'] !== 'patient') { return; }

    $id = 0;
    foreach (['id', 'appt_id', 'appointment_id'] as $k) {
        if (!empty($_POST[$k])) { $id = (int)$_POST[$k]; break; }
        if (!empty($_GET[$k]))  { $id = (int)$_GET[$k];  break; }
    }
    if (!$id) { return; }

    $st = db()->prepare('SELECT patient_id, status FROM appointments WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a || (int)$a['patient_id'] !== (int)$u['id']) {
        rules_back('Appointment not found.');
    }

    if ($what === 'edit' && $a['status'] !== 'pending') {
        rules_back('This booking was already accepted by the clinic, so it can no longer be changed.');
    }
    if ($what === 'cancel' && !in_array($a['status'], ['pending', 'waiting'], true)) {
        rules_back('This booking can no longer be cancelled.');
    }
}
