<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
header('Content-Type: application/json');
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
    b.payment_status
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
  ];
}

$stmt->close();
echo json_encode($data);
