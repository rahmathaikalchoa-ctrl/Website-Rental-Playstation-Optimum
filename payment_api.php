<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/booking_lib.php';
require_once __DIR__ . '/midtrans_lib.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Info untuk frontend: apakah bayar online aktif + URL Snap JS & client key (aman untuk publik)
if ($action === 'config') {
  echo json_encode(midtransConfigured()
    ? ["enabled" => true, "client_key" => MIDTRANS_CLIENT_KEY, "snap_js" => midtransSnapUrl() . '/snap/snap.js']
    : ["enabled" => false]);
  exit;
}

if (!isset($_SESSION['user_id'])) {
  echo json_encode(["status" => "error", "message" => "Silakan login terlebih dahulu"]);
  exit;
}
if (!midtransConfigured()) {
  echo json_encode(["status" => "error", "message" => "Pembayaran online belum tersedia. Silakan bayar di kasir."]);
  exit;
}

$userId    = intval($_SESSION['user_id']);
$bookingId = intval($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0);

function loadBooking($conn, $bookingId, $userId) {
  $stmt = $conn->prepare("
    SELECT b.*, r.title AS room FROM bookings b JOIN rooms r ON r.id = b.room_id
    WHERE b.id = ? AND b.user_id = ? LIMIT 1
  ");
  $stmt->bind_param("ii", $bookingId, $userId);
  $stmt->execute();
  $b = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $b;
}

// Sinkronkan status dari Midtrans untuk transaksi terakhir booking ini
function syncStatus($conn, $b) {
  if (empty($b['midtrans_order_id'])) return $b['payment_status'];
  $st = midtransFetchStatus($b['midtrans_order_id']);
  if (!$st || empty($st['transaction_status'])) return $b['payment_status'];
  return midtransApplyStatus($conn, $st) ?? $b['payment_status'];
}

releaseNoShows($conn);
$b = $bookingId > 0 ? loadBooking($conn, $bookingId, $userId) : null;
if (!$b) {
  echo json_encode(["status" => "error", "message" => "Booking tidak ditemukan"]);
  exit;
}

// ================= CEK STATUS =================
if ($action === 'status') {
  $status = syncStatus($conn, $b);
  echo json_encode(["status" => "ok", "payment_status" => $status]);
  exit;
}

// ================= BUAT / LANJUTKAN TRANSAKSI =================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $now = time();

  // Transaksi sebelumnya mungkin sudah dibayar atau masih berjalan
  $status = syncStatus($conn, $b);
  if ($status === 'paid') {
    echo json_encode(["status" => "error", "message" => "Booking ini sudah lunas."]);
    exit;
  }
  if ($status === 'cancelled') {
    echo json_encode(["status" => "error", "message" => "Booking ini sudah dibatalkan."]);
    exit;
  }
  $b = loadBooking($conn, $bookingId, $userId);

  if (intval($b['end_time']) <= $now) {
    echo json_encode(["status" => "error", "message" => "Sesi booking ini sudah selesai."]);
    exit;
  }

  // Giliran dari antrian "Main Sekarang" dibayar di kasir (konsumen sudah di tempat)
  $stmt = $conn->prepare("SELECT 1 FROM booking_queue WHERE booking_id = ? LIMIT 1");
  $stmt->bind_param("i", $bookingId);
  $stmt->execute();
  $fromQueue = (bool) $stmt->get_result()->fetch_row();
  $stmt->close();
  if ($fromQueue) {
    echo json_encode(["status" => "error", "message" => "Booking dari antrian dibayar di kasir."]);
    exit;
  }

  // Masih ada popup pembayaran yang aktif: pakai token yang sama (jangan buat order baru)
  if ($b['payment_status'] === 'pending' && $b['snap_token'] && intval($b['payment_expires_at']) > $now) {
    echo json_encode(["status" => "ok", "token" => $b['snap_token']]);
    exit;
  }

  // Waktu bayar dibatasi agar selesai sebelum batas lapor ke kasir
  $minutes = MIDTRANS_PAY_WINDOW_MIN;
  if ($b['expires_at'] !== null) {
    $minutes = min($minutes, intdiv(intval($b['expires_at']) - $now, 60));
  }
  if ($minutes < MIDTRANS_MIN_WINDOW_MIN) {
    echo json_encode(["status" => "error", "message" => "Waktu terlalu mepet untuk bayar online. Silakan bayar di kasir."]);
    exit;
  }

  // order_id Midtrans harus unik per percobaan bayar
  $orderId = $b['order_code'] . '-' . $now;
  $snap = midtransCreateSnap($b, $orderId, $minutes);
  if (!$snap['ok']) {
    echo json_encode(["status" => "error", "message" => $snap['message']]);
    exit;
  }

  $payExpires = $now + $minutes * 60;
  $id = intval($b['id']);
  $stmt = $conn->prepare("
    UPDATE bookings
    SET payment_status = 'pending', payment_method = 'midtrans',
        midtrans_order_id = ?, snap_token = ?, payment_expires_at = ?
    WHERE id = ? AND payment_status IN ('unpaid', 'pending')
  ");
  $stmt->bind_param("ssii", $orderId, $snap['token'], $payExpires, $id);
  $stmt->execute();
  $stmt->close();

  echo json_encode(["status" => "ok", "token" => $snap['token']]);
  exit;
}

echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
