<?php
// rundown semua ruangan per tanggal (hari ini s/d lusa) buat halaman Ruangan
// publik, cuma jam terisi tanpa nama/kode/kontak
header('Content-Type: application/json');
require 'db.php';
require_once __DIR__ . '/booking_lib.php';
// batalin yg lewat batas check-in dulu biar slotnya kosong
releaseNoShows($conn);

$today   = date('Y-m-d');
$allowed = [$today, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+2 days'))];
$date    = $_GET['date'] ?? $today;
if (!in_array($date, $allowed, true)) {
  echo json_encode(["status" => "error", "message" => "Tanggal tidak valid"]);
  exit;
}

$dayStart = strtotime("$date 00:00:00");
$now      = time();

$rooms = $conn->query("SELECT id, title, tier, console_model, status FROM rooms ORDER BY id")->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("
  SELECT room_id, start_time, end_time, expires_at FROM bookings
  WHERE payment_status <> 'cancelled' AND end_time > ? AND start_time < ?
  ORDER BY room_id, start_time
");
$dayEnd = $dayStart + 86400;
$stmt->bind_param("ii", $dayStart, $dayEnd);
$stmt->execute();
$slots = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $b) {
  $slots[] = [
    'room_id' => intval($b['room_id']),
    'start'   => intval($b['start_time']),
    'end'     => intval($b['end_time']),
    // udah lapor kasir & jamnya lagi jalan = dipakai
    'playing' => $b['expires_at'] === null && intval($b['start_time']) <= $now && intval($b['end_time']) > $now,
  ];
}
$stmt->close();

echo json_encode([
  "status" => "ok",
  "date"   => $date,
  "now"    => $now,
  "open"   => $dayStart + BOOKING_OPEN_SEC,
  "close"  => $dayStart + BOOKING_CLOSE_SEC,
  "rooms"  => array_map(fn($r) => [
    'id' => intval($r['id']), 'title' => $r['title'], 'tier' => $r['tier'],
    'console_model' => $r['console_model'], 'maintenance' => $r['status'] !== 'available',
  ], $rooms),
  "slots"  => $slots,
]);
