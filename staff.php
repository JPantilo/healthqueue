<?php
// Admin: set each worker's clinic and departments (services).
require_once __DIR__ . '/../includes/layout.php';

require_role(['admin']);
$pdo = db();

$centers  = $pdo->query('SELECT id, name FROM centers ORDER BY name')->fetchAll();
$services = $pdo->query('SELECT id, name FROM services ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId   = (int)($_POST['user_id'] ?? 0);
    $centerId = (int)($_POST['center_id'] ?? 0);
    $picked   = array_map('intval', (array)($_POST['services'] ?? []));

    $chk = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role = "worker"');
    $chk->execute([$userId]);
    if (!$chk->fetch()) {
        flash('That user is not a worker.', 'err');
        redirect('staff.php');
    }

    $validCenters  = array_map('intval', array_column($centers, 'id'));
    $validServices = array_map('intval', array_column($services, 'id'));
    $centerId = in_array($centerId, $validCenters, true) ? $centerId : null;
    $picked   = array_values(array_intersect($picked, $validServices));

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET center_id = ? WHERE id = ?')->execute([$centerId, $userId]);
    $pdo->prepare('DELETE FROM worker_services WHERE user_id = ?')->execute([$userId]);
    $ins = $pdo->prepare('INSERT INTO worker_services (user_id, service_id) VALUES (?, ?)');
    foreach ($picked as $sid) { $ins->execute([$userId, $sid]); }
    $pdo->commit();

    flash('Worker updated.', 'ok');
    redirect('staff.php');
}

$workers = $pdo->query('SELECT id, name, email, center_id FROM users WHERE role = "worker" ORDER BY name')->fetchAll();
$assigned = [];
foreach ($pdo->query('SELECT user_id, service_id FROM worker_services')->fetchAll() as $r) {
    $assigned[(int)$r['user_id']][] = (int)$r['service_id'];
}

page_header('Staff departments');
?>

<div class="st">
  <h1>Staff departments</h1>
  <p class="sub">Choose each worker's clinic and the departments they handle. Workers only see and accept bookings for those services at that clinic.</p>

  <?php if (!$workers): ?>
    <div class="st-empty">No workers yet. Set a user's role to Worker on the Users page first.</div>
  <?php endif; ?>

  <?php foreach ($workers as $w): $mine = $assigned[(int)$w['id']] ?? []; ?>
    <form method="POST" class="st-card">
      <input type="hidden" name="user_id" value="<?= e($w['id']) ?>">
      <div class="st-head">
        <div>
          <div class="st-name"><?= e($w['name']) ?></div>
          <div class="st-mail"><?= e($w['email']) ?></div>
        </div>
        <select name="center_id" aria-label="Clinic for <?= e($w['name']) ?>">
          <option value="0">No clinic</option>
          <?php foreach ($centers as $c): ?>
            <option value="<?= e($c['id']) ?>" <?= (int)$w['center_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="st-label">Departments</div>
      <div class="st-checks">
        <?php foreach ($services as $s): ?>
          <label>
            <input type="checkbox" name="services[]" value="<?= e($s['id']) ?>" <?= in_array((int)$s['id'], $mine, true) ? 'checked' : '' ?>>
            <span><?= e($s['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <button class="st-save" type="submit">Save</button>
    </form>
  <?php endforeach; ?>
</div>
<?php page_footer(); ?>
