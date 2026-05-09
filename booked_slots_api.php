<?php
require 'db.php';
header('Content-Type: application/json');

$roomId = intval($_GET['room_id'] ?? 0);
if ($roomId <= 0) {
  echo json_encode([]);
  exit;
}

$todayStart = mktime(0, 0, 0);
$todayEnd   = $todayStart + 86400;

$stmt = $conn->prepare("
  SELECT start_time, end_time
  FROM bookings
  WHERE room_id = ?
    AND end_time > ?
    AND start_time < ?
  ORDER BY start_time ASC
");
$stmt->bind_param("iii", $roomId, $todayStart, $todayEnd);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($rows);
