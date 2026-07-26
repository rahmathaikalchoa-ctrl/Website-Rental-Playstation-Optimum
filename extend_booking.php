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
$maxDuration = 12; // sama dengan batas maksimal di apikr.php

if ($bookingId <= 0 || $extraHours < 1 || $extraHours > 5) {
  echo json_encode(["status" => "error", "message" => "Data tidak valid"]);
  exit;
}

// Ambil booking — harus milik user ini
$stmt = $conn->prepare("
  SELECT id, room_id, start_time, end_time, duration
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

$newDuration = intval($booking['duration']) + $extraHours;
if ($newDuration > $maxDuration) {
  echo json_encode(["status" => "error", "message" => "Total durasi tidak boleh lebih dari $maxDuration jam"]);
  exit;
}

$currentEnd = intval($booking['end_time']);
$newEnd     = $currentEnd + ($extraHours * 3600);
$roomId     = intval($booking['room_id']);

// Perpanjangan tidak boleh melewati jam tutup (tengah malam hari yang sama dengan mulainya sesi)
$dayStart   = strtotime(date('Y-m-d', intval($booking['start_time'])));
$closeLimit = $dayStart + 24 * 3600;
if ($newEnd > $closeLimit) {
  echo json_encode(["status" => "error", "message" => "Perpanjangan melewati jam operasional (maks sampai tengah malam)"]);
  exit;
}

// Transaksi + row lock pada ruangan supaya cek-bentrok dan update atomik
// (mencegah dua perpanjangan/booking lolos bersamaan untuk slot yang sama).
try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("SELECT id, price FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE");
  $stmt->bind_param("i", $roomId);
  $stmt->execute();
  $room = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$room) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Room tidak ditemukan"]);
    exit;
  }

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
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Ruangan sudah dibooking di jam perpanjangan tersebut"]);
    exit;
  }

  $extraCost = intval($room['price']) * $extraHours;

  // Update end_time, durasi, dan total_price
  $stmt = $conn->prepare("
    UPDATE bookings
    SET end_time = ?, duration = duration + ?, total_price = total_price + ?
    WHERE id = ?
  ");
  $stmt->bind_param("iiii", $newEnd, $extraHours, $extraCost, $bookingId);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();

  $conn->commit();

  echo json_encode($ok
    ? ["status" => "ok", "new_end" => $newEnd]
    : ["status" => "error", "message" => "Gagal memperpanjang booking"]
  );
} catch (\Throwable $e) {
  $conn->rollback();
  error_log("Extend booking failed: " . $e->getMessage());
  echo json_encode(["status" => "error", "message" => "Gagal memperpanjang booking"]);
}
