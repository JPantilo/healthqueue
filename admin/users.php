<?php
// Admin: change roles and delete users.
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? 'role';
    $newRole = $_POST['role'] ?? '';
    if ($action === 'delete' && $userId) {
        if ($userId === (int)$me['id']) {
            flash('You cannot delete your own admin account.', 'err');
        } else {
            $delete = $pdo->prepare('DELETE FROM users WHERE id=?');
            $delete->execute([$userId]);
            flash($delete->rowCount() ? 'User deleted.' : 'User could not be found.', $delete->rowCount() ? 'ok' : 'err');
        }
        redirect('users.php');
    }

    if ($action === 'role' && $userId && in_array($newRole, ['patient', 'worker', 'admin'], true)) {
        if ($userId === (int)$me['id'] && $newRole !== 'admin') {
            flash('You cannot demote your own account from admin.', 'err');
        } else {
            $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$newRole, $userId]);
            flash('User role updated.', 'ok');
        }
        redirect('users.php');
    }
}

$users = $pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();

page_header('User Role Management');
?>
<h2>System Users &amp; Permissions</h2>

<table class="table card mt-3">
  <thead>
    <tr>
      <th>User</th>
      <th>Email</th>
      <th>Role</th>
      <th>Registered</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <b><?= e($u['name']) ?></b>
          <?php if ($u['id'] == $me['id']): ?><span class="badge">(You)</span><?php endif; ?>
        </td>
        <td><?= e($u['email']) ?></td>
        <td><span class="tag tag-<?= e($u['role']) ?>"><?= e($u['role']) ?></span></td>
        <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
        <td>
          <div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">
          <form method="POST" style="display:inline-flex;gap:0.4rem;">
          <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
          <input type="hidden" name="action" value="role">
          <select name="role">
              <option value="patient" <?= $u['role'] === 'patient' ? 'selected' : '' ?>>Patient</option>
              <option value="worker" <?= $u['role'] === 'worker' ? 'selected' : '' ?>>Worker</option>
              <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
            <button type="submit" class="btn sm secondary">Save</button>
            </form>
            <?php if ($u['id'] != $me['id']): ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn sm danger" onclick="return confirm('Delete this user and all of their appointments? This cannot be undone.')">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php page_footer(); ?>