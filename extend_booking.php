<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(["status" => "error", "message" => "Belum login"]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Invalid request"]);
  exit;
}

$userId     = intval($_SESSION['user_id']);
$bookingId  = intval($_POST['booking_id'] ?? 0);
$extraHours = intval($_POST['extra_hours'] ?? 0);

if ($bookingId <= 0 || $extraHours < 1 || $extraHours > 5) {
  echo json_encode(["status" => "error", "message" => "Data tidak valid"]);
  exit;
}

// Ambil booking — harus milik user ini dan sedang aktif
$stmt = $conn->prepare("
  SELECT id, room_id, start_time, end_time
  FROM bookings
  WHERE id = ? AND user_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $bookingId, $userId);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
  echo json_encode(["status" => "error", "message" => "Booking tidak ditemukan"]);
  exit;
}

$now = time();
if ($now < $booking['start_time'] || $now >= $booking['end_time']) {
  echo json_encode(["status" => "error", "message" => "Hanya booking yang sedang aktif yang bisa diperpanjang"]);
  exit;
}

$currentEnd = intval($booking['end_time']);
$newEnd     = $currentEnd + ($extraHours * 3600);
$roomId     = intval($booking['room_id']);

// Cek konflik: ada booking lain di ruangan ini yang tumpang tindih dengan perpanjangan
$stmt = $conn->prepare("
  SELECT id FROM bookings
  WHERE room_id = ?
    AND id != ?
    AND start_time < ?
    AND end_time > ?
  LIMIT 1
");
$stmt->bind_param("iiii", $roomId, $bookingId, $newEnd, $currentEnd);
$stmt->execute();
$conflict = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($conflict) {
  echo json_encode(["status" => "error", "message" => "Ruangan sudah dibooking di jam perpanjangan tersebut"]);
  exit;
}

// Update end_time dan durasi
$stmt = $conn->prepare("
  UPDATE bookings
  SET end_time = ?, duration = duration + ?
  WHERE id = ?
");
$stmt->bind_param("iii", $newEnd, $extraHours, $bookingId);
$stmt->execute();
$ok = $stmt->affected_rows > 0;
$stmt->close();

echo json_encode($ok
  ? ["status" => "ok", "new_end" => $newEnd]
  : ["status" => "error", "message" => "Gagal memperpanjang booking"]
);
