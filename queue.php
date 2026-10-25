<?php
// Worker queue board.
// Workers only see and act on their own clinic (users.center_id). Admins can switch clinics.
// Flow: patient books -> pending -> worker accepts -> waiting -> in consultation -> done / no-show.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/mailer.php';

require_role(['worker', 'admin']);
$pdo = db();

$st = $pdo->prepare('SELECT role, center_id FROM users WHERE id = ?');
$st->execute([$_SESSION['uid']]);
$me = $st->fetch();
$isAdmin = $me['role'] === 'admin';

// Departments (services) this worker handles. Admins are not limited.
$svcIds = [];
$svcNames = [];
if (!$isAdmin) {
    $ds = $pdo->prepare('SELECT s.id, s.name FROM worker_services ws JOIN services s ON s.id = ws.service_id WHERE ws.user_id = ? ORDER BY s.name');
    $ds->execute([$_SESSION['uid']]);
    foreach ($ds->fetchAll() as $r) { $svcIds[] = (int)$r['id']; $svcNames[] = $r['name']; }
}
$svcList  = implode(',', $svcIds ?: [0]);
$svcPlain = $isAdmin ? '' : " AND service_id IN ($svcList)";
$svcA     = $isAdmin ? '' : " AND a.service_id IN ($svcList)";

// Which clinic is this screen for?
if ($isAdmin) {
    $centers = $pdo->query('SELECT * FROM centers ORDER BY name')->fetchAll();
    $centerId = (int)($_GET['center_id'] ?? ($centers[0]['id'] ?? 0));
} else {
    $centerId = (int)$me['center_id'];
    $stc = $pdo->prepare('SELECT * FROM centers WHERE id = ?');
    $stc->execute([$centerId]);
    $centers = $stc->fetchAll();
}
$center = null;
foreach ($centers as $c) {
    if ((int)$c['id'] === $centerId) { $center = $c; }
}

$today = date('Y-m-d');
$active = ['pending', 'waiting', 'in_consultation'];

