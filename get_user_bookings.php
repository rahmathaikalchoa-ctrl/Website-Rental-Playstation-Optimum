<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/booking_lib.php';
// batalin yg lewat batas check-in dulu biar data bener
releaseNoShows($conn);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) {
  // sesi habis: script.js baca header ini terus buka modal login
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
    r.tier,
    r.console_model,
    r.max_players,
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
    b.amount_paid,
    b.order_code,
    EXISTS (SELECT 1 FROM room_reviews rv WHERE rv.booking_id = b.id) AS reviewed,
    b.payment_status,
    b.expires_at,
    b.source,
    b.lock_room,
    b.relocated_from,
    ro.title AS relocated_from_title
  FROM bookings b
  JOIN rooms r ON b.room_id = r.id
  LEFT JOIN rooms ro ON ro.id = b.relocated_from
  WHERE b.user_id = ?
  ORDER BY b.start_time DESC
  LIMIT 50
";

// total jam main dari semua booking (riwayat cuma 50 terbaru), no-show & batal gak dihitung
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(duration), 0) FROM bookings
  WHERE user_id = ? AND payment_status <> 'cancelled' AND expires_at IS NULL AND end_time <= UNIX_TIMESTAMP()
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
header('X-Played-Hours: ' . intval($stmt->get_result()->fetch_row()[0]));
$stmt->close();

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();

$res = $stmt->get_result();
$data = [];

// game yg ada di ruangan asal tapi gak ada di ruangan baru
$missingGames = function ($fromRoom, $toRoom) use ($conn) {
  $stmt = $conn->prepare("
    SELECT g.title FROM room_games rg JOIN games g ON g.id = rg.game_id
    WHERE rg.room_id = ? AND rg.game_id NOT IN (SELECT game_id FROM room_games WHERE room_id = ?)
    ORDER BY g.title
  ");
  $stmt->bind_param("ii", $fromRoom, $toRoom);
  $stmt->execute();
  $titles = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'title');
  $stmt->close();
  return $titles;
};

$now = time();
while ($row = $res->fetch_assoc()) {
  // sesi jalan & udah check-in: opsi tambah jam dihitung server
  $extend = null;
  if ($row['expires_at'] === null && $row['payment_status'] !== 'cancelled'
      && $now >= (int) $row['start_time'] && $now < (int) $row['end_time']) {
    $extend = extensionOptions($conn, $row, ['id' => $row['room_id']] + $row);
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
    'extend'         => $extend,
    'lock_room'      => (bool) $row['lock_room'],
    'relocated_from' => $row['relocated_from_title'],
    'missing_games'  => $row['relocated_from'] !== null && (int) $row['end_time'] > $now
                        ? $missingGames((int) $row['relocated_from'], (int) $row['room_id']) : [],
    'discount'       => intval($row['discount']),
    'amount_paid'    => intval($row['amount_paid']),
    'reviewed'       => (bool) $row['reviewed'],
  ];
}

$stmt->close();
echo json_encode($data);
