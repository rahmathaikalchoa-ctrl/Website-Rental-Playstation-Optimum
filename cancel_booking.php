<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require __DIR__ . '/queue_lib.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(["status" => "error", "message" => "Belum login"]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Invalid request"]);
  exit;
}

$userId    = intval($_SESSION['user_id']);
$bookingId = intval($_POST['booking_id'] ?? 0);

if ($bookingId <= 0) {
  echo json_encode(["status" => "error", "message" => "ID booking tidak valid"]);
  exit;
}

// Pastikan booking milik user ini dan belum dimulai
$stmt = $conn->prepare("
  SELECT id FROM bookings
  WHERE id = ? AND user_id = ? AND start_time > UNIX_TIMESTAMP()
    AND payment_status <> 'cancelled'
  LIMIT 1
");
$stmt->bind_param("ii", $bookingId, $userId);
$stmt->execute();
$found = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$found) {
  echo json_encode(["status" => "error", "message" => "Booking tidak ditemukan atau sudah dimulai"]);
  exit;
}

// Soft cancel: data tetap tersimpan di riwayat
$stmt = $conn->prepare("UPDATE bookings SET payment_status = 'cancelled' WHERE id = ?");
$stmt->bind_param("i", $bookingId);
$stmt->execute();
$ok = $stmt->affected_rows > 0;
$stmt->close();

if ($ok) processQueue($conn);

echo json_encode($ok
  ? ["status" => "ok"]
  : ["status" => "error", "message" => "Gagal membatalkan booking"]
);
