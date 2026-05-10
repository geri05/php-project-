<?php
/*
 * convert_reservation.php — Converts an active reservation into a live parking
 * session. Called when the user clicks "Occupy Now" before their reservation expires.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once __DIR__ . '/../database/db.php';

$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function hardResetConnection(PDO $pdo): void {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e) {}
    try { @$pdo->exec("ROLLBACK"); } catch (Throwable $e) {}
}
hardResetConnection($pdo);

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT r.id, r.spot_id, r.vehicle_id
          FROM reservations r
         WHERE r.user_id = ?
           AND r.status = 'active'
           AND r.reservation_end > NOW()
         ORDER BY r.id DESC
         LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$user_id]);
    $res = $stmt->fetch();

    if (!$res) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error'   => 'No active reservation found to convert.'
        ]);
        exit;
    }

    $reservation_id = (int) $res['id'];
    $spot_id        = (int) $res['spot_id'];
    $vehicle_id     = (int) $res['vehicle_id'];

    $check = $pdo->prepare("
        SELECT 1 FROM parking_sessions ps
          JOIN vehicles v ON ps.vehicle_id = v.id
         WHERE v.user_id = ? AND ps.status = 'active'
         LIMIT 1
    ");
    $check->execute([$user_id]);
    if ($check->fetch()) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error'   => 'You already have an active session.'
        ]);
        exit;
    }

    $u = $pdo->prepare("UPDATE reservations SET status = 'completed' WHERE id = ?");
    $u->execute([$reservation_id]);

    $now = new DateTime();
    $ins = $pdo->prepare("
        INSERT INTO parking_sessions (vehicle_id, spot_id, entry_time, status)
        VALUES (?, ?, ?, 'active')
        RETURNING id, spot_id
    ");
    $ins->execute([$vehicle_id, $spot_id, $now->format('Y-m-d H:i:s')]);
    $new_session = $ins->fetch();
    $session_id  = (int) $new_session['id'];

    $u = $pdo->prepare("UPDATE parking_spots SET status = 'occupied' WHERE id = ?");
    $u->execute([$spot_id]);

    $s = $pdo->prepare("SELECT spot_number FROM parking_spots WHERE id = ?");
    $s->execute([$spot_id]);
    $spot_number = $s->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'session_id'  => $session_id,
        'spot_number' => $spot_number,
        'entry_time'  => $now->format('c'),
        'message'     => 'Spot activated! Timer has started.'
    ]);

} catch (Throwable $e) {
    hardResetConnection($pdo);
    error_log('[convert_reservation.php] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Activation failed. Please try again.',
    ]);
}