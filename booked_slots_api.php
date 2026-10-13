<?php
header('Content-Type: application/json');
require 'db.php';
require_once __DIR__ . '/booking_lib.php';
// batalin yg lewat batas check-in dulu biar data bener
releaseNoShows($conn);

$roomId = intval($_GET['room_id'] ?? 0);
if ($roomId <= 0) {
  echo json_encode([]);
  exit;
}

$today   = date('Y-m-d');
$allowed = [$today, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+2 days'))];
$dateStr = $_GET['date'] ?? $today;
// tanggal di luar hari ini s/d lusa jangan diganti diam-diam jadi hari ini (slot salah tanggal)
if (!in_array($dateStr, $allowed, true)) {
  echo json_encode([]);
  exit;
}

// booking yg lagi diubah jadwalnya gak bentrok sama dirinya sendiri
$exclude = intval($_GET['exclude'] ?? 0);

$todayStart = strtotime($dateStr . ' 00:00:00');
$todayEnd   = $todayStart + 86400;

$stmt = $conn->prepare("
  SELECT start_time, end_time
  FROM bookings
  WHERE room_id = ?
    AND payment_status <> 'cancelled'
    AND id <> ?
    AND end_time > ?
    AND start_time < ?
  ORDER BY start_time ASC
");
$stmt->bind_param("iiii", $roomId, $exclude, $todayStart, $todayEnd);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($rows);
