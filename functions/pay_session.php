<?php
/*
 * pay_session.php — Processes payment for an active parking session.
 * Calculates the total fee, records the payment, marks the session as
 * completed, and releases the parking spot.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/pricing.php';

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

$payment_method = strtolower(trim($data['payment_method'] ?? ''));
if (!in_array($payment_method, ['cash', 'card'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment method']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT ps.id, ps.entry_time, ps.spot_id, sp.spot_number
          FROM parking_sessions ps
          JOIN vehicles v ON v.id = ps.vehicle_id
          JOIN parking_spots sp ON sp.id = ps.spot_id
         WHERE v.user_id = ? AND ps.status = 'active'
         ORDER BY ps.id DESC
         LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$user_id]);
    $session = $stmt->fetch();

    if (!$session) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error'   => 'No active session found to pay for.'
        ]);
        exit;
    }

    $session_id  = (int) $session['id'];
    $spot_id     = (int) $session['spot_id'];
    $spot_number = $session['spot_number'];

    $entry       = new DateTime($session['entry_time']);
    $now         = new DateTime();
    $elapsed_sec = max(0, $now->getTimestamp() - $entry->getTimestamp());
    $fee         = calculate_fee($elapsed_sec);
    $total_fee   = $fee['amount'];

    $p = $pdo->prepare("
        INSERT INTO payments (session_id, amount, payment_method, payment_type)
        VALUES (?, ?, ?, 'stay_fee')
        RETURNING id
    ");
    $p->execute([$session_id, $total_fee, $payment_method]);
    $payment_id = (int) $p->fetchColumn();

    $u = $pdo->prepare("
        UPDATE parking_sessions
           SET status = 'completed',
               exit_time = ?,
               total_fee = ?
         WHERE id = ?
    ");
    $u->execute([$now->format('Y-m-d H:i:s'), $total_fee, $session_id]);

    $u = $pdo->prepare("UPDATE parking_spots SET status = 'available' WHERE id = ?");
    $u->execute([$spot_id]);

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'payment_id'     => $payment_id,
        'session_id'     => $session_id,
        'spot_number'    => $spot_number,
        'amount'         => $total_fee,
        'payment_method' => $payment_method,
        'duration'       => [
            'seconds' => $elapsed_sec,
            'hours'   => $fee['hours'],
            'detail'  => $fee['detail'],
            'plan'    => $fee['plan'],
        ],
        'message' => 'Payment successful! Spot has been released.'
    ]);

} catch (Throwable $e) {
    hardResetConnection($pdo);
    error_log('[pay_session.php] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Payment failed. Please try again.',
    ]);
}