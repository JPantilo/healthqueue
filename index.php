<?php
// Home page. Lists centers and services from the database; still loads if the DB is down.
require_once __DIR__ . '/includes/layout.php';

$centers = [];
$services = [];
$dbDown = false;
try {
    $centers  = db()->query('SELECT * FROM centers ORDER BY name')->fetchAll();
    $services = db()->query('SELECT * FROM services ORDER BY name')->fetchAll();
} catch (Exception $e) {
    $dbDown = true;
}

$sampleCenter = $centers[0]['name'] ?? 'City Health Office - Iligan';
page_header('HealthQueue Iligan');

$user = current_user();
$loggedIn = (bool)$user;
$role = $user['role'] ?? null;
$queueTicket = null;
$publicCenterIndex = 0;
$publicCenter = $centers[0] ?? null;
if (!$user && $centers) {
    $requestedCenter = (int)($_GET['center_id'] ?? $centers[0]['id']);
    foreach ($centers as $index => $centerOption) {
        if ((int)$centerOption['id'] === $requestedCenter) {
            $publicCenterIndex = $index;
            $publicCenter = $centerOption;
            break;
        }
    }
}
if (!$dbDown && $user && $user['role'] === 'patient') {
    $ticket = db()->prepare(
        'SELECT a.queue_no, a.appt_date, a.status, c.name AS center_name
         FROM appointments a
         JOIN centers c ON c.id = a.center_id
         WHERE a.patient_id = ? AND a.status IN ("pending", "waiting", "in_consultation")
         ORDER BY a.appt_date ASC, a.appt_time ASC, a.queue_no ASC
         LIMIT 1'
    );
    $ticket->execute([$user['id']]);
    $queueTicket = $ticket->fetch() ?: null;
} elseif (!$dbDown && !empty($publicCenter['id'])) {
    $ticket = db()->prepare(
        'SELECT a.queue_no, a.appt_date, a.status, c.name AS center_name
         FROM appointments a
         JOIN centers c ON c.id = a.center_id
         WHERE a.center_id = ? AND a.appt_date = CURDATE()
           AND a.status IN ("in_consultation", "waiting", "pending")
         ORDER BY FIELD(a.status, "in_consultation", "waiting", "pending"), a.queue_no ASC
         LIMIT 1'
    );
    $ticket->execute([(int)$publicCenter['id']]);
    $queueTicket = $ticket->fetch() ?: null;
}
$queueTicketIsPublic = !$user && (bool)$queueTicket;
$publicPrevious = $centers ? $centers[($publicCenterIndex - 1 + count($centers)) % count($centers)] : null;
$publicNext = $centers ? $centers[($publicCenterIndex + 1) % count($centers)] : null;
$ctaHref  = !$loggedIn ? google_login_url() : ($role === 'patient' ? 'appointments/create.php' : ($role === 'worker' ? 'queue.php' : 'admin/dashboard.php'));
$ctaText  = !$loggedIn || $role === 'patient' ? 'Book an appointment' : ($role === 'worker' ? 'Open worker queue' : 'Open admin dashboard');
?>
<div class="hq">

  <section class="hq-hero">
    <div>
      <span class="hq-eyebrow">Iligan City &middot; Lanao del Norte</span>
      <h1>Your turn at the health center, <em>planned ahead.</em></h1>
      <p class="lead">Choose a health center and a date, and get your queue number before you leave home. A confirmation email is sent, and the visit can be added to your Google Calendar.</p>
      <a class="btn primary lg" href="<?= e($ctaHref) ?>"><?= e($ctaText) ?></a>

      <?php if (!$dbDown): ?>
      <div class="hq-meta">
        <div><strong><?= count($centers) ?></strong><span>Health centers</span></div>
        <div><strong><?= count($services) ?></strong><span>Services</span></div>
        <div><strong>5-day</strong><span>Weather forecast</span></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="hq-ticket-area">
      <?php if (!$user && $publicCenter): ?>
      <div class="public-queue-nav">
        <a class="queue-arrow" href="?center_id=<?= e($publicPrevious['id']) ?>" aria-label="Previous health center">&lsaquo;</a>
        <span><?= e($publicCenterIndex + 1) ?> / <?= e(count($centers)) ?></span>
        <a class="queue-arrow" href="?center_id=<?= e($publicNext['id']) ?>" aria-label="Next health center">&rsaquo;</a>
      </div>
      <?php endif; ?>
      <div class="hq-ticket" aria-label="<?= $queueTicket ? 'Your queue ticket' : 'Public queue status' ?>">
        <div class="label"><?= $user && $queueTicket ? 'Your current queue' : ($queueTicketIsPublic ? 'Current public queue' : 'No active queue') ?></div>
        <div class="center"><?= e($queueTicket['center_name'] ?? ($publicCenter['name'] ?? $sampleCenter)) ?></div>
        <div class="num"><?= $queueTicket ? '#' . e($queueTicket['queue_no']) : '—' ?></div>
        <div class="row">
          <span><?= $queueTicket ? date('D, M j', strtotime($queueTicket['appt_date'])) : date('D, M j') ?></span>
          <span class="hq-pill"><?= $queueTicket ? e(str_replace('_', ' ', $queueTicket['status'])) : 'No patients queued' ?></span>
        </div>
      </div>
    </div>
  </section>
  <?php if (!$user && $publicCenter): ?><script>window.setTimeout(function () { window.location.reload(); }, 30000);</script><?php endif; ?>

  <section class="hq-section">
    <h2>How it works</h2>
    <p class="sub">Three steps, no need to line up at dawn.</p>
    <div class="hq-steps">
      <div class="card hq-step">
        <div class="n">01</div>
        <h3>Pick a center and service</h3>
        <p>Select the health center near you and the service you need.</p>
      </div>
      <div class="card hq-step">
        <div class="n">02</div>
        <h3>Choose your date</h3>
        <p>See the weather forecast for that day, then confirm your queue number.</p>
      </div>
      <div class="card hq-step">
        <div class="n">03</div>
        <h3>Arrive and get called</h3>
        <p>Health workers call patients in queue order, and you get an email when you are next.</p>
      </div>
    </div>
  </section>

  <?php if ($dbDown): ?>
    <div class="card hq-section" style="padding:1.4rem"><p style="margin:0">The list of centers could not be loaded right now. Please try again in a few minutes.</p></div>
  <?php else: ?>
  <section class="hq-section">
    <h2>Services</h2>
    <p class="sub">What you can book at our participating centers.</p>
    <div class="hq-chips">
      <?php foreach ($services as $s): ?>
        <span class="hq-chip"><?= e($s['name']) ?></span>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="hq-section">
    <h2>Health centers</h2>
    <p class="sub">Participating centers across Iligan City.</p>
    <div class="hq-centers">
      <?php foreach ($centers as $c): ?>
        <div class="card hq-center">
          <div class="hq-pin">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
          </div>
          <div>
            <h3><?= e($c['name']) ?></h3>
            <p><?= e($c['address']) ?></p>
            <div class="hours"><?= e($c['hours']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="hq-cta">
    <h2>Ready to skip the line?</h2>
    <p>Sign in with your Google account and book your slot in under a minute.</p>
    <a class="btn primary lg" href="<?= e($ctaHref) ?>"><?= e($ctaText) ?></a>
  </div>
  <?php endif; ?>

</div>
<?php page_footer(); ?>
