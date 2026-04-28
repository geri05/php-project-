<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'database/db.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: index.php?error=true');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $flash = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'update_user_role') {
                $userId = (int)($_POST['user_id'] ?? 0);
                $role = $_POST['role'] ?? 'user';
                if (in_array($role, ['user', 'admin'], true) && $userId > 0) {
                    $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
                    $stmt->execute([$role, $userId]);
                    $flash = 'User role updated.';
                }
            }

            if ($action === 'update_spot_status') {
                $spotId = (int)($_POST['spot_id'] ?? 0);
                $status = $_POST['status'] ?? 'available';
                if (in_array($status, ['available', 'reserved', 'occupied'], true) && $spotId > 0) {
                    $stmt = $pdo->prepare('UPDATE parking_spots SET status = ? WHERE id = ?');
                    $stmt->execute([$status, $spotId]);
                    $flash = 'Spot status updated.';
                }
            }

            if ($action === 'delete_reservation') {
                $reservationId = (int)($_POST['reservation_id'] ?? 0);
                if ($reservationId > 0) {
                    $stmt = $pdo->prepare('DELETE FROM reservations WHERE id = ?');
                    $stmt->execute([$reservationId]);
                    $flash = 'Reservation deleted.';
                }
            }
        } catch (Throwable $e) {
            $flash = 'Action failed: ' . $e->getMessage();
        }
    }
}

// Stats
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalSpots = (int)$pdo->query('SELECT COUNT(*) FROM parking_spots')->fetchColumn();
$availableSpots = (int)$pdo->query("SELECT COUNT(*) FROM parking_spots WHERE status = 'available'")->fetchColumn();
$activeReservations = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE reservation_end > NOW()")->fetchColumn();

// Lists
$usersStmt = $pdo->query('
    SELECT id, first_name, last_name, email, role, created_at
    FROM users
    ORDER BY id DESC
    LIMIT 30
');
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$spotsStmt = $pdo->query('
    SELECT id, spot_number, status
    FROM parking_spots
    ORDER BY spot_number
');
$spots = $spotsStmt->fetchAll(PDO::FETCH_ASSOC);

$resStmt = $pdo->query('
    SELECT id, user_id, spot_id, reservation_start, reservation_end
    FROM reservations
    ORDER BY id DESC
    LIMIT 30
');
$reservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel — Parkster</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root { --bg:#0f1220; --card:#171b2e; --text:#e9edf7; --muted:#a5b0c7; --ok:#2ec27e; --warn:#f6c453; --danger:#e85d75; }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--bg); color:var(--text); font-family:Inter,Segoe UI,Arial,sans-serif; }
    .wrap { max-width:1200px; margin:0 auto; padding:20px; }
    .top { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    .top a { color:#fff; text-decoration:none; margin-left:10px; }
    .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
    .card { background:var(--card); border-radius:12px; padding:14px; }
    .kpi { font-size:26px; font-weight:700; margin-top:6px; }
    .muted { color:var(--muted); font-size:12px; }
    .flash { background:#1f2a44; border:1px solid #34456e; border-radius:8px; padding:10px; margin-bottom:12px; }
    .sections { display:grid; grid-template-columns:1fr; gap:14px; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th, td { text-align:left; padding:10px; border-bottom:1px solid #2a3150; vertical-align:middle; }
    th { color:#c7d1ea; font-weight:600; }
    select, button { background:#202845; color:#fff; border:1px solid #3b4b7a; border-radius:7px; padding:6px 8px; font-size:12px; }
    button { cursor:pointer; }
    .btn-danger { border-color:#7a3040; background:#3a1d25; }
    .badge { padding:3px 8px; border-radius:999px; font-size:11px; display:inline-block; }
    .b-ok { background:rgba(46,194,126,.15); color:var(--ok); }
    .b-warn { background:rgba(246,196,83,.15); color:var(--warn); }
    .b-danger { background:rgba(232,93,117,.15); color:var(--danger); }
    @media (max-width:980px){ .grid{grid-template-columns:repeat(2,1fr);} }
    @media (max-width:640px){ .grid{grid-template-columns:1fr;} .wrap{padding:12px;} }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <h1>Parkster Admin</h1>
      <div>
        <a href="index.php">Main App</a>
        <a href="functions/logout.php">Logout</a>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="grid">
      <div class="card"><div class="muted">Total Users</div><div class="kpi"><?= $totalUsers ?></div></div>
      <div class="card"><div class="muted">Total Spots</div><div class="kpi"><?= $totalSpots ?></div></div>
      <div class="card"><div class="muted">Available Spots</div><div class="kpi"><?= $availableSpots ?></div></div>
      <div class="card"><div class="muted">Active Reservations</div><div class="kpi"><?= $activeReservations ?></div></div>
    </div>

    <div class="sections">
      <div class="card">
        <h3>Users</h3>
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?></td>
                <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($u['role'] ?? 'user') ?></td>
                <td><?= htmlspecialchars($u['created_at'] ?? '-') ?></td>
                <td>
                  <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="update_user_role">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <select name="role">
                      <option value="user" <?= (($u['role'] ?? '') === 'user') ? 'selected' : '' ?>>user</option>
                      <option value="admin" <?= (($u['role'] ?? '') === 'admin') ? 'selected' : '' ?>>admin</option>
                    </select>
                    <button type="submit">Save</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>Parking Spots</h3>
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Spot</th><th>Status</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($spots as $s): ?>
              <tr>
                <td><?= (int)$s['id'] ?></td>
                <td><?= htmlspecialchars($s['spot_number'] ?? '-') ?></td>
                <td>
                  <?php $st = $s['status'] ?? 'available'; ?>
                  <span class="badge <?= $st === 'available' ? 'b-ok' : ($st === 'reserved' ? 'b-warn' : 'b-danger') ?>">
                    <?= htmlspecialchars($st) ?>
                  </span>
                </td>
                <td>
                  <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="update_spot_status">
                    <input type="hidden" name="spot_id" value="<?= (int)$s['id'] ?>">
                    <select name="status">
                      <option value="available" <?= $st === 'available' ? 'selected' : '' ?>>available</option>
                      <option value="reserved" <?= $st === 'reserved' ? 'selected' : '' ?>>reserved</option>
                      <option value="occupied" <?= $st === 'occupied' ? 'selected' : '' ?>>occupied</option>
                    </select>
                    <button type="submit">Save</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>Recent Reservations</h3>
        <table>
          <thead>
            <tr>
              <th>ID</th><th>User ID</th><th>Spot ID</th><th>Start</th><th>End</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reservations as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= (int)($r['user_id'] ?? 0) ?></td>
                <td><?= (int)($r['spot_id'] ?? 0) ?></td>
                <td><?= htmlspecialchars($r['start_time'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['end_time'] ?? '-') ?></td>
                <td>
                  <form method="POST" onsubmit="return confirm('Delete reservation #<?= (int)$r['id'] ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="delete_reservation">
                    <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>