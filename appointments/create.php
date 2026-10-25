<?php
// CREATE: saves to MySQL, then Google Calendar event + Resend email.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/appointments.php';
require_once __DIR__ . '/../includes/google.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/weather.php';
require_once __DIR__ . '/../includes/rules.php';

$u = require_patient();
guard_new_booking();
$pdo = db();

$centers = $pdo->query('SELECT * FROM centers ORDER BY name')->fetchAll();
$services = $pdo->query('SELECT * FROM services ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$data, $err] = validate_appointment($_POST);
    if ($err) {
        flash($err, 'err');
        $appt = $_POST;
    } else {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM centers WHERE id = ? FOR UPDATE');
        $lock->execute([$data['center_id']]);
        $limit = (int)cfg('daily_limit_per_center', 40);
        $st = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE center_id=? AND appt_date=? AND status != "cancelled"');
        $st->execute([$data['center_id'], $data['appt_date']]);
        if ((int)$st->fetchColumn() >= $limit) {
            $pdo->rollBack();
            flash('This center has reached its appointment capacity for the chosen date.', 'err');
            $appt = $_POST;
        } elseif (appointment_slot_taken($data['center_id'], $data['service_id'], $data['appt_date'], $data['appt_time'])) {
            $pdo->rollBack();
            flash('That department already has a queue within one hour of this time. Choose a time at least one hour apart.', 'err');
            $appt = $_POST;
        } else {
            $st = $pdo->prepare('SELECT id FROM appointments WHERE patient_id=? AND appt_date=? AND appt_time=? AND status != "cancelled"');
            $st->execute([$u['id'], $data['appt_date'], $data['appt_time']]);
            if ($st->fetch()) {
                $pdo->rollBack();
                flash('You already have an appointment at that time.', 'err');
                $appt = $_POST;
            } else {
                $st = $pdo->prepare('SELECT COALESCE(MAX(queue_no), 0) AS m FROM appointments WHERE center_id=? AND appt_date=?');
                $st->execute([$data['center_id'], $data['appt_date']]);
                $day = $st->fetch();
                $qno = (int)$day['m'] + 1;

                $pdo->prepare('INSERT INTO appointments (patient_id,center_id,service_id,appt_date,appt_time,notes,queue_no) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$u['id'], $data['center_id'], $data['service_id'], $data['appt_date'], $data['appt_time'], $data['notes'], $qno]);
                $id = (int)$pdo->lastInsertId();
                $pdo->commit();

                $row = array_merge($data, ['queue_no' => $qno]);
                [$cn, $sn] = names_for($data['center_id'], $data['service_id']);
                $evt = gcal_create($u, $row, $cn, $sn);
                if ($evt) {
                    $pdo->prepare('UPDATE appointments SET gcal_event_id=? WHERE id=?')->execute([$evt, $id]);
                }
                $advisory = weather_advisory(weather_for($data['center_id'], $data['appt_date'], $data['appt_time']));
                $mailed = appointment_email('created', $u, $row, $cn, $sn, $advisory);
                if ($evt) {
                    $extra = ' Added to Google Calendar.';
                } else {
                    $extra = ' Calendar sync failed: ' . (google_calendar_error() ?: 'Unknown Google Calendar error.');
                }
                $extra .= $mailed ? ' Email sent.' : '';
                $flashType = (!$evt || $advisory) ? 'warn' : 'ok';
                flash("Booked! Your queue number is #{$qno}." . $extra . ($advisory ? ' Weather: ' . $advisory : ''), $flashType);
                redirect('index.php');
            }
        }
    }
} else {
    $appt = null;
}

page_header('Book Appointment');
?>
<h2>Book a New Appointment</h2>
<p class="text-muted">Fill out the form below to secure your consultation slot and queue number.</p>
<?php require __DIR__ . '/_form.php'; ?>
<?php page_footer(); ?>