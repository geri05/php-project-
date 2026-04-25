<?php
session_start();
require_once 'database/db.php';

header('Content-Type: application/json');

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$spot_number     = trim($data['spot_number']     ?? '');
$duration_hours  = intval($data['duration_hours'] ?? 1);
$paypal_order_id = trim($data['paypal_order_id'] ?? '');
$amount          = floatval($data['amount']       ?? 0);

if (!$spot_number || $duration_hours < 1 || !$paypal_order_id || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid parameters']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // 1. Lock & verify the spot is still available
    $stmt = $pdo->prepare("
        SELECT id, status FROM parking_spots
        WHERE spot_number = ?
        FOR UPDATE
    ");
    $stmt->execute([$spot_number]);
    $spot = $stmt->fetch();

    if (!$spot) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Spot not found']);
        exit;
    }
    if ($spot['status'] !== 'available') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Spot is no longer available. Please choose another.']);
        exit;
    }

    $spot_id = $spot['id'];
    $now     = new DateTime();
    $end     = (clone $now)->modify("+{$duration_hours} hours");

    $res_start = $now->format('Y-m-d H:i:s');
    $res_end   = $end->format('Y-m-d H:i:s');

    // 2. Get or create a vehicle record for this user
    $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $vehicle = $stmt->fetch();

    if (!$vehicle) {
        $stmt = $pdo->prepare("
            INSERT INTO vehicles (license_plate, user_id)
            VALUES (?, ?)
            RETURNING id
        ");
        $plate = 'USER-' . $user_id . '-AUTO';
        $stmt->execute([$plate, $user_id]);
        $vehicle_id = $stmt->fetchColumn();
    } else {
        $vehicle_id = $vehicle['id'];
    }

    // 3. Insert reservation
    $stmt = $pdo->prepare("
        INSERT INTO reservations (user_id, spot_id, vehicle_id, reservation_start, reservation_end, status)
        VALUES (?, ?, ?, ?, ?, 'active')
        RETURNING id
    ");
    $stmt->execute([$user_id, $spot_id, $vehicle_id, $res_start, $res_end]);
    $reservation_id = $stmt->fetchColumn();

    // 4. Insert payment record (PayPal)
    $stmt = $pdo->prepare("
        INSERT INTO payments (reservation_id, amount, payment_method, payment_type, paid_at)
        VALUES (?, ?, 'paypal', 'reservation_fee', NOW())
    ");
    $stmt->execute([$reservation_id, $amount]);

    // 5. Mark the spot as reserved
    $stmt = $pdo->prepare("UPDATE parking_spots SET status = 'reserved' WHERE id = ?");
    $stmt->execute([$spot_id]);

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'reservation_id' => $reservation_id,
        'spot_number'    => $spot_number,
        'start'          => $res_start,
        'end'            => $res_end,
        'amount'         => $amount,
        'paypal_order'   => $paypal_order_id,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>