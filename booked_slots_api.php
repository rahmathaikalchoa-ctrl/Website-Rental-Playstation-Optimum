<?php
require 'db.php';
header('Content-Type: application/json');

$roomId = intval($_GET['room_id'] ?? 0);
if ($roomId <= 0) {
  echo json_encode([]);
  exit;
}

$today   = date('Y-m-d');
$allowed = [$today, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+2 days'))];
$dateStr = $_GET['date'] ?? $today;
if (!in_array($dateStr, $allowed, true)) $dateStr = $today;

$todayStart = strtotime($dateStr . ' 00:00:00');
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
