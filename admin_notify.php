<?php
// Notifikasi admin: reservasi & antrian online yang masuk setelah id terakhir yang sudah dilihat.
// Hanya membaca data, dipanggil berkala oleh admin.js.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require __DIR__ . '/db.php';
require_once __DIR__ . '/booking_lib.php';

if (!isAdminSession($conn)) {
  echo json_encode(["status" => "unauthorized"]);
  exit;
}
session_write_close();

$afterBooking = intval($_GET['after_booking'] ?? 0);
$afterQueue   = intval($_GET['after_queue'] ?? 0);

$max = $conn->query("SELECT
  (SELECT COALESCE(MAX(id), 0) FROM bookings WHERE source = 'online') AS b,
  (SELECT COALESCE(MAX(id), 0) FROM booking_queue WHERE source = 'online') AS q
")->fetch_assoc();

// Reservasi online langsung (bukan hasil antrian) yang belum dibatalkan
$stmt = $conn->prepare("
  SELECT b.id, b.customer_name, b.start_time, b.with_nintendo, r.title AS room
  FROM bookings b
  JOIN rooms r ON r.id = b.room_id
  LEFT JOIN booking_queue q ON q.booking_id = b.id
  WHERE b.source = 'online' AND b.id > ? AND q.id IS NULL AND b.payment_status <> 'cancelled'
  ORDER BY b.id DESC LIMIT 10
");
$stmt->bind_param("i", $afterBooking);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
  SELECT id, customer_name, room_class, duration, with_nintendo
  FROM booking_queue
  WHERE source = 'online' AND id > ? AND status <> 'cancelled'
  ORDER BY id DESC LIMIT 10
");
$stmt->bind_param("i", $afterQueue);
$stmt->execute();
$queue = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$today = date('Y-m-d');
$whenText = function ($ts) use ($bulan, $today) {
  $d = date('Y-m-d', $ts);
  $day = $d === $today ? 'Hari ini'
    : ($d === date('Y-m-d', strtotime('+1 day')) ? 'Besok' : date('j', $ts) . ' ' . $bulan[intval(date('n', $ts)) - 1]);
  return $day . ' ' . date('H:i', $ts);
};

echo json_encode([
  "status"         => "ok",
  "max_booking_id" => intval($max['b']),
  "max_queue_id"   => intval($max['q']),
  "bookings" => array_map(fn($b) => [
    "id"   => intval($b['id']),
    "name" => $b['customer_name'],
    "room" => $b['room'] . ($b['with_nintendo'] ? ' + Nintendo' : ''),
    "when" => $whenText(intval($b['start_time'])),
    "date" => date('Y-m-d', intval($b['start_time'])),
  ], $bookings),
  "queue" => array_map(fn($q) => [
    "id"    => intval($q['id']),
    "name"  => $q['customer_name'],
    "class" => $q['room_class'] . ($q['with_nintendo'] ? ' + Nintendo' : ''),
    "hours" => intval($q['duration']),
  ], $queue),
]);
