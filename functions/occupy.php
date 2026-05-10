<?php
/*
 * occupy.php — Creates an active parking session for the authenticated user
 * on a given spot. Handles auto-cleanup of expired reservations, concurrency
 * locking, and automatic vehicle assignment before starting the session.
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

$spot_number = trim($data['spot_number'] ?? '');
if ($spot_number === '') {
    echo json_encode(['success' => false, 'error' => 'Missing spot number']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    $cleanup = $pdo->prepare("
        UPDATE reservations
           SET status = 'no-show'
         WHERE user_id = ? AND status = 'active' AND reservation_end < NOW()
    ");
    $cleanup->execute([$user_id]);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    if (!$stmt->fetch()) {
        session_destroy();
        echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
        exit;
    }

    $check = $pdo->prepare("
        SELECT 1 FROM reservations
         WHERE user_id = ? AND status = 'active' AND reservation_end > NOW()
        UNION
        SELECT 1 FROM parking_sessions ps
          JOIN vehicles v ON ps.vehicle_id = v.id
         WHERE v.user_id = ? AND ps.status = 'active'
        LIMIT 1
    ");
    $check->execute([$user_id, $user_id]);
    if ($check->fetch()) {
        echo json_encode([
            'success' => false,
            'error'   => 'You already have an active reservation or session. Please end it before occupying a new spot.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, status FROM parking_spots WHERE spot_number = ?");
    $stmt->execute([$spot_number]);
    $spot = $stmt->fetch();

    if (!$spot) {
        echo json_encode(['success' => false, 'error' => 'Spot not found']);
        exit;
    }
    if ($spot['status'] !== 'available') {
        echo json_encode(['success' => false, 'error' => 'This spot is no longer available. Please choose another.']);
        exit;
    }

    $spot_id = (int)$spot['id'];

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status FROM parking_spots WHERE id = ? FOR UPDATE");
    $stmt->execute([$spot_id]);
    $spotLocked = $stmt->fetch();
    if (!$spotLocked || $spotLocked['status'] !== 'available') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Spot was taken by someone else.']);
        exit;
    }

    $now = new DateTime();

    $vehicle_id = null;
    $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $vehicle = $stmt->fetch();

    if ($vehicle) {
        $vehicle_id = (int)$vehicle['id'];
    } else {
        $plate = 'USER-' . $user_id . '-AUTO';
        $stmt = $pdo->prepare("SELECT id, user_id FROM vehicles WHERE license_plate = ?");
        $stmt->execute([$plate]);
        $existing = $stmt->fetch();

        if ($existing && (int)$existing['user_id'] === $user_id) {
            $vehicle_id = (int)$existing['id'];
        } else {
            if ($existing) {
                $plate = 'USER-' . $user_id . '-' . substr(md5(uniqid('', true)), 0, 6);
            }
            $stmt = $pdo->prepare("INSERT INTO vehicles (license_plate, user_id) VALUES (?, ?) RETURNING id");
            $stmt->execute([$plate, $user_id]);
            $vehicle_id = (int)$stmt->fetchColumn();
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO parking_sessions (vehicle_id, spot_id, entry_time, status)
        VALUES (?, ?, ?, 'active')
        RETURNING id
    ");
    $stmt->execute([$vehicle_id, $spot_id, $now->format('Y-m-d H:i:s')]);
    $session_id = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("UPDATE parking_spots SET status = 'occupied' WHERE id = ?");
    $stmt->execute([$spot_id]);

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'session_id'  => $session_id,
        'spot_number' => $spot_number,
        'entry_time'  => $now->format('c'),
        'message'     => 'Spot occupied successfully!'
    ]);

} catch (Throwable $e) {
    hardResetConnection($pdo);
    error_log('[occupy.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to occupy spot. Please try again.',
    ]);
}