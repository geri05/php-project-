<?php
/*
 * end_session.php — Handles ending a reservation or parking session.
 * Reservations can be cancelled freely; active sessions are blocked here
 * and must be closed through pay_session.php after payment.
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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$type    = $data['type'] ?? '';
$user_id = (int) $_SESSION['user_id'];

if (!in_array($type, ['reservation', 'session'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid type']);
    exit;
}

if ($type === 'session') {
    echo json_encode([
        'success'          => false,
        'requires_payment' => true,
        'error'            => 'Payment is required before releasing the spot. Please open the payment modal.'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, spot_id
          FROM reservations
         WHERE user_id = ? AND status = 'active'
         ORDER BY id DESC
         LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$user_id]);
    $res = $stmt->fetch();

    if (!$res) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error'   => 'No active reservation to cancel.'
        ]);
        exit;
    }

    $reservation_id = (int) $res['id'];
    $spot_id        = (int) $res['spot_id'];

    $u = $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?");
    $u->execute([$reservation_id]);

    $check = $pdo->prepare("
        SELECT 1 FROM parking_sessions
         WHERE spot_id = ? AND status = 'active'
         LIMIT 1
    ");
    $check->execute([$spot_id]);
    if (!$check->fetch()) {
        $u = $pdo->prepare("UPDATE parking_spots SET status = 'available' WHERE id = ?");
        $u->execute([$spot_id]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Reservation cancelled successfully.'
    ]);

} catch (Throwable $e) {
    hardResetConnection($pdo);
    error_log('[end_session.php] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Cancellation failed. Please try again.',
    ]);
}