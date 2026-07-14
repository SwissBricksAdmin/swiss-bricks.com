<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Users';

$msg = '';

// Update role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $uid  = (int)$_POST['user_id'];
    $role = $conn->real_escape_string($_POST['role']);
    $allowed = ['member','admin','super_admin'];
    if (in_array($role, $allowed) && $uid !== (int)$_SESSION['user_id']) {
        $conn->query("UPDATE users SET role='$role' WHERE id=$uid");
        $msg = 'Role updated.';
    }
}

$search   = $conn->real_escape_string($_GET['search'] ?? '');
$perPage  = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;
$where    = $search ? "WHERE email LIKE '%$search%' OR CONCAT(first_name,' ',last_name) LIKE '%$search%'" : '';
$total    = $conn->query("SELECT COUNT(*) FROM users $where")->fetch_row()[0];
$pages    = max(1, ceil($total / $perPage));
$users    = $conn->query("SELECT * FROM users $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");

require_once __DIR__ . '/layout.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>

<div class="admin-card">
  <div class="admin-card-header">
    <div class="admin-card-title">All Users (<?= $total ?>)</div>
    <form class="admin-search" method="GET">
      <input type="text" name="search" placeholder="Search name or email…" value="<?= htmlspecialchars($search) ?>">
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>
  </div>
  <table class="admin-table">
    <thead><tr>
      <th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Joined</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php while ($u = $users->fetch_assoc()): ?>
      <tr>
        <td style="font-weight:600;"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
        <td style="color:var(--text3);"><?= htmlspecialchars($u['email']) ?></td>
        <td style="color:var(--text3);"><?= htmlspecialchars($u['phone'] ?: '—') ?></td>
        <td>
          <span style="<?= $u['role']==='super_admin' ? 'color:var(--accent);font-weight:700;' : ($u['role']==='admin' ? 'color:#3b82f6;font-weight:700;' : 'color:var(--text3);') ?>">
            <?= ucfirst(str_replace('_', ' ', $u['role'])) ?>
          </span>
        </td>
        <td style="color:var(--text3);"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
        <td>
          <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
            <form method="POST" style="display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="role" class="status-select">
                <?php foreach (['member','admin','super_admin'] as $r): ?>
                  <option value="<?= $r ?>" <?= $u['role']===$r ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$r)) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="update_role" class="btn btn-outline btn-xs">Save</button>
            </form>
          <?php else: ?>
            <span style="font-size:0.8rem;color:var(--text3);">Current user</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div style="padding:16px 20px;">
      <div class="pagination">
        <?php for ($i=1; $i<=$pages; $i++): ?>
          <?php if ($i===$page): ?><span class="current"><?= $i ?></span>
          <?php else: ?><a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
