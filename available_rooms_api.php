<?php
// Cari ruangan yang masih kosong untuk tanggal, jam, dan durasi tertentu,
// lengkap dengan total harga (paket, weekend, Nintendo sudah dihitung).
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/queue_lib.php';
releaseNoShows($conn);

$slot = scheduledStart(trim($_GET['date'] ?? ''), trim($_GET['time'] ?? ''));
if (!$slot['ok']) {
  echo json_encode(["status" => "error", "message" => $slot['message']]);
  exit;
}
$start    = $slot['start'];
$duration = intval($_GET['duration'] ?? 0);
$players  = max(1, min(8, intval($_GET['players'] ?? 1)));
$nintendo = !empty($_GET['nintendo']);
$end      = $start + $duration * 3600;

if ($duration < 1 || $duration > BOOKING_MAX_DURATION) {
  echo json_encode(["status" => "error", "message" => "Durasi harus 1-" . BOOKING_MAX_DURATION . " jam"]);
  exit;
}
if ($end > closeTs($start)) {
  echo json_encode(["status" => "error", "message" => "Durasi melewati jam tutup (" . secToHHMM(BOOKING_CLOSE_SEC) . ")"]);
  exit;
}

// Mau main Nintendo: ruangan Nintendo Switch, atau ruangan yang bisa tambah Nintendo
$stmt = $conn->prepare("
  SELECT r.id, r.title, r.category, r.tier, r.console_type, r.console_model, r.price, r.weekend_extra,
         r.nintendo_extra, r.max_players, r.has_netflix
  FROM rooms r
  WHERE r.status = 'available' AND r.max_players >= ?
    AND (? = 0 OR r.console_type = 'SWITCH' OR r.nintendo_extra IS NOT NULL)
    AND NOT EXISTS (
      SELECT 1 FROM bookings b
      WHERE b.room_id = r.id AND b.payment_status <> 'cancelled' AND b.start_time < ? AND b.end_time > ?
    )
");
$n = $nintendo ? 1 : 0;
$stmt->bind_param("iiii", $players, $n, $end, $start);
$stmt->execute();
$rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$result = [];
foreach ($rooms as $r) {
  $addon = $nintendo && $r['nintendo_extra'] !== null;
  $result[] = [
    "id"            => intval($r['id']),
    "title"         => $r['title'],
    "category"      => $r['category'],
    "tier"          => $r['tier'],
    "console_model" => $r['console_model'],
    "max_players"   => intval($r['max_players']),
    "has_netflix"   => (bool) $r['has_netflix'],
    "with_nintendo" => $addon,
    "total"         => bookingPrice($r, $duration, $start, $addon),
  ];
}
usort($result, fn($a, $b) => $a['total'] <=> $b['total'] ?: $a['id'] <=> $b['id']);

echo json_encode(["status" => "ok", "rooms" => $result]);