// ---------- Actions (always limited to this clinic) ----------
if ($center && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $apptId = (int)($_POST['appt_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $back = 'queue.php?center_id=' . $centerId;

    if ($action === 'accept') {
        $lookup = $pdo->prepare('SELECT appt_date FROM appointments WHERE id=? AND center_id=? AND status="pending"' . $svcPlain);
        $lookup->execute([$apptId, $centerId]);
        $acceptedDate = $lookup->fetchColumn();
        $n = $pdo->prepare('UPDATE appointments SET status="waiting" WHERE id=? AND center_id=? AND status="pending"' . $svcPlain);
        $n->execute([$apptId, $centerId]);
        if ($n->rowCount()) {
            $dateLabel = $acceptedDate === $today ? "today's queue" : date('F j, Y', strtotime($acceptedDate));
            flash("Booking accepted. The patient is in {$dateLabel}.", 'ok');
        } else {
            flash('That booking can no longer be accepted.', 'err');
        }
        redirect($back);
    }

    if ($action === 'call' || $action === 'done' || $action === 'no_show') {
        $map = [
            'call' => ['waiting', 'in_consultation'],
            'done' => ['in_consultation', 'done'],
        ];
        if ($action === 'no_show') {
            $n = $pdo->prepare('UPDATE appointments SET status="no_show" WHERE id=? AND center_id=? AND status IN ("pending","waiting")' . $svcPlain);
            $n->execute([$apptId, $centerId]);
        } else {
            [$from, $to] = $map[$action];
            $n = $pdo->prepare('UPDATE appointments SET status=? WHERE id=? AND center_id=? AND status=?' . $svcPlain);
            $n->execute([$to, $apptId, $centerId, $from]);
        }

        if ($n->rowCount() && $action === 'done') {
            $nextQuery = 'SELECT a.*, u.name, u.email, s.name AS service_name, c.name AS center_name
                          FROM appointments a
                          JOIN users u ON a.patient_id=u.id
                          JOIN services s ON a.service_id=s.id
                          JOIN centers c ON a.center_id=c.id
                          WHERE a.center_id=? AND a.appt_date=? AND a.status="waiting"' . $svcA . '
                          ORDER BY a.queue_no ASC LIMIT 1';
            $nextSt = $pdo->prepare($nextQuery);
            $nextSt->execute([$centerId, $today]);
            $next = $nextSt->fetch();
            if ($next) {
                $promote = $pdo->prepare('UPDATE appointments SET status="in_consultation" WHERE id=? AND status="waiting"');
                $promote->execute([$next['id']]);
                if ($promote->rowCount()) {
                    appointment_email('next', $next, $next, $next['center_name'], $next['service_name']);
                }
            }
        }

        if ($n->rowCount() && $action === 'call') {
            $called = $pdo->prepare('SELECT u.name, u.email, u.mobile, u.mobile_network
                                     FROM appointments a
                                     JOIN users u ON a.patient_id=u.id
                                     WHERE a.id=?');
            $called->execute([$apptId]);
            $calledPatient = $called->fetch();
            if ($calledPatient) {
                $_SESSION['queue_patient_modal'] = $calledPatient;
            }
            // Tell the next waiting patient that they are up next.
            $nx = $pdo->prepare('SELECT a.*, u.name, u.email, s.name AS service_name, c.name AS center_name
                                 FROM appointments a
                                 JOIN users u ON a.patient_id=u.id
                                 JOIN services s ON a.service_id=s.id
                                 JOIN centers c ON a.center_id=c.id
                                 WHERE a.center_id=? AND a.appt_date=? AND a.status="waiting"' . $svcA . '
                                 ORDER BY a.queue_no ASC LIMIT 1');
            $nx->execute([$centerId, $today]);
            $next = $nx->fetch();
            if ($next) {
                appointment_email('next', $next, $next, $next['center_name'], $next['service_name']);
            }
        }
        if ($n->rowCount() && $action === 'no_show') {
            $missed = $pdo->prepare('SELECT a.*, u.name, u.email, s.name AS service_name, c.name AS center_name
                                    FROM appointments a
                                    JOIN users u ON a.patient_id=u.id
                                    JOIN services s ON a.service_id=s.id
                                    JOIN centers c ON a.center_id=c.id
                                    WHERE a.id=?');
            $missed->execute([$apptId]);
            $patient = $missed->fetch();
            if ($patient) {
                appointment_email('no_show', $patient, $patient, $patient['center_name'], $patient['service_name']);
            }
        }
        $message = 'Queue updated.';
        if ($action === 'done' && !empty($next) && !empty($promote) && $promote->rowCount()) {
            $message = 'Patient marked done. The next patient is now in consultation.';
        }
        flash($n->rowCount() ? $message : 'That patient is no longer in that step.', $n->rowCount() ? 'ok' : 'err');
        redirect($back);
    }

    if ($action === 'reschedule') {
        $newDate = $_POST['new_date'] ?? '';
        $newTime = $_POST['new_time'] ?? '';
        $okDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) && strtotime($newDate) !== false;
        $okTime = preg_match('/^\d{2}:\d{2}$/', $newTime);

        if (!$okDate || !$okTime || $newDate < $today) {
            flash('Choose a valid date (today or later) and time.', 'err');
            redirect($back);
        }

        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT * FROM appointments WHERE id=? AND center_id=? AND status IN ("pending","waiting")' . $svcPlain . ' FOR UPDATE');
            $q->execute([$apptId, $centerId]);
            $appt = $q->fetch();
            if (!$appt) { throw new RuntimeException('That booking cannot be rescheduled.'); }

            $queueNo = (int)$appt['queue_no'];
            if ($newDate !== $appt['appt_date']) {
                // One active booking per patient per day, across all clinics.
                $d = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE patient_id=? AND appt_date=? AND id<>? AND status IN ("pending","waiting","in_consultation")');
                $d->execute([$appt['patient_id'], $newDate, $apptId]);
                if ((int)$d->fetchColumn() > 0) {
                    throw new RuntimeException('This patient already has a booking on that date.');
                }
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE center_id=? AND appt_date=? AND status<>"cancelled"');
                $cnt->execute([$centerId, $newDate]);
                if ((int)$cnt->fetchColumn() >= (int)cfg('daily_limit_per_center')) {
                    throw new RuntimeException('That date is full for this clinic.');
                }
                // New day, new number. MAX includes cancelled rows so a number is never reused.
                $mx = $pdo->prepare('SELECT COALESCE(MAX(queue_no),0)+1 FROM appointments WHERE center_id=? AND appt_date=? FOR UPDATE');
                $mx->execute([$centerId, $newDate]);
                $queueNo = (int)$mx->fetchColumn();
            }

            $up = $pdo->prepare('UPDATE appointments SET appt_date=?, appt_time=?, queue_no=? WHERE id=? AND center_id=?');
            $up->execute([$newDate, $newTime . ':00', $queueNo, $apptId, $centerId]);
            $pdo->commit();
            flash('Rescheduled to ' . date('M j, Y', strtotime($newDate)) . ' at ' . date('g:i A', strtotime($newTime)) . '. Queue number is #' . $queueNo . '.', 'ok');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $msg = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not reschedule. Please try again.';
            flash($msg, 'err');
        }
        redirect($back);
    }
}

// ---------- Data for the page ----------
$pending = $pendingToday = $pendingTomorrow = $pendingLater = [];
$waitingNow = $inConsult = $doneToday = $upcomingActive = [];
if ($center) {
    $p = $pdo->prepare('SELECT a.*, u.name AS patient_name, s.name AS service_name
                        FROM appointments a
                        JOIN users u ON a.patient_id=u.id
                        JOIN services s ON a.service_id=s.id
                        WHERE a.center_id=? AND a.status="pending" AND a.appt_date>=?' . $svcA . '
                        ORDER BY a.appt_date, a.queue_no');
    $p->execute([$centerId, $today]);
    $pending = $p->fetchAll();
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    foreach ($pending as $booking) {
        if ($booking['appt_date'] === $today) {
            $pendingToday[] = $booking;
        } elseif ($booking['appt_date'] === $tomorrow) {
            $pendingTomorrow[] = $booking;
        } else {
            $pendingLater[] = $booking;
        }
    }

    $t = $pdo->prepare('SELECT a.*, u.name AS patient_name, s.name AS service_name
                        FROM appointments a
                        JOIN users u ON a.patient_id=u.id
                        JOIN services s ON a.service_id=s.id
                        WHERE a.center_id=? AND a.appt_date=?
                          AND a.status IN ("waiting","in_consultation","done")' . $svcA . '
                        ORDER BY a.queue_no');
    $t->execute([$centerId, $today]);
    foreach ($t->fetchAll() as $r) {
        if ($r['status'] === 'waiting') { $waitingNow[] = $r; }
        elseif ($r['status'] === 'in_consultation') { $inConsult[] = $r; }
        else { $doneToday[] = $r; }
    }

    $u = $pdo->prepare('SELECT a.*, u.name AS patient_name, s.name AS service_name
                        FROM appointments a
                        JOIN users u ON a.patient_id=u.id
                        JOIN services s ON a.service_id=s.id
                        WHERE a.center_id=? AND a.appt_date>? 
                          AND a.status IN ("waiting","in_consultation")' . $svcA . '
                        ORDER BY a.appt_date, a.queue_no');
    $u->execute([$centerId, $today]);
    $upcomingActive = $u->fetchAll();
}

$calledPatientModal = $_SESSION['queue_patient_modal'] ?? null;
unset($_SESSION['queue_patient_modal']);

function rs_form(array $a): string {
    ob_start(); ?>
    <details class="qb-resched">
      <summary>Reschedule</summary>
      <form method="POST" class="qb-rform">
        <input type="hidden" name="appt_id" value="<?= e($a['id']) ?>">
        <input type="hidden" name="action" value="reschedule">
        <input type="date" name="new_date" value="<?= e($a['appt_date']) ?>" min="<?= e(date('Y-m-d')) ?>" required>
        <input type="time" name="new_time" value="<?= e(substr($a['appt_time'], 0, 5)) ?>" required>
        <button class="qb-btn" type="submit">Save</button>
      </form>
    </details>
    <?php return ob_get_clean();
}

page_header('Queue');
?>

<div class="qb">
<?php if (!$center): ?>
  <div class="qb-top"><div><h1>Queue</h1></div></div>
  <div class="qb-empty">
    <?= $isAdmin ? 'No clinics found. Add one in the database first.' : 'Your account is not assigned to a clinic yet. Ask an admin to set your clinic on the Users page.' ?>
  </div>
<?php else: ?>
  <div class="qb-top">
    <div>
      <h1><?= e($center['name']) ?></h1>
      <p><?= date('l, F j, Y') ?> <span class="live-indicator"><i></i> Live board</span><?php if (!$isAdmin): ?> &middot; <?= $svcNames ? e(implode(', ', $svcNames)) : 'No department assigned' ?><?php endif; ?></p>
    </div>
    <div class="qb-tools">
      <?php if ($isAdmin && count($centers) > 1): ?>
        <form method="GET" class="qb-pick">
          <select name="center_id" onchange="this.form.submit()" aria-label="Clinic">
            <?php foreach ($centers as $c): ?>
              <option value="<?= e($c['id']) ?>" <?= (int)$c['id'] === $centerId ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
      <button type="button" class="btn secondary sm" onclick="window.location.reload()">Refresh queue</button>
    </div>
  </div>

  <?php if (!$isAdmin && !$svcIds): ?>
    <div class="qb-empty" style="margin-bottom:1.4rem">You have no departments yet, so no bookings are shown. Ask an admin to assign your departments.</div>
  <?php endif; ?>

  <div class="qb-stats">
    <div class="qb-stat"><strong><?= count($pendingToday) ?></strong><span>Today's requests</span></div>
    <div class="qb-stat"><strong><?= count($waitingNow) ?></strong><span>Waiting today</span></div>
    <div class="qb-stat"><strong><?= count($inConsult) ?></strong><span>In consultation</span></div>
    <div class="qb-stat"><strong><?= count($doneToday) ?></strong><span>Done today</span></div>
  </div>

  <div class="qb-cols">
    <section>
      <h2>Booking requests</h2>
      <?php if (!$pending): ?>
        <div class="qb-empty">No bookings are waiting for approval.</div>
      <?php else: ?>
        <?php
        $requestGroups = [
            ["Today's requests", $pendingToday],
            ['Tomorrow\'s requests', $pendingTomorrow],
            ['Later requests', $pendingLater],
        ];
        foreach ($requestGroups as $group):
            [$groupTitle, $groupBookings] = $group;
            if (!$groupBookings) { continue; }
        ?>
        <h3 class="qb-group-title"><?= e($groupTitle) ?></h3>
        <div class="qb-list">
          <?php foreach ($groupBookings as $a): ?>
            <div class="qb-item">
              <div class="qb-num">#<?= e($a['queue_no']) ?></div>
              <div>
                <div class="qb-name"><?= e($a['patient_name']) ?></div>
                <div class="qb-meta"><?= e($a['service_name']) ?></div>
              </div>
              <div class="qb-meta">
                <span class="qb-date"><?= date('M j', strtotime($a['appt_date'])) ?></span>
                <?= date('g:i A', strtotime($a['appt_time'])) ?>
                <?php if (!empty($a['notes'])): ?><div class="qb-note">"<?= e($a['notes']) ?>"</div><?php endif; ?>
              </div>
              <div class="qb-actions">
                <form method="POST">
                  <input type="hidden" name="appt_id" value="<?= e($a['id']) ?>">
                  <button class="qb-btn main" name="action" value="accept">Accept</button>
                  <button class="qb-btn warn" name="action" value="no_show" onclick="return confirm('Mark this patient as a no-show? This closes the appointment.')">No-show &amp; close</button>
                </form>
                <?= rs_form($a) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($upcomingActive): ?>
        <h3 class="qb-group-title">Upcoming accepted queues</h3>
        <div class="qb-list">
          <?php foreach ($upcomingActive as $a): ?>
            <div class="qb-item">
              <div class="qb-num">#<?= e($a['queue_no']) ?></div>
              <div>
                <div class="qb-name"><?= e($a['patient_name']) ?></div>
                <div class="qb-meta"><?= e($a['service_name']) ?> &middot; <?= date('M j, Y', strtotime($a['appt_date'])) ?> &middot; <?= date('g:i A', strtotime($a['appt_time'])) ?></div>
              </div>
              <div class="qb-meta"><?= e(ucfirst(str_replace('_', ' ', $a['status']))) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section>
      <h2>Today's queue</h2>
      <?php if (!$inConsult && !$waitingNow): ?>
        <div class="qb-empty">Nobody is waiting or in consultation right now.</div>
      <?php else: ?>
        <div class="qb-list">
          <?php foreach (array_merge($inConsult, $waitingNow) as $a):
            $isNow = $a['status'] === 'in_consultation';
          ?>
            <div class="qb-item <?= $isNow ? 'now' : '' ?>">
              <div class="qb-num">#<?= e($a['queue_no']) ?></div>
              <div>
                <div class="qb-name"><?= e($a['patient_name']) ?></div>
                <div class="qb-meta"><?= e($a['service_name']) ?> &middot; <?= date('g:i A', strtotime($a['appt_time'])) ?><?= $isNow ? ' &middot; In consultation' : '' ?></div>
              </div>
              <?php if (!empty($a['notes'])): ?><div class="qb-note">"<?= e($a['notes']) ?>"</div><?php endif; ?>
              <div class="qb-actions">
                <form method="POST">
                  <input type="hidden" name="appt_id" value="<?= e($a['id']) ?>">
                  <?php if ($isNow): ?>
                    <button class="qb-btn main" name="action" value="done">Mark done</button>
                  <?php else: ?>
                    <button class="qb-btn main" name="action" value="call">Call patient</button>
                    <button class="qb-btn warn" name="action" value="no_show" onclick="return confirm('Mark this patient as a no-show? This closes the appointment.')">No-show &amp; close</button>
                  <?php endif; ?>
                </form>
                <?php if (!$isNow) { echo rs_form($a); } ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>
</div>
<?php if ($calledPatientModal): ?>
<div class="queue-modal-backdrop" id="patient-modal" role="dialog" aria-modal="true" aria-labelledby="patient-modal-title">
  <div class="queue-modal">
    <h2 id="patient-modal-title">Patient details</h2>
    <dl class="queue-patient-details">
      <dt>Name</dt><dd><?= e($calledPatientModal['name']) ?></dd>
      <dt>Email</dt><dd><?= e($calledPatientModal['email'] ?: 'Not provided') ?></dd>
      <dt>Mobile number</dt><dd><?= e($calledPatientModal['mobile'] ?: 'Not provided') ?></dd>
      <dt>Network</dt><dd><?= e($calledPatientModal['mobile_network'] ?: 'Not provided') ?></dd>
    </dl>
    <button type="button" class="btn primary" onclick="document.getElementById('patient-modal').remove()">Close</button>
  </div>
</div>
<?php endif; ?>
<?php if ($center): ?><script>window.setTimeout(function () { window.location.reload(); }, 60000);</script><?php endif; ?>
<?php page_footer(); ?>
