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

$loggedIn = (bool)current_user();
$ctaHref  = $loggedIn ? 'appointments/create.php' : google_login_url();
$ctaText  = 'Book an appointment';
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

    <div class="hq-ticket" aria-label="Sample queue ticket">
      <div class="label">Sample queue ticket</div>
      <div class="center"><?= e($sampleCenter) ?></div>
      <div class="num">#07</div>
      <div class="row">
        <span><?= date('D, M j') ?></span>
        <span class="hq-pill">Waiting</span>
      </div>
    </div>
  </section>

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
