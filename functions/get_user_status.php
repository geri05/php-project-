<?php
/*
 * get_user_status.php — Returns the current user's parking state (active session,
 * active reservation, or none). Also auto-expires any globally overdue reservations
 * and frees their spots before responding.
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

$user_id = (int)$_SESSION['user_id'];
$now_iso = (new DateTime())->format('c');

try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.spot_id
          FROM reservations r
         WHERE r.status = 'active'
           AND r.reservation_end < NOW()
    ");
    $stmt->execute();
    $expired = $stmt->fetchAll();

    if ($expired) {
        $pdo->beginTransaction();
        try {
            foreach ($expired as $exp) {
                $u = $pdo->prepare("UPDATE reservations SET status = 'no-show' WHERE id = ?");
                $u->execute([(int) $exp['id']]);

                $check = $pdo->prepare("
                    SELECT 1 FROM parking_sessions
                     WHERE spot_id = ? AND status = 'active'
                     LIMIT 1
                ");
                $check->execute([(int) $exp['spot_id']]);
                if (!$check->fetch()) {
                    $up = $pdo->prepare("UPDATE parking_spots SET status = 'available' WHERE id = ?");
                    $up->execute([(int) $exp['spot_id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[get_user_status.php cleanup] ' . $e->getMessage());
        }
    }

    $stmt = $pdo->prepare("
        SELECT ps.id, ps.entry_time, sp.spot_number
          FROM parking_sessions ps
          JOIN vehicles v ON v.id = ps.vehicle_id
          JOIN parking_spots sp ON sp.id = ps.spot_id
         WHERE v.user_id = ? AND ps.status = 'active'
         ORDER BY ps.id DESC
         LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $session = $stmt->fetch();

    if ($session) {
        $entry       = new DateTime($session['entry_time']);
        $now         = new DateTime();
        $elapsed_sec = max(0, $now->getTimestamp() - $entry->getTimestamp());
        $fee         = calculate_fee($elapsed_sec);

        echo json_encode([
            'success'     => true,
            'type'        => 'session',
            'session_id'  => (int) $session['id'],
            'spot_number' => $session['spot_number'],
            'entry_time'  => $entry->format('c'),
            'now'         => $now_iso,
            'elapsed_s'   => $elapsed_sec,
            'current_fee' => $fee['amount'],
            'fee_detail'  => $fee['detail'],
            'fee_plan'    => $fee['plan'],
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.id, r.reservation_start, r.reservation_end, sp.spot_number
          FROM reservations r
          JOIN parking_spots sp ON sp.id = r.spot_id
         WHERE r.user_id = ? AND r.status = 'active' AND r.reservation_end > NOW()
         ORDER BY r.id DESC
         LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $res = $stmt->fetch();

    if ($res) {
        $start = new DateTime($res['reservation_start']);
        $end   = new DateTime($res['reservation_end']);
        echo json_encode([
            'success'        => true,
            'type'           => 'reservation',
            'reservation_id' => (int) $res['id'],
            'spot_number'    => $res['spot_number'],
            'start_time'     => $start->format('c'),
            'end_time'       => $end->format('c'),
            'now'            => $now_iso
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'type'    => 'none',
        'now'     => $now_iso
    ]);

} catch (Throwable $e) {
    error_log('[get_user_status.php] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage()
    ]);
}