<?php
/*
 * payment_history.php — Returns the logged-in user's payments (with spot + duration)
 * for display in the Payment History modal. Read-only JSON endpoint.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once __DIR__ . '/../database/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT
            p.id              AS payment_id,
            p.amount,
            p.payment_method,
            p.payment_type,
            p.paid_at,
            ps.id             AS session_id,
            ps.entry_time,
            ps.exit_time,
            sp.spot_number
        FROM payments p
        LEFT JOIN parking_sessions ps ON ps.id = p.session_id
        LEFT JOIN vehicles v          ON v.id  = ps.vehicle_id
        LEFT JOIN parking_spots sp    ON sp.id = ps.spot_id
        WHERE v.user_id = ?
        ORDER BY p.paid_at DESC
    ");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'payments' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('[payment_history.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load payment history.']);
}
