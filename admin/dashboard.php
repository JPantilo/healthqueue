<?php
// Admin summary: totals, status breakdown, per-center load, 7-day trend.
require_once __DIR__ . '/../includes/layout.php';

require_role('admin');
$pdo = db();

$totals = [
    'users'        => $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'patients'     => $pdo->query('SELECT COUNT(*) FROM users WHERE role="patient"')->fetchColumn(),
    'workers'      => $pdo->query('SELECT COUNT(*) FROM users WHERE role="worker"')->fetchColumn(),
    'appointments' => $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn(),
    'today'        => $pdo->query('SELECT COUNT(*) FROM appointments WHERE appt_date = CURDATE()')->fetchColumn(),
    'pending'      => $pdo->query('SELECT COUNT(*) FROM appointments WHERE status="pending"')->fetchColumn(),
    'done'         => $pdo->query('SELECT COUNT(*) FROM appointments WHERE status="done"')->fetchColumn(),
];

$statuses = $pdo->query('SELECT status, COUNT(*) as cnt FROM appointments GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$centers  = $pdo->query(
    'SELECT c.*,
            COUNT(CASE WHEN a.status IN ("pending","waiting","in_consultation") THEN 1 END) AS appt_count,
            COUNT(CASE WHEN a.appt_date = CURDATE() THEN 1 END) AS today_count,
            COUNT(CASE WHEN a.appt_date > CURDATE() AND a.status IN ("pending","waiting","in_consultation") THEN 1 END) AS upcoming_count
     FROM centers c
     LEFT JOIN appointments a ON c.id=a.center_id
     GROUP BY c.id ORDER BY c.id ASC'
)->fetchAll();

// 7-day trend
$trend = $pdo->query(
    'SELECT appt_date, COUNT(*) AS cnt
     FROM appointments
     WHERE appt_date >= CURDATE() - INTERVAL 6 DAY AND appt_date <= CURDATE()
     GROUP BY appt_date ORDER BY appt_date'
)->fetchAll(PDO::FETCH_KEY_PAIR);
$trendLabels = [];
$trendData   = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $trendLabels[] = date('M j', strtotime($d));
    $trendData[]   = (int)($trend[$d] ?? 0);
}

// Top services
$topServices = $pdo->query(
    'SELECT s.name, COUNT(a.id) AS cnt
     FROM services s
     LEFT JOIN appointments a ON a.service_id = s.id
     GROUP BY s.id ORDER BY cnt DESC LIMIT 5'
)->fetchAll();

page_header('Admin Dashboard');
?>
<style>
.dash-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 1rem; margin-bottom: 2rem; }
.dash-card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:1.2rem 1.4rem; }
.dash-card .dnum { font-family:'Fraunces',serif; font-size:2.2rem; line-height:1.1; color:var(--brand); font-weight:600; }
.dash-card .dlabel { font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-top:.2rem; }
.dash-card.accent { background:linear-gradient(135deg,#14877d,#0b5f58); color:#fff; }
.dash-card.accent .dnum { color:#fff; }
.dash-card.accent .dlabel { color:rgba(255,255,255,.75); }
.dash-section { margin-bottom: 2rem; }
.dash-section h2 { font-family:'Fraunces',serif; font-size:1.35rem; font-weight:600; margin-bottom:1rem; }
.dash-2col { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:2rem; }
.dash-3col { display:grid; grid-template-columns:1.6fr 1fr 1fr; gap:1.5rem; margin-bottom:2rem; }
.progress-bar { height:8px; background:var(--line); border-radius:4px; overflow:hidden; margin-top:.4rem; }
.progress-fill { height:100%; background:var(--brand); border-radius:4px; transition:width .3s; }
@media(max-width:768px){.dash-grid{grid-template-columns:repeat(2,1fr);}.dash-2col,.dash-3col{grid-template-columns:1fr;}}
</style>

<div style="margin:1.2rem 0 1.8rem;">
  <h1 style="font-family:'Fraunces',serif;font-size:2rem;font-weight:600;margin-bottom:.2rem;">Clinic Analytics</h1>
  <p style="color:var(--muted);">Overview for <?= date('l, F j, Y') ?></p>
</div>

<div class="dash-grid">
  <div class="dash-card accent">
    <div class="dnum"><?= e($totals['today']) ?></div>
    <div class="dlabel">Total Queue Today</div>
  </div>
  <div class="dash-card">
    <div class="dnum"><?= e($totals['appointments']) ?></div>
    <div class="dlabel">Total Appointments</div>
  </div>
  <div class="dash-card">
    <div class="dnum"><?= e($totals['patients']) ?></div>
    <div class="dlabel">Registered Patients</div>
  </div>
  <div class="dash-card">
    <div class="dnum"><?= e($totals['workers']) ?></div>
    <div class="dlabel">Health Workers</div>
  </div>
</div>

<div class="dash-3col">
  <div class="dash-card">
    <h2>7-Day Booking Trend</h2>
    <canvas id="trendChart" height="160"></canvas>
  </div>
  <div class="dash-card">
    <h2>Status Breakdown</h2>
    <table class="table" style="font-size:.88rem;">
      <tbody>
        <?php
        $statusLabels = ['pending'=>'Pending','waiting'=>'Waiting','in_consultation'=>'In Consultation','done'=>'Done','no_show'=>'No-show','cancelled'=>'Cancelled'];
        $maxStatus = max(array_values($statuses) ?: [1]);
        foreach ($statusLabels as $k => $label):
            $cnt = (int)($statuses[$k] ?? 0);
            $pct = $maxStatus > 0 ? round($cnt / $maxStatus * 100) : 0;
        ?>
        <tr>
          <td><span class="tag tag-<?= e($k) ?>"><?= e($label) ?></span></td>
          <td style="text-align:right"><b><?= $cnt ?></b></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="dash-card">
    <h2>Top Services</h2>
    <?php $maxSvc = max(array_column($topServices, 'cnt') ?: [1]); ?>
    <?php foreach ($topServices as $s): ?>
    <div style="margin-bottom:.85rem;">
      <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.25rem;">
        <span><?= e($s['name']) ?></span>
        <b><?= e($s['cnt']) ?></b>
      </div>
      <div class="progress-bar"><div class="progress-fill" style="width:<?= $maxSvc > 0 ? round($s['cnt']/$maxSvc*100) : 0 ?>%"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="dash-card dash-section">
  <h2>Load by Health Center</h2>
  <table class="table">
    <thead>
      <tr><th>Center</th><th>Address</th><th>Active bookings</th><th>Total today</th><th>Upcoming</th></tr>
    </thead>
    <tbody>
      <?php foreach ($centers as $c): ?>
        <tr>
          <td><b><?= e($c['name']) ?></b></td>
          <td style="color:var(--muted);font-size:.88rem"><?= e($c['address']) ?></td>
          <td><?= e($c['appt_count']) ?></td>
          <td><span class="badge"><?= e($c['today_count']) ?></span></td>
          <td><span class="badge"><?= e($c['upcoming_count']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($trendLabels) ?>,
    datasets: [{
      label: 'Bookings',
      data: <?= json_encode($trendData) ?>,
      backgroundColor: 'rgba(15,118,110,.18)',
      borderColor: '#0f766e',
      borderWidth: 2,
      borderRadius: 6,
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#e3eae7' } },
      x: { grid: { display: false } }
    }
  }
});
</script>
<?php page_footer(); ?>
