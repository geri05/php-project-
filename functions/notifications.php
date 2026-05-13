<?php
/*
 * notifications.php — Returns synthesized notifications for the logged-in user,
 * derived from active reservations, active sessions, and recent payments.
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
$items = [];

try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.reservation_start, r.reservation_end, sp.spot_number
          FROM reservations r
          JOIN parking_spots sp ON sp.id = r.spot_id
         WHERE r.user_id = ? AND r.status = 'active' AND r.reservation_end > NOW()
         ORDER BY r.id DESC
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $start = new DateTime($r['reservation_start']);
        $end   = new DateTime($r['reservation_end']);
        $now   = new DateTime();
        $minsToStart = ($start->getTimestamp() - $now->getTimestamp()) / 60;
        $minsToEnd   = ($end->getTimestamp()   - $now->getTimestamp()) / 60;

        if ($minsToStart > 0) {
            $items[] = [
                'id'      => 'res-start-' . $r['id'],
                'level'   => 'info',
                'icon'    => 'fa-clock',
                'title'   => 'Reservation upcoming',
                'message' => "Spot {$r['spot_number']} — starts in " . max(1, (int) round($minsToStart)) . " min.",
                'time'    => $start->format('c'),
            ];
        } elseif ($minsToEnd <= 30) {
            $items[] = [
                'id'      => 'res-expire-' . $r['id'],
                'level'   => 'warning',
                'icon'    => 'fa-triangle-exclamation',
                'title'   => 'Reservation ending soon',
                'message' => "Spot {$r['spot_number']} expires in " . max(1, (int) round($minsToEnd)) . " min.",
                'time'    => $end->format('c'),
            ];
        } else {
            $items[] = [
                'id'      => 'res-active-' . $r['id'],
                'level'   => 'info',
                'icon'    => 'fa-bookmark',
                'title'   => 'Active reservation',
                'message' => "Spot {$r['spot_number']} reserved until " . $end->format('H:i') . '.',
                'time'    => $end->format('c'),
            ];
        }
    }

    $stmt = $pdo->prepare("
        SELECT ps.id, ps.entry_time, sp.spot_number
          FROM parking_sessions ps
          JOIN vehicles v       ON v.id  = ps.vehicle_id
          JOIN parking_spots sp ON sp.id = ps.spot_id
         WHERE v.user_id = ? AND ps.status = 'active'
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $entry = new DateTime($s['entry_time']);
        $now   = new DateTime();
        $hours = ($now->getTimestamp() - $entry->getTimestamp()) / 3600;

        if ($hours >= 3) {
            $items[] = [
                'id'      => 'sess-long-' . $s['id'],
                'level'   => 'warning',
                'icon'    => 'fa-hourglass-half',
                'title'   => 'Long parking session',
                'message' => "Parked at spot {$s['spot_number']} for " . number_format($hours, 1) . ' h.',
                'time'    => $entry->format('c'),
            ];
        } else {
            $items[] = [
                'id'      => 'sess-active-' . $s['id'],
                'level'   => 'info',
                'icon'    => 'fa-car-side',
                'title'   => 'Active session',
                'message' => "Parked at spot {$s['spot_number']} since " . $entry->format('H:i') . '.',
                'time'    => $entry->format('c'),
            ];
        }
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.amount, p.paid_at, sp.spot_number
          FROM payments p
          LEFT JOIN parking_sessions ps ON ps.id = p.session_id
          LEFT JOIN vehicles v          ON v.id  = ps.vehicle_id
          LEFT JOIN parking_spots sp    ON sp.id = ps.spot_id
         WHERE v.user_id = ? AND p.paid_at > NOW() - INTERVAL '7 days'
         ORDER BY p.paid_at DESC
         LIMIT 5
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $when = new DateTime($p['paid_at']);
        $items[] = [
            'id'      => 'pay-' . $p['id'],
            'level'   => 'success',
            'icon'    => 'fa-check',
            'title'   => 'Payment received',
            'message' => number_format((float) $p['amount'], 2) . ' L paid for spot ' . ($p['spot_number'] ?? '—') . '.',
            'time'    => $when->format('c'),
        ];
    }

    usort($items, fn($a, $b) => strcmp($b['time'], $a['time']));

    echo json_encode(['success' => true, 'notifications' => $items]);
} catch (Throwable $e) {
    error_log('[notifications.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load notifications.']);
}
