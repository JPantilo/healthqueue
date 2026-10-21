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

  <div class="field">
    <label for="notes">Notes / Reason for visit (optional):</label>
    <textarea name="notes" id="notes" rows="2" maxlength="255"><?= e($notes) ?></textarea>
  </div>

  <div class="form-actions mt-3">
    <button type="submit" class="btn primary"><?= $isEdit ? 'Update Appointment' : 'Confirm & Book Appointment' ?></button>
    <a href="index.php" class="btn secondary">Cancel</a>
  </div>
</form>
