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
  // Sesi login habis: script.js membaca header ini lalu membuka modal login
  header('X-Auth-Required: 1');
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
    r.weekend_extra,
    r.nintendo_extra,
    b.duration,
    b.with_nintendo,
    b.is_student,
    b.start_time,
    b.end_time,
    b.total_price,
    b.discount,
    b.order_code,
    EXISTS (SELECT 1 FROM room_reviews rv WHERE rv.booking_id = b.id) AS reviewed,
    b.payment_status,
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
  // Biaya tambah 1-3 jam dihitung server (paket, weekend, Nintendo, pelajar ikut terhitung)
  $extendCosts = [];
  for ($h = 1; $h <= BOOKING_EXTEND_MAX; $h++) {
    $extendCosts[$h] = bookingPrice($row, $row['duration'] + $h, (int) $row['start_time'], (bool) $row['with_nintendo'], (bool) $row['is_student'])
                     - bookingPrice($row, $row['duration'], (int) $row['start_time'], (bool) $row['with_nintendo'], (bool) $row['is_student']);
  }
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
    'expires_at'     => $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
    'source'         => $row['source'],
    'with_nintendo'  => (bool) $row['with_nintendo'],
    'is_student'     => (bool) $row['is_student'],
    'extend_costs'   => $extendCosts,
    'discount'       => intval($row['discount']),
    'reviewed'       => (bool) $row['reviewed'],
  ];
}

$stmt->close();
echo json_encode($data);
