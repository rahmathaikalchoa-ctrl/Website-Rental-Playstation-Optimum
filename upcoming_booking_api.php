<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(null);
  exit;
}

$userId = intval($_SESSION['user_id']);

$stmt = $conn->prepare("
  SELECT b.id, r.title AS room, b.start_time, b.end_time, b.duration
  FROM bookings b
  JOIN rooms r ON r.id = b.room_id
  WHERE b.user_id = ? AND b.start_time > UNIX_TIMESTAMP()
  ORDER BY b.start_time ASC
  LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode($row ?: null);
