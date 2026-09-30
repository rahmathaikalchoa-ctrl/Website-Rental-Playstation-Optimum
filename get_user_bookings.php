<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/booking_lib.php';
// Booking yang lewat batas check-in dibatalkan dulu supaya data yang tampil akurat
releaseNoShows($conn);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) {
  echo json_encode([]);
  exit;
}

$user_id = (int) $_SESSION['user_id'];

$sql = "
  SELECT
    b.id,
    b.room_id,
    r.title AS room,
    r.price,
    b.duration,
    b.start_time,
    b.end_time,
    b.total_price,
    b.order_code,
    b.payment_status,
    b.payment_method,
    EXISTS (SELECT 1 FROM booking_queue q WHERE q.booking_id = b.id) AS from_queue,
    b.expires_at,
    b.source
  FROM bookings b
  JOIN rooms r ON b.room_id = r.id
  WHERE b.user_id = ?
  ORDER BY b.start_time DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();

$res = $stmt->get_result();
$data = [];

while ($row = $res->fetch_assoc()) {
  $data[] = [
    'id'             => $row['id'],
    'room_id'        => $row['room_id'],
    'room'           => $row['room'],
    'price'          => $row['price'],
    'duration'       => $row['duration'],
    'time'           => date('H:i', (int)$row['start_time']),
    'start_time'     => $row['start_time'],
    'end_time'       => $row['end_time'],
    'total_price'    => $row['total_price'],
    'order_code'     => $row['order_code'],
    'payment_status' => $row['payment_status'],
    'payment_method' => $row['payment_method'],
    'from_queue'     => (bool) $row['from_queue'],
    'expires_at'     => $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
    'source'         => $row['source'],
  ];
}

$stmt->close();
echo json_encode($data);
