<?php
// READ: patients see their own; workers/admins see all (filterable).
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/google.php';

$u = require_patient();
$pdo = db();

$calendarConnected = !empty($u['google_refresh_token']);
if (isset($_GET['calendar']) && $_GET['calendar'] === 'connected') {
    flash('Google Calendar is connected. New and updated appointments can now sync automatically.', 'ok');
    redirect('index.php');
}

$isStaff = false;
$centerFilter = (int)($_GET['center_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

$sql = 'SELECT a.*, c.name AS center_name, s.name AS service_name, u.name AS patient_name, u.email AS patient_email 
        FROM appointments a 
        JOIN centers c ON a.center_id=c.id 
        JOIN services s ON a.service_id=s.id 
        JOIN users u ON a.patient_id=u.id ';

$where = [];
$params = [];

if (!$isStaff) {
    $where[] = 'a.patient_id = ?';
    $params[] = $u['id'];
} else {
    if ($centerFilter) {
        $where[] = 'a.center_id = ?';
        $params[] = $centerFilter;
    }
    if ($statusFilter) {
        $where[] = 'a.status = ?';
        $params[] = $statusFilter;
    }
}

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= $isStaff
    ? ' ORDER BY a.appt_date DESC, a.appt_time DESC, a.queue_no ASC'
    : ' ORDER BY a.appt_date ASC, a.appt_time ASC, a.queue_no ASC';

$st = $pdo->prepare($sql);
$st->execute($params);
$appts = $st->fetchAll();

$centers = $isStaff ? $pdo->query('SELECT * FROM centers ORDER BY name')->fetchAll() : [];
$activeCount = 0;
$nextAppointment = null;
if (!$isStaff) {
    foreach ($appts as &$appointment) {
        if (in_array($appointment['status'], ['pending', 'waiting', 'in_consultation'], true)) {
            $activeCount++;
        }
        if (in_array($appointment['status'], ['pending', 'waiting', 'in_consultation'], true)) {
            $ahead = $pdo->prepare('SELECT COUNT(*) FROM appointments
                WHERE center_id = ? AND service_id = ? AND appt_date = ? AND queue_no < ?
                AND status IN ("pending", "waiting", "in_consultation")');
            $ahead->execute([$appointment['center_id'], $appointment['service_id'], $appointment['appt_date'], $appointment['queue_no']]);
            $appointment['ahead_count'] = (int)$ahead->fetchColumn();
            if ($nextAppointment === null ||
                $appointment['appt_date'] < $nextAppointment['appt_date'] ||
                ($appointment['appt_date'] === $nextAppointment['appt_date'] && $appointment['queue_no'] < $nextAppointment['queue_no'])) {
                $nextAppointment = $appointment;
            }
        }
    }
    unset($appointment);
}

page_header('My Appointments');
?>
<div class="bar mb-3">
  <div>
    <h2><?= $isStaff ? 'Clinic Appointments' : 'My Appointments' ?></h2>
    <p class="text-muted"><?= $isStaff ? 'Review bookings across your clinics.' : 'Track your bookings and queue progress in one place.' ?></p>
  </div>
  <?php if (!$isStaff): ?>
    <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">
      <?php if ($calendarConnected): ?>
        <span class="badge">Google Calendar connected</span>
      <?php else: ?>
        <a href="<?= e(google_calendar_url()) ?>" class="btn secondary">Sync Google Calendar</a>
      <?php endif; ?>
      <a href="create.php" class="btn primary">Book New Appointment</a>
    </div>
  <?php else: ?>
    <a href="create.php" class="btn primary">Book New Appointment</a>
  <?php endif; ?>
</div>

<?php if (!$isStaff): ?>
  <div class="appt-overview mb-3">
    <div class="overview-card"><span class="overview-icon">◷</span><div><strong><?= $activeCount ?></strong><span>Active bookings</span></div></div>
    <div class="overview-card"><span class="overview-icon">#</span><div><strong><?= $nextAppointment ? '#' . e($nextAppointment['queue_no']) : '—' ?></strong><span><?= $nextAppointment && $nextAppointment['appt_date'] === date('Y-m-d') ? "Today's queue number" : 'Next queue number' ?></span></div></div>
    <div class="overview-card"><span class="overview-icon">✓</span><div><strong><?= $nextAppointment ? e($nextAppointment['ahead_count']) : '—' ?></strong><span><?= $nextAppointment && $nextAppointment['appt_date'] === date('Y-m-d') ? 'People ahead today' : 'People ahead on queue date' ?></span></div></div>
    <?php if ($nextAppointment): ?><button type="button" class="btn secondary sm overview-refresh" onclick="window.location.reload()">Refresh queue</button><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($isStaff): ?>
  <form method="GET" class="filter-bar card mb-3">
    <div class="grid cols-3">
      <div>
        <label>Center:</label>
        <select name="center_id" onchange="this.form.submit()">
          <option value="">All Centers</option>
          <?php foreach ($centers as $c): ?>
            <option value="<?= e($c['id']) ?>" <?= $centerFilter == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Status:</label>
        <select name="status" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="waiting" <?= $statusFilter === 'waiting' ? 'selected' : '' ?>>Waiting</option>
          <option value="in_consultation" <?= $statusFilter === 'in_consultation' ? 'selected' : '' ?>>In Consultation</option>
          <option value="done" <?= $statusFilter === 'done' ? 'selected' : '' ?>>Done</option>
          <option value="no_show" <?= $statusFilter === 'no_show' ? 'selected' : '' ?>>No-show</option>
          <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
      </div>
      <div style="align-self:end;">
        <a href="index.php" class="btn secondary">Reset Filter</a>
      </div>
    </div>
  </form>
<?php endif; ?>

<?php if (empty($appts)): ?>
  <div class="card empty">
    <p>No appointments found.</p>
  </div>
<?php else: ?>
  <table class="table card">
    <thead>
      <tr>
        <th>Queue #</th>
        <?php if ($isStaff): ?><th>Patient</th><?php endif; ?>
        <th>Service</th>
        <th>Center</th>
        <th>Date &amp; Time</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($appts as $a): ?>
        <tr>
          <td><span class="queue-badge">#<?= e($a['queue_no']) ?></span></td>
          <?php if ($isStaff): ?>
            <td>
              <b><?= e($a['patient_name']) ?></b><br>
              <small class="text-muted"><?= e($a['patient_email']) ?></small>
            </td>
          <?php endif; ?>
          <td><?= e($a['service_name']) ?></td>
          <td><?= e($a['center_name']) ?></td>
          <td>
            <?= date('M j, Y', strtotime($a['appt_date'])) ?><br>
            <small class="text-muted"><?= date('g:i A', strtotime($a['appt_time'])) ?></small>
          </td>
          <td>
            <span class="tag tag-<?= e($a['status']) ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span>
            <?php if (!$isStaff && isset($a['ahead_count'])): ?>
              <small class="queue-hint"><?= e($a['ahead_count']) ?> ahead</small>
            <?php endif; ?>
          </td>
          <td>
            <?php if (in_array($a['status'], ['pending', 'waiting'], true)): ?>
              <a href="edit.php?id=<?= e($a['id']) ?>" class="btn sm secondary">Reschedule</a>
              <a href="delete.php?id=<?= e($a['id']) ?>" class="btn sm danger" onclick="return confirm('Cancel this appointment?')">Cancel</a>
            <?php else: ?>
              <span class="text-muted">&mdash;</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php if (!$isStaff && $nextAppointment): ?><script>window.setTimeout(function () { window.location.reload(); }, 30000);</script><?php endif; ?>
<?php page_footer(); ?>