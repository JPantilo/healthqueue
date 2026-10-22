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
        $n = $pdo->prepare('UPDATE appointments SET status="waiting" WHERE id=? AND center_id=? AND status="pending"' . $svcPlain);
        $n->execute([$apptId, $centerId]);
        flash($n->rowCount() ? 'Booking accepted. The patient is now in the queue.' : 'That booking can no longer be accepted.', $n->rowCount() ? 'ok' : 'err');
        redirect($back);
    }

    if ($action === 'call' || $action === 'done' || $action === 'no_show') {
        $map = [
            'call'    => ['waiting', 'in_consultation'],
            'done'    => ['in_consultation', 'done'],
            'no_show' => ['waiting', 'no_show'],
        ];
        [$from, $to] = $map[$action];
        $n = $pdo->prepare('UPDATE appointments SET status=? WHERE id=? AND center_id=? AND status=?' . $svcPlain);
        $n->execute([$to, $apptId, $centerId, $from]);

        if ($n->rowCount() && $action === 'call') {
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
        flash($n->rowCount() ? 'Queue updated.' : 'That patient is no longer in that step.', $n->rowCount() ? 'ok' : 'err');
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
$pending = $waitingNow = $inConsult = $doneToday = [];
if ($center) {
    $p = $pdo->prepare('SELECT a.*, u.name AS patient_name, s.name AS service_name
                        FROM appointments a
                        JOIN users u ON a.patient_id=u.id
                        JOIN services s ON a.service_id=s.id
                        WHERE a.center_id=? AND a.status="pending" AND a.appt_date>=?' . $svcA . '
                        ORDER BY a.appt_date, a.queue_no');
    $p->execute([$centerId, $today]);
    $pending = $p->fetchAll();

    $t = $pdo->prepare('SELECT a.*, u.name AS patient_name, s.name AS service_name
                        FROM appointments a
                        JOIN users u ON a.patient_id=u.id
                        JOIN services s ON a.service_id=s.id
                        WHERE a.center_id=? AND a.appt_date=? AND a.status IN ("waiting","in_consultation","done")' . $svcA . '
                        ORDER BY a.queue_no');
    $t->execute([$centerId, $today]);
    foreach ($t->fetchAll() as $r) {
        if ($r['status'] === 'waiting') { $waitingNow[] = $r; }
        elseif ($r['status'] === 'in_consultation') { $inConsult[] = $r; }
        else { $doneToday[] = $r; }
    }
}

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
      <p><?= date('l, F j, Y') ?><?php if (!$isAdmin): ?> &middot; <?= $svcNames ? e(implode(', ', $svcNames)) : 'No department assigned' ?><?php endif; ?></p>
    </div>
    <?php if ($isAdmin && count($centers) > 1): ?>
      <form method="GET" class="qb-pick">
        <select name="center_id" onchange="this.form.submit()" aria-label="Clinic">
          <?php foreach ($centers as $c): ?>
            <option value="<?= e($c['id']) ?>" <?= (int)$c['id'] === $centerId ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$isAdmin && !$svcIds): ?>
    <div class="qb-empty" style="margin-bottom:1.4rem">You have no departments yet, so no bookings are shown. Ask an admin to assign your departments.</div>
  <?php endif; ?>

  <div class="qb-stats">
    <div class="qb-stat"><strong><?= count($pending) ?></strong><span>Awaiting approval</span></div>
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
        <div class="qb-list">
          <?php foreach ($pending as $a): ?>
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
                </form>
                <?= rs_form($a) ?>
              </div>
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
          <?php foreach (array_merge($inConsult, $waitingNow) as $a): $isNow = $a['status'] === 'in_consultation'; ?>
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
                    <button class="qb-btn warn" name="action" value="no_show">No-show</button>
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
<?php page_footer(); ?>
