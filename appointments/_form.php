<?php
// Shared booking form. Expects: $appt (array|null), $centers, $services.
$isEdit = !empty($appt['id']);
$action = $isEdit ? 'edit.php?id=' . e($appt['id']) : 'create.php';
$selectedCenter = $appt['center_id'] ?? ($centers[0]['id'] ?? 1);
$selectedService = $appt['service_id'] ?? ($services[0]['id'] ?? 1);
$selectedDate = $appt['appt_date'] ?? date('Y-m-d', strtotime('+1 day'));
$selectedTime = substr($appt['appt_time'] ?? '09:00:00', 0, 5);
$notes = $appt['notes'] ?? '';
?>
<form method="POST" action="<?= e($action) ?>" class="form card">
  <div class="field">
    <label for="center_id">Select Health Center:</label>
    <select name="center_id" id="center_id" required>
      <?php foreach ($centers as $c): ?>
        <option value="<?= e($c['id']) ?>" <?= $c['id'] == $selectedCenter ? 'selected' : '' ?>>
          <?= e($c['name']) ?> (<?= e($c['address']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div id="map" class="map-box" style="height: 240px; border-radius: 6px; margin-bottom: 1rem;"></div>

  <div class="field">
    <label for="service_id">Medical Service:</label>
    <select name="service_id" id="service_id" required>
      <?php foreach ($services as $s): ?>
        <option value="<?= e($s['id']) ?>" <?= $s['id'] == $selectedService ? 'selected' : '' ?>>
          <?= e($s['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="grid cols-2">
    <div class="field">
      <label for="appt_date">Date:</label>
      <input type="date" name="appt_date" id="appt_date" value="<?= e($selectedDate) ?>" min="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="field">
      <label for="appt_time">Time:</label>
      <input type="time" name="appt_time" id="appt_time" value="<?= e($selectedTime) ?>" required>
    </div>
  </div>

  <!-- Live Weather Forecast Preview -->
  <div id="wx" class="wx" hidden></div>

  <div class="field">
    <label for="notes">Notes / Reason for visit (optional):</label>
    <textarea name="notes" id="notes" rows="2" maxlength="255"><?= e($notes) ?></textarea>
  </div>

  <div class="form-actions mt-3">
    <button type="submit" class="btn primary"><?= $isEdit ? 'Update Appointment' : 'Confirm & Book Appointment' ?></button>
    <a href="index.php" class="btn secondary">Cancel</a>
  </div>
</form>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function() {
  var CENTERS = <?= json_encode($centers) ?>;
  var sel = document.getElementById('center_id');
  if (!CENTERS.length) return;

  var first = CENTERS[0];
  var map = L.map('map').setView([first.lat, first.lng], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  var markers = {};
  CENTERS.forEach(function (c) {
    var m = L.marker([c.lat, c.lng]).addTo(map).bindPopup('<b>' + c.name + '</b><br>' + c.address);
    markers[c.id] = m;
  });

  function focus() {
    var c = CENTERS.filter(function (x) { return x.id == sel.value; })[0];
    if (c) { map.setView([c.lat, c.lng], 15); markers[c.id].openPopup(); }
  }
  sel.addEventListener('change', focus);
  focus();

  // OpenWeatherMap forecast for the chosen center/date/time (same-domain request).
  var wx = document.getElementById('wx'), f = document.forms[0];
  function loadWx() {
    var qs = 'center_id=' + encodeURIComponent(sel.value) + '&date=' + encodeURIComponent(f.appt_date.value) + '&time=' + encodeURIComponent(f.appt_time.value);
    fetch('<?= e(cfg('base_url')) ?>/api/weather.php?' + qs)
      .then(function (r) { return r.json(); })
      .then(function (w) {
        wx.hidden = false;
        wx.className = 'wx';
        wx.textContent = '';
        if (!w.available) {
          wx.textContent = w.message || '';
          return;
        }
        var img = document.createElement('img');
        img.alt = '';
        img.src = 'https://openweathermap.org/img/wn/' + w.icon + '@2x.png';
        var t = document.createElement('div');
        var b = document.createElement('b');
        b.textContent = w.temp + '\u00B0C';
        t.appendChild(b);
        t.appendChild(document.createTextNode(' ' + w.desc + ' \u00B7 ' + w.pop + '% rain chance'));
        if (w.advisory) {
          wx.className = 'wx warn';
          t.appendChild(document.createElement('br'));
          t.appendChild(document.createTextNode(w.advisory));
        }
        wx.appendChild(img);
        wx.appendChild(t);
      })
      .catch(function () { wx.hidden = true; });
  }
  [sel, f.appt_date, f.appt_time].forEach(function (el) { el.addEventListener('change', loadWx); });
  loadWx();
})();
</script>