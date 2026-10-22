<?php
// READ: patients see their own; workers/admins see all (filterable).
require_once __DIR__ . '/../includes/layout.php';

$u = require_login();
$pdo = db();

$isStaff = in_array($u['role'], ['worker', 'admin'], true);
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
$sql .= ' ORDER BY a.appt_date DESC, a.appt_time DESC';

$st = $pdo->prepare($sql);
$st->execute($params);
$appts = $st->fetchAll();

$centers = $isStaff ? $pdo->query('SELECT * FROM centers ORDER BY name')->fetchAll() : [];

page_header('My Appointments');
?>
<div class="bar mb-3">
  <h2><?= $isStaff ? 'Clinic Appointments' : 'My Appointments' ?></h2>
  <a href="create.php" class="btn primary">+ Book New Appointment</a>
</div>

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
          <td><span class="tag tag-<?= e($a['status']) ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span></td>
          <td>
            <?php if ($a['status'] !== 'cancelled' && $a['status'] !== 'done'): ?>
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
<?php page_footer(); ?>