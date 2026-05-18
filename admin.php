<?php
// Admin dashboard for Parkster: handles CSRF-protected POST actions (user role updates, user deletion,
// spot status changes, reservation and session management), displays KPI stats, and renders paginated
// tables for users, parking spots, sessions, and reservations.

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
$flash_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $flash = 'Invalid CSRF token.';
        $flash_type = 'err';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'update_user_role') {
                $userId = (int)($_POST['user_id'] ?? 0);
                $role   = $_POST['role'] ?? 'customer';
                if (in_array($role, ['customer', 'admin'], true) && $userId > 0) {
                    if ($userId === (int)$_SESSION['user_id'] && $role !== 'admin') {
                        $flash = "You can't change your own admin role.";
                        $flash_type = 'err';
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
                        $stmt->execute([$role, $userId]);
                        $flash = 'User role updated.';
                        $flash_type = 'ok';
                    }
                } else {
                    $flash = 'Invalid role or user.';
                    $flash_type = 'err';
                }
            }

            if ($action === 'delete_user') {
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId === (int)$_SESSION['user_id']) {
                    $flash = "You can't delete your own account here.";
                    $flash_type = 'err';
                } elseif ($userId > 0) {
                    $pdo->beginTransaction();
                    $pdo->prepare("
                        UPDATE parking_spots SET status='available'
                        WHERE id IN (
                            SELECT spot_id FROM reservations WHERE user_id = ? AND status='active'
                            UNION
                            SELECT spot_id FROM parking_sessions ps
                            JOIN vehicles v ON v.id = ps.vehicle_id
                            WHERE v.user_id = ? AND ps.status='active'
                        )
                    ")->execute([$userId, $userId]);

                    $pdo->prepare("
                        DELETE FROM payments WHERE reservation_id IN (
                            SELECT id FROM reservations WHERE user_id = ?
                        )
                    ")->execute([$userId]);
                    $pdo->prepare("
                        DELETE FROM payments WHERE session_id IN (
                            SELECT ps.id FROM parking_sessions ps
                            JOIN vehicles v ON v.id = ps.vehicle_id
                            WHERE v.user_id = ?
                        )
                    ")->execute([$userId]);

                    $pdo->prepare("DELETE FROM reservations WHERE user_id = ?")->execute([$userId]);
                    $pdo->prepare("
                        DELETE FROM parking_sessions
                        WHERE vehicle_id IN (SELECT id FROM vehicles WHERE user_id = ?)
                    ")->execute([$userId]);
                    $pdo->prepare("DELETE FROM vehicles WHERE user_id = ?")->execute([$userId]);
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

                    $pdo->commit();
                    $flash = "User #$userId deleted.";
                    $flash_type = 'ok';
                }
            }

            if ($action === 'update_spot_status') {
                $spotId = (int)($_POST['spot_id'] ?? 0);
                $status = $_POST['status'] ?? 'available';
                if (in_array($status, ['available', 'reserved', 'occupied'], true) && $spotId > 0) {
                    $pdo->beginTransaction();
                    if ($status === 'available') {
                        $pdo->prepare("UPDATE reservations SET status='cancelled' WHERE spot_id = ? AND status='active'")
                            ->execute([$spotId]);
                        $pdo->prepare("UPDATE parking_sessions SET status='completed', exit_time=NOW() WHERE spot_id = ? AND status='active'")
                            ->execute([$spotId]);
                    }
                    $pdo->prepare('UPDATE parking_spots SET status = ? WHERE id = ?')
                        ->execute([$status, $spotId]);
                    $pdo->commit();
                    $flash = 'Spot status updated.';
                    $flash_type = 'ok';
                }
            }

            if ($action === 'delete_reservation') {
                $reservationId = (int)($_POST['reservation_id'] ?? 0);
                if ($reservationId > 0) {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT spot_id, status FROM reservations WHERE id = ?");
                    $stmt->execute([$reservationId]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($r && $r['status'] === 'active') {
                        $pdo->prepare("UPDATE parking_spots SET status='available' WHERE id = ?")
                            ->execute([$r['spot_id']]);
                    }
                    $pdo->prepare("DELETE FROM payments WHERE reservation_id = ?")->execute([$reservationId]);
                    $pdo->prepare("DELETE FROM reservations WHERE id = ?")->execute([$reservationId]);
                    $pdo->commit();
                    $flash = 'Reservation deleted.';
                    $flash_type = 'ok';
                }
            }

            if ($action === 'end_reservation') {
                $reservationId = (int)($_POST['reservation_id'] ?? 0);
                if ($reservationId > 0) {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT spot_id FROM reservations WHERE id = ? AND status='active'");
                    $stmt->execute([$reservationId]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($r) {
                        $pdo->prepare("UPDATE reservations SET status='completed' WHERE id = ?")
                            ->execute([$reservationId]);
                        $pdo->prepare("UPDATE parking_spots SET status='available' WHERE id = ?")
                            ->execute([$r['spot_id']]);
                        $flash = 'Reservation ended.';
                        $flash_type = 'ok';
                    } else {
                        $flash = 'Reservation not active.';
                        $flash_type = 'err';
                    }
                    $pdo->commit();
                }
            }

            if ($action === 'end_session') {
                $sessionId = (int)($_POST['session_id'] ?? 0);
                if ($sessionId > 0) {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT spot_id FROM parking_sessions WHERE id = ? AND status='active'");
                    $stmt->execute([$sessionId]);
                    $s = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($s) {
                        $pdo->prepare("UPDATE parking_sessions SET status='completed', exit_time=NOW() WHERE id = ?")
                            ->execute([$sessionId]);
                        $pdo->prepare("UPDATE parking_spots SET status='available' WHERE id = ?")
                            ->execute([$s['spot_id']]);
                        $flash = 'Parking session ended.';
                        $flash_type = 'ok';
                    } else {
                        $flash = 'Session not active.';
                        $flash_type = 'err';
                    }
                    $pdo->commit();
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash = 'Action failed: ' . $e->getMessage();
            $flash_type = 'err';
        }
    }
}

$totalUsers         = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalSpots         = (int)$pdo->query('SELECT COUNT(*) FROM parking_spots')->fetchColumn();
$availableSpots     = (int)$pdo->query("SELECT COUNT(*) FROM parking_spots WHERE status = 'available'")->fetchColumn();
$reservedSpots      = (int)$pdo->query("SELECT COUNT(*) FROM parking_spots WHERE status = 'reserved'")->fetchColumn();
$occupiedSpots      = (int)$pdo->query("SELECT COUNT(*) FROM parking_spots WHERE status = 'occupied'")->fetchColumn();
$activeReservations = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'active'")->fetchColumn();
$activeSessions     = (int)$pdo->query("SELECT COUNT(*) FROM parking_sessions WHERE status = 'active'")->fetchColumn();
$totalRevenue       = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();

// Income breakdown by calendar period (PostgreSQL date_trunc uses the server timezone).
$incomeToday   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE paid_at >= CURRENT_DATE")->fetchColumn();
$incomeWeek    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE paid_at >= date_trunc('week',  CURRENT_DATE)")->fetchColumn();
$incomeMonth   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE paid_at >= date_trunc('month', CURRENT_DATE)")->fetchColumn();
$incomeYear    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE paid_at >= date_trunc('year',  CURRENT_DATE)")->fetchColumn();

$paymentsCount = (int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
$avgPayment    = $paymentsCount > 0 ? $totalRevenue / $paymentsCount : 0.0;

$incomeByMethod = $pdo->query("
    SELECT payment_method, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM payments
    GROUP BY payment_method
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

$incomeByType = $pdo->query("
    SELECT payment_type, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM payments
    GROUP BY payment_type
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Daily series for the last 7 days, filling gaps with 0 so the chart is continuous.
$rawDaily = $pdo->query("
    SELECT TO_CHAR(DATE(paid_at), 'YYYY-MM-DD') AS day, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE paid_at >= CURRENT_DATE - INTERVAL '6 days'
    GROUP BY day
    ORDER BY day
")->fetchAll(PDO::FETCH_KEY_PAIR);

$last7Days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = (new DateTime("-{$i} days"))->format('Y-m-d');
    $last7Days[$d] = isset($rawDaily[$d]) ? (float)$rawDaily[$d] : 0.0;
}
$max7 = max($last7Days) ?: 1.0;

$users = $pdo->query('
    SELECT id, first_name, last_name, email, role, created_at, auth_provider
    FROM users
    ORDER BY id DESC
    LIMIT 50
')->fetchAll(PDO::FETCH_ASSOC);

$spots = $pdo->query('
    SELECT id, spot_number, status
    FROM parking_spots
    ORDER BY spot_number
')->fetchAll(PDO::FETCH_ASSOC);

$reservations = $pdo->query("
    SELECT r.id, r.user_id, r.spot_id, r.reservation_start, r.reservation_end, r.status,
           u.first_name, u.last_name, u.email,
           ps.spot_number
    FROM reservations r
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN parking_spots ps ON ps.id = r.spot_id
    ORDER BY r.id DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

$sessions = $pdo->query("
    SELECT s.id, s.entry_time, s.exit_time, s.status,
           ps.spot_number,
           v.license_plate,
           u.id AS user_id, u.first_name, u.last_name, u.email
    FROM parking_sessions s
    LEFT JOIN parking_spots ps ON ps.id = s.spot_id
    LEFT JOIN vehicles v ON v.id = s.vehicle_id
    LEFT JOIN users u ON u.id = v.user_id
    ORDER BY s.id DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

function fmt_dt($s) {
    if (!$s) return '-';
    try {
        $d = new DateTime($s);
        return $d->format('Y-m-d H:i');
    } catch (Throwable $e) { return $s; }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel — Parkster</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root {
      --bg:#0f1220; --card:#171b2e; --card-2:#1d2238;
      --text:#e9edf7; --muted:#a5b0c7;
      --ok:#2ec27e; --warn:#f6c453; --danger:#e85d75;
      --accent:#6c8cff; --border:#2a3150;
    }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--bg); color:var(--text);
           font-family:Inter,Segoe UI,Arial,sans-serif; }
    .wrap { max-width:1280px; margin:0 auto; padding:20px; }
    .top { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:10px;}
    .top h1 { margin:0; font-size:22px; letter-spacing:.3px;}
    .top a { color:#fff; text-decoration:none; margin-left:10px;
             padding:8px 14px; background:#202845; border:1px solid #3b4b7a; border-radius:8px; font-size:13px;}
    .top a:hover { background:#2a3556; }
    .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
    .card { background:var(--card); border-radius:12px; padding:14px;
            border:1px solid var(--border); }
    .card h3 { margin:0 0 12px; font-size:15px; letter-spacing:.3px;}
    .kpi  { font-size:26px; font-weight:700; margin-top:6px; }
    .muted { color:var(--muted); font-size:12px; }
    .flash { background:#1f2a44; border:1px solid #34456e; border-radius:8px; padding:10px; margin-bottom:12px; }
    .flash.ok { background:rgba(46,194,126,.10); border-color:rgba(46,194,126,.4); color:#9be8c4;}
    .flash.err { background:rgba(232,93,117,.10); border-color:rgba(232,93,117,.4); color:#f3b1bd;}
    .sections { display:grid; grid-template-columns:1fr; gap:14px; }
    .table-wrap { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:13px; min-width:560px;}
    th, td { text-align:left; padding:10px; border-bottom:1px solid var(--border); vertical-align:middle; }
    th { color:#c7d1ea; font-weight:600; background:#1a1f37; position:sticky; top:0;}
    tr:hover td { background:rgba(108,140,255,.04); }
    select, button, input[type="text"] {
      background:#202845; color:#fff; border:1px solid #3b4b7a; border-radius:7px;
      padding:6px 8px; font-size:12px; font-family:inherit;
    }
    button { cursor:pointer; }
    button:hover { background:#2a3556; }
    .btn-danger { border-color:#7a3040; background:#3a1d25; }
    .btn-danger:hover { background:#5a2630; }
    .btn-ok     { border-color:#2e7a55; background:#1d3a2c; }
    .btn-ok:hover { background:#27553e; }
    .badge { padding:3px 8px; border-radius:999px; font-size:11px; display:inline-block; font-weight:500;}
    .b-ok { background:rgba(46,194,126,.15); color:var(--ok); }
    .b-warn { background:rgba(246,196,83,.15); color:var(--warn); }
    .b-danger { background:rgba(232,93,117,.15); color:var(--danger); }
    .b-info { background:rgba(108,140,255,.15); color:var(--accent); }
    .b-muted { background:rgba(165,176,199,.15); color:var(--muted); }
    .row-actions { display:flex; gap:6px; flex-wrap:wrap; }
    .row-actions form { margin:0; }
    .search-box { margin-bottom:10px; }
    .search-box input { width:240px; }
    .empty { padding:14px; color:var(--muted); text-align:center; }
    @media (max-width:980px){ .grid{grid-template-columns:repeat(2,1fr);} }
    @media (max-width:640px){ .grid{grid-template-columns:1fr;} .wrap{padding:12px;} }

    .balance-card { margin-bottom:16px; }
    .balance-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:18px; }
    .bal-tile { background:var(--card-2); border:1px solid var(--border); border-radius:10px; padding:12px; }
    .bal-amount { font-size:22px; font-weight:700; margin:6px 0; }
    .bal-sub { margin:0 0 10px; font-size:13px; color:var(--muted); text-transform:uppercase; letter-spacing:.6px;}
    .balance-split { display:grid; grid-template-columns:1.4fr 1fr; gap:18px; }
    .bar-chart { display:flex; align-items:flex-end; gap:10px; height:160px; padding:10px;
                 background:var(--card-2); border:1px solid var(--border); border-radius:10px; }
    .bar-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%;}
    .bar-val { font-size:11px; color:var(--muted); margin-bottom:4px; }
    .bar { width:100%; background:linear-gradient(180deg, var(--accent), #3a55b5);
           border-radius:6px 6px 0 0; min-height:2px; transition:height .3s; }
    .bar-label { font-size:11px; color:var(--muted); margin-top:6px; }
    .bal-breakdowns { display:flex; flex-direction:column; gap:14px; }
    .bal-row { display:flex; justify-content:space-between; align-items:center;
               padding:6px 0; border-bottom:1px dashed var(--border); font-size:13px;}
    .bal-row:last-child { border-bottom:none; }
    .bal-row .badge { font-size:11px; }
    @media (max-width:980px){ .balance-grid{grid-template-columns:repeat(2,1fr);} .balance-split{grid-template-columns:1fr;} }
    @media (max-width:640px){ .balance-grid{grid-template-columns:1fr;} }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <h1>🛠️ Parkster Admin</h1>
      <div>
        <a href="index.php">← Main App</a>
        <a href="functions/logout.php">Logout</a>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="grid">
      <div class="card"><div class="muted">Total Users</div><div class="kpi"><?= $totalUsers ?></div></div>
      <div class="card"><div class="muted">Total Spots</div><div class="kpi"><?= $totalSpots ?></div></div>
      <div class="card"><div class="muted">Available</div><div class="kpi" style="color:var(--ok)"><?= $availableSpots ?></div></div>
      <div class="card"><div class="muted">Reserved / Occupied</div><div class="kpi"><span style="color:var(--warn)"><?= $reservedSpots ?></span> / <span style="color:var(--danger)"><?= $occupiedSpots ?></span></div></div>
      <div class="card"><div class="muted">Active Reservations</div><div class="kpi" style="color:var(--warn)"><?= $activeReservations ?></div></div>
      <div class="card"><div class="muted">Active Sessions</div><div class="kpi" style="color:var(--danger)"><?= $activeSessions ?></div></div>
      <div class="card"><div class="muted">Total Revenue</div><div class="kpi" style="color:var(--ok)">€<?= number_format($totalRevenue, 2) ?></div></div>
      <div class="card"><div class="muted">Occupancy</div>
        <div class="kpi"><?= $totalSpots > 0 ? round((($reservedSpots+$occupiedSpots)/$totalSpots)*100) : 0 ?>%</div>
      </div>
    </div>

    <div class="card balance-card">
      <h3>💰 Balance / Income</h3>
      <div class="balance-grid">
        <div class="bal-tile">
          <div class="muted">Today</div>
          <div class="bal-amount" style="color:var(--accent)">€<?= number_format($incomeToday, 2) ?></div>
          <div class="muted"><?= (new DateTime())->format('D, M j') ?></div>
        </div>
        <div class="bal-tile">
          <div class="muted">This Week</div>
          <div class="bal-amount" style="color:var(--ok)">€<?= number_format($incomeWeek, 2) ?></div>
          <div class="muted">Since Mon, <?= (new DateTime('monday this week'))->format('M j') ?></div>
        </div>
        <div class="bal-tile">
          <div class="muted">This Month</div>
          <div class="bal-amount" style="color:var(--warn)">€<?= number_format($incomeMonth, 2) ?></div>
          <div class="muted"><?= (new DateTime())->format('F Y') ?></div>
        </div>
        <div class="bal-tile">
          <div class="muted">This Year</div>
          <div class="bal-amount" style="color:var(--danger)">€<?= number_format($incomeYear, 2) ?></div>
          <div class="muted"><?= (new DateTime())->format('Y') ?></div>
        </div>
      </div>

      <div class="balance-split">
        <div>
          <h4 class="bal-sub">Last 7 days</h4>
          <div class="bar-chart">
            <?php foreach ($last7Days as $day => $val): ?>
              <?php $h = (int)round(($val / $max7) * 100); if ($h < 2 && $val > 0) $h = 2; ?>
              <div class="bar-col" title="<?= $day ?>: €<?= number_format($val, 2) ?>">
                <div class="bar-val">€<?= number_format($val, 0) ?></div>
                <div class="bar" style="height:<?= $h ?>%"></div>
                <div class="bar-label"><?= (new DateTime($day))->format('D') ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="bal-breakdowns">
          <div>
            <h4 class="bal-sub">By payment method</h4>
            <?php if (empty($incomeByMethod)): ?>
              <div class="muted">No payments yet.</div>
            <?php else: foreach ($incomeByMethod as $m): ?>
              <div class="bal-row">
                <span class="badge b-info"><?= htmlspecialchars($m['payment_method']) ?></span>
                <span>€<?= number_format((float)$m['total'], 2) ?></span>
                <span class="muted">(<?= (int)$m['cnt'] ?>)</span>
              </div>
            <?php endforeach; endif; ?>
          </div>
          <div>
            <h4 class="bal-sub">By payment type</h4>
            <?php if (empty($incomeByType)): ?>
              <div class="muted">No payments yet.</div>
            <?php else: foreach ($incomeByType as $t): ?>
              <div class="bal-row">
                <span class="badge b-warn"><?= htmlspecialchars($t['payment_type']) ?></span>
                <span>€<?= number_format((float)$t['total'], 2) ?></span>
                <span class="muted">(<?= (int)$t['cnt'] ?>)</span>
              </div>
            <?php endforeach; endif; ?>
          </div>
          <div>
            <h4 class="bal-sub">Totals</h4>
            <div class="bal-row"><span class="muted">Payments</span><span><?= $paymentsCount ?></span></div>
            <div class="bal-row"><span class="muted">Average</span><span>€<?= number_format($avgPayment, 2) ?></span></div>
            <div class="bal-row"><span class="muted">All-time</span><span>€<?= number_format($totalRevenue, 2) ?></span></div>
          </div>
        </div>
      </div>
    </div>

    <div class="sections">

      <div class="card">
        <h3>👥 Users</h3>
        <div class="search-box">
          <input type="text" id="userSearch" placeholder="Search by name or email…" onkeyup="filterTable('userSearch','usersTable')">
        </div>
        <div class="table-wrap">
        <table id="usersTable">
          <thead>
            <tr>
              <th>ID</th><th>Name</th><th>Email</th><th>Provider</th><th>Role</th><th>Created</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
              <tr><td colspan="7" class="empty">No users.</td></tr>
            <?php else: ?>
              <?php foreach ($users as $u): ?>
                <?php $role = $u['role'] ?? 'customer'; ?>
                <tr>
                  <td><?= (int)$u['id'] ?></td>
                  <td><?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?></td>
                  <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                  <td>
                    <span class="badge <?= ($u['auth_provider'] ?? 'local') === 'google' ? 'b-info' : 'b-muted' ?>">
                      <?= htmlspecialchars($u['auth_provider'] ?? 'local') ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge <?= $role === 'admin' ? 'b-warn' : 'b-info' ?>">
                      <?= htmlspecialchars($role) ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars(fmt_dt($u['created_at'] ?? '')) ?></td>
                  <td>
                    <div class="row-actions">
                      <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="update_user_role">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <select name="role">
                          <option value="customer" <?= $role === 'customer' ? 'selected' : '' ?>>customer</option>
                          <option value="admin"    <?= $role === 'admin'    ? 'selected' : '' ?>>admin</option>
                        </select>
                        <button type="submit" class="btn-ok">Save</button>
                      </form>
                      <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                        <form method="POST" onsubmit="return confirm('Delete user #<?= (int)$u['id'] ?> and all their data?')">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="delete_user">
                          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                          <button type="submit" class="btn-danger">Delete</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>

      <div class="card">
        <h3>🅿️ Parking Spots</h3>
        <div class="search-box">
          <input type="text" id="spotSearch" placeholder="Search spot…" onkeyup="filterTable('spotSearch','spotsTable')">
        </div>
        <div class="table-wrap">
        <table id="spotsTable">
          <thead>
            <tr>
              <th>ID</th><th>Spot</th><th>Status</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($spots as $s): ?>
              <?php $st = $s['status'] ?? 'available'; ?>
              <tr>
                <td><?= (int)$s['id'] ?></td>
                <td><strong><?= htmlspecialchars($s['spot_number'] ?? '-') ?></strong></td>
                <td>
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
                      <option value="reserved"  <?= $st === 'reserved'  ? 'selected' : '' ?>>reserved</option>
                      <option value="occupied"  <?= $st === 'occupied'  ? 'selected' : '' ?>>occupied</option>
                    </select>
                    <button type="submit" class="btn-ok">Save</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>

      <div class="card">
        <h3>🚗 Recent Parking Sessions</h3>
        <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>User</th><th>Spot</th><th>Plate</th><th>Entry</th><th>Exit</th><th>Status</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($sessions)): ?>
              <tr><td colspan="8" class="empty">No sessions yet.</td></tr>
            <?php else: ?>
              <?php foreach ($sessions as $s): ?>
                <?php $st = $s['status'] ?? '-'; ?>
                <tr>
                  <td><?= (int)$s['id'] ?></td>
                  <td>
                    <?= htmlspecialchars(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ?>
                    <div class="muted"><?= htmlspecialchars($s['email'] ?? '') ?></div>
                  </td>
                  <td><strong><?= htmlspecialchars($s['spot_number'] ?? '-') ?></strong></td>
                  <td><?= htmlspecialchars($s['license_plate'] ?? '-') ?></td>
                  <td><?= htmlspecialchars(fmt_dt($s['entry_time'] ?? '')) ?></td>
                  <td><?= htmlspecialchars(fmt_dt($s['exit_time'] ?? '')) ?></td>
                  <td>
                    <span class="badge <?= $st === 'active' ? 'b-danger' : 'b-muted' ?>">
                      <?= htmlspecialchars($st) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($st === 'active'): ?>
                      <form method="POST" onsubmit="return confirm('Force-end this session?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="end_session">
                        <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="btn-danger">End</button>
                      </form>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>

      <div class="card">
        <h3>📅 Recent Reservations</h3>
        <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>User</th><th>Spot</th><th>Start</th><th>End</th><th>Status</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($reservations)): ?>
              <tr><td colspan="7" class="empty">No reservations yet.</td></tr>
            <?php else: ?>
              <?php foreach ($reservations as $r): ?>
                <?php $st = $r['status'] ?? '-'; ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td>
                    <?= htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?>
                    <div class="muted"><?= htmlspecialchars($r['email'] ?? '') ?></div>
                  </td>
                  <td><strong><?= htmlspecialchars($r['spot_number'] ?? '-') ?></strong></td>
                  <td><?= htmlspecialchars(fmt_dt($r['reservation_start'] ?? '')) ?></td>
                  <td><?= htmlspecialchars(fmt_dt($r['reservation_end'] ?? '')) ?></td>
                  <td>
                    <?php
                      $bcls = 'b-muted';
                      if ($st === 'active') $bcls = 'b-warn';
                      elseif ($st === 'completed') $bcls = 'b-ok';
                      elseif ($st === 'cancelled' || $st === 'no-show') $bcls = 'b-danger';
                    ?>
                    <span class="badge <?= $bcls ?>"><?= htmlspecialchars($st) ?></span>
                  </td>
                  <td>
                    <div class="row-actions">
                      <?php if ($st === 'active'): ?>
                        <form method="POST" onsubmit="return confirm('End this reservation now?')">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="end_reservation">
                          <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                          <button type="submit" class="btn-ok">End</button>
                        </form>
                      <?php endif; ?>
                      <form method="POST" onsubmit="return confirm('Delete reservation #<?= (int)$r['id'] ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete_reservation">
                        <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="btn-danger">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>

    </div>
  </div>

  <script>
    function filterTable(inputId, tableId) {
      const q = (document.getElementById(inputId).value || '').toLowerCase();
      const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
      rows.forEach(tr => {
        const txt = tr.innerText.toLowerCase();
        tr.style.display = txt.includes(q) ? '' : 'none';
      });
    }
  </script>
</body>
</html>