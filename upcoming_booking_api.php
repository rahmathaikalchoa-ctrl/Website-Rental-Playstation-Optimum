<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/booking_lib.php';
// Booking yang lewat batas check-in dibatalkan dulu supaya data yang tampil akurat
releaseNoShows($conn);

if (!isset($_SESSION['user_id'])) {
  echo json_encode(null);
  exit;
}

$userId = intval($_SESSION['user_id']);

// Sesi yang sedang berjalan atau booking terdekat yang belum selesai
$stmt = $conn->prepare("
  SELECT b.id, r.title AS room, b.start_time, b.end_time, b.duration, b.total_price,
         b.order_code, b.expires_at, b.payment_status
  FROM bookings b
  JOIN rooms r ON r.id = b.room_id
  WHERE b.user_id = ? AND b.end_time > UNIX_TIMESTAMP()
    AND b.payment_status <> 'cancelled'
  ORDER BY b.start_time ASC
  LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode($row ?: null);
