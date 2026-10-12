<?php
// Ulasan ruangan.
// GET  ?room_id=ID : rata-rata rating + ulasan terbaru.
// POST booking_id, rating (1-5), comment : ulasan dari pemesan setelah sesinya selesai.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  session_write_close();
  $roomId = intval($_GET['room_id'] ?? 0);
  $stmt = $conn->prepare("SELECT ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS n FROM room_reviews WHERE room_id = ?");
  $stmt->bind_param("i", $roomId);
  $stmt->execute();
  $sum = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $stmt = $conn->prepare("
    SELECT u.username, rv.rating, rv.comment, rv.created_at
    FROM room_reviews rv JOIN users u ON u.id = rv.user_id
    WHERE rv.room_id = ?
    ORDER BY rv.created_at DESC LIMIT 10
  ");
  $stmt->bind_param("i", $roomId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  echo json_encode([
    "avg_rating" => $sum['avg_rating'] !== null ? floatval($sum['avg_rating']) : null,
    "count"      => intval($sum['n']),
    "reviews"    => array_map(fn($r) => [
      "username" => $r['username'],
      "rating"   => intval($r['rating']),
      "comment"  => $r['comment'],
      "date"     => date('d/m/Y', strtotime($r['created_at'])),
    ], $rows),
  ]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}
if (!isset($_SESSION['user_id'])) {
  // Sesi login habis: script.js membaca header ini lalu membuka modal login
  header('X-Auth-Required: 1');
  echo json_encode(["status" => "error", "message" => "Silakan login terlebih dahulu"]);
  exit;
}

$userId    = intval($_SESSION['user_id']);
$bookingId = intval($_POST['booking_id'] ?? 0);
$rating    = intval($_POST['rating'] ?? 0);
$comment   = trim($_POST['comment'] ?? '');

if ($rating < 1 || $rating > 5) {
  echo json_encode(["status" => "error", "message" => "Pilih rating 1 sampai 5 bintang"]);
  exit;
}
if (mb_strlen($comment) > 300) {
  echo json_encode(["status" => "error", "message" => "Komentar maksimal 300 karakter"]);
  exit;
}
if (!checkRateLimit('review', 10, 600, (string) $userId)) {
  echo json_encode(["status" => "error", "message" => "Terlalu banyak ulasan dalam waktu singkat. Coba lagi nanti."]);
  exit;
}

// Hanya booking milik sendiri yang sudah selesai dimainkan (sudah check-in, tidak batal)
$stmt = $conn->prepare("
  SELECT room_id FROM bookings
  WHERE id = ? AND user_id = ? AND payment_status <> 'cancelled'
    AND expires_at IS NULL AND end_time <= UNIX_TIMESTAMP()
");
$stmt->bind_param("ii", $bookingId, $userId);
$stmt->execute();
$b = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$b) {
  echo json_encode(["status" => "error", "message" => "Ulasan hanya bisa diberikan setelah sesi kamu selesai"]);
  exit;
}

try {
  $roomId = intval($b['room_id']);
  $stmt = $conn->prepare("INSERT INTO room_reviews (booking_id, room_id, user_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
  $stmt->bind_param("iiiis", $bookingId, $roomId, $userId, $rating, $comment);
  $stmt->execute();
  $stmt->close();
} catch (\mysqli_sql_exception $e) {
  // UNIQUE booking_id: booking ini sudah pernah diulas
  echo json_encode(["status" => "error", "message" => "Booking ini sudah kamu ulas"]);
  exit;
}
echo json_encode(["status" => "ok"]);
