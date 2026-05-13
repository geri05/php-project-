<?php
/*
 * download_bill.php — Builds a self-contained printable bill (HTML) for a single
 * payment owned by the logged-in user and forces a file download. Opening the
 * downloaded file in any browser lets the user print or save it as PDF.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once __DIR__ . '/../database/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Not authenticated';
    exit;
}

$user_id    = (int) $_SESSION['user_id'];
$payment_id = (int) ($_GET['payment_id'] ?? 0);

if ($payment_id <= 0) {
    http_response_code(400);
    echo 'Invalid payment id';
    exit;
}

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
            sp.spot_number,
            v.license_plate,
            u.first_name,
            u.last_name,
            u.email
        FROM payments p
        LEFT JOIN parking_sessions ps ON ps.id = p.session_id
        LEFT JOIN vehicles v          ON v.id  = ps.vehicle_id
        LEFT JOIN parking_spots sp    ON sp.id = ps.spot_id
        LEFT JOIN users u             ON u.id  = v.user_id
        WHERE p.id = ? AND v.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$payment_id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo 'Bill not found';
        exit;
    }
} catch (Throwable $e) {
    error_log('[download_bill.php] ' . $e->getMessage());
    http_response_code(500);
    echo 'Could not generate bill';
    exit;
}

function fmt_dt(?string $s): string {
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('Y-m-d H:i', $t) : '—';
}

function fmt_duration(?string $start, ?string $end): string {
    if (!$start || !$end) return '—';
    $sec = max(0, strtotime($end) - strtotime($start));
    $h   = (int) floor($sec / 3600);
    $m   = (int) floor(($sec % 3600) / 60);
    return sprintf('%dh %02dm', $h, $m);
}

$bill_no    = 'BILL-' . str_pad((string)$row['payment_id'], 6, '0', STR_PAD_LEFT);
$customer   = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
$email      = $row['email']         ?? '—';
$plate      = $row['license_plate'] ?? '—';
$spot       = $row['spot_number']   ?? '—';
$method     = ucfirst((string)$row['payment_method']);
$type       = str_replace('_', ' ', (string)$row['payment_type']);
$amount     = number_format((float)$row['amount'], 2);
$paid_at    = fmt_dt($row['paid_at']);
$entry      = fmt_dt($row['entry_time']);
$exit       = fmt_dt($row['exit_time']);
$duration   = fmt_duration($row['entry_time'], $row['exit_time']);

$filename = $bill_no . '.html';

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $h($bill_no) ?> — Parking Bill</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif; color: #1a1a1a;
    margin: 0; padding: 40px; background: #f4f6f8;
  }
  .bill {
    background: #fff; max-width: 720px; margin: 0 auto;
    border-radius: 12px; padding: 40px;
    box-shadow: 0 8px 24px rgba(0,0,0,.08);
  }
  .head {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 2px solid #1cc7d0; padding-bottom: 18px; margin-bottom: 24px;
  }
  .brand { font-size: 24px; font-weight: 800; color: #1cc7d0; letter-spacing: .5px; }
  .brand small { display: block; font-size: 11px; color: #888; font-weight: 500; letter-spacing: 1.5px; }
  .bill-no { text-align: right; font-size: 13px; color: #444; }
  .bill-no strong { display: block; font-size: 16px; color: #1a1a1a; margin-bottom: 4px; }
  h2 { margin: 26px 0 10px; font-size: 14px; color: #888; letter-spacing: 1.5px; text-transform: uppercase; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 8px 0; font-size: 14px; vertical-align: top; }
  td.l { color: #777; width: 40%; }
  td.v { font-weight: 600; color: #1a1a1a; }
  .total {
    margin-top: 28px; padding: 22px; border-radius: 10px;
    background: linear-gradient(135deg, #1cc7d0, #0a99a1); color: #fff;
    display: flex; justify-content: space-between; align-items: center;
  }
  .total .lbl  { font-size: 13px; letter-spacing: 1.5px; text-transform: uppercase; opacity: .9; }
  .total .amt  { font-size: 32px; font-weight: 800; }
  .foot { margin-top: 28px; font-size: 11px; color: #999; text-align: center; line-height: 1.6; }
  .actions { text-align: center; margin: 18px 0 0; }
  .actions button {
    padding: 10px 22px; border: 0; border-radius: 6px; background: #1cc7d0; color: #fff;
    font-weight: 700; cursor: pointer; font-size: 13px;
  }
  @media print {
    body { background: #fff; padding: 0; }
    .bill { box-shadow: none; max-width: 100%; }
    .actions { display: none; }
  }
</style>
</head>
<body>
  <div class="bill">
    <div class="head">
      <div class="brand">PARKWISE<small>SMART PARKING</small></div>
      <div class="bill-no">
        <strong><?= $h($bill_no) ?></strong>
        Issued: <?= $h($paid_at) ?>
      </div>
    </div>

    <h2>Billed to</h2>
    <table>
      <tr><td class="l">Customer</td><td class="v"><?= $h($customer) ?></td></tr>
      <tr><td class="l">Email</td><td class="v"><?= $h($email) ?></td></tr>
      <tr><td class="l">Vehicle plate</td><td class="v"><?= $h($plate) ?></td></tr>
    </table>

    <h2>Session</h2>
    <table>
      <tr><td class="l">Parking spot</td><td class="v"><?= $h($spot) ?></td></tr>
      <tr><td class="l">Entry</td><td class="v"><?= $h($entry) ?></td></tr>
      <tr><td class="l">Exit</td><td class="v"><?= $h($exit) ?></td></tr>
      <tr><td class="l">Duration</td><td class="v"><?= $h($duration) ?></td></tr>
    </table>

    <h2>Payment</h2>
    <table>
      <tr><td class="l">Type</td><td class="v"><?= $h(ucfirst($type)) ?></td></tr>
      <tr><td class="l">Method</td><td class="v"><?= $h($method) ?></td></tr>
      <tr><td class="l">Paid at</td><td class="v"><?= $h($paid_at) ?></td></tr>
    </table>

    <div class="total">
      <span class="lbl">Total paid</span>
      <span class="amt"><?= $h($amount) ?> L</span>
    </div>

    <div class="actions">
      <button onclick="window.print()">Print / Save as PDF</button>
    </div>

    <p class="foot">
      Thank you for using ParkWise.<br>
      This document was automatically generated and is a valid proof of payment.
    </p>
  </div>
</body>
</html>
