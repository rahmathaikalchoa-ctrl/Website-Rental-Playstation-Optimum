<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header("Content-Type: application/json");
require __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  echo json_encode(["status" => "error", "message" => "Invalid request"]);
  exit;
}

$customer = trim($_POST["name"] ?? "");
$email    = trim($_POST["email"] ?? "");
$phone    = trim($_POST["phone"] ?? "");
$roomId   = intval($_POST["room_id"] ?? 0);
$time     = trim($_POST["time"] ?? "");
$duration = intval($_POST["duration"] ?? 0);

$today   = date("Y-m-d");
$allowed = [$today, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+2 days'))];
$date    = trim($_POST["date"] ?? $today);
if (!in_array($date, $allowed, true)) {
  echo json_encode(["status" => "error", "message" => "Tanggal booking tidak valid"]);
  exit;
}

$user_id = $_SESSION['user_id'] ?? null;

$maxDuration = 12; // sesuai rentang jam operasional (11:00-23:00)
if ($customer === "" || $roomId <= 0 || $time === "" || $duration <= 0 || $duration > $maxDuration) {
  echo json_encode(["status" => "error", "message" => "Data tidak lengkap atau durasi tidak valid (maks $maxDuration jam)"]);
  exit;
}

// Validasi jam operasional (11:00-23:00) di server, jangan cuma andalkan UI.
if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $tm)) {
  echo json_encode(["status" => "error", "message" => "Format jam tidak valid"]);
  exit;
}
$startHour = intval($tm[1]);
if ($startHour < 11 || $startHour > 23) {
  echo json_encode(["status" => "error", "message" => "Jam booking harus di antara 11:00-23:00"]);
  exit;
}

if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  echo json_encode(["status" => "error", "message" => "Format email tidak valid"]);
  exit;
}

if ($phone !== "" && !preg_match('/^[0-9]{9,14}$/', $phone)) {
  echo json_encode(["status" => "error", "message" => "Format nomor HP tidak valid"]);
  exit;
}

$startTimestamp = strtotime("$date $time");

if ($startTimestamp === false) {
  echo json_encode(["status" => "error", "message" => "Format waktu tidak valid"]);
  exit;
}

if ($date === $today && $startTimestamp < time()) {
  echo json_encode(["status" => "error", "message" => "Waktu mulai tidak boleh di masa lalu"]);
  exit;
}

$endTimestamp = $startTimestamp + ($duration * 3600);

// Cek terhadap timestamp penuh (bukan cuma bulatan jam) supaya jam mulai
// dengan menit (mis. 23:30) tidak lolos melewati batas tengah malam.
$dayStart   = strtotime(date('Y-m-d', $startTimestamp));
$closeLimit = $dayStart + 24 * 3600;
if ($endTimestamp > $closeLimit) {
  echo json_encode(["status" => "error", "message" => "Durasi tidak boleh melewati jam tutup (tengah malam)"]);
  exit;
}

// Transaksi + row lock pada ruangan supaya cek-bentrok dan insert atomik
// (mencegah dua booking lolos bersamaan untuk slot yang sama / race condition).
try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("SELECT id, price FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE");
  $stmt->bind_param("i", $roomId);
  $stmt->execute();
  $room = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$room) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Room tidak ditemukan"]);
    exit;
  }

  $totalPrice = intval($room['price']) * $duration;

  // Cek konflik: ada booking lain di ruangan yang sama yang waktunya tumpang tindih
  $stmt = $conn->prepare("
    SELECT id FROM bookings
    WHERE room_id = ?
      AND start_time < ?
      AND end_time > ?
    LIMIT 1
  ");
  $stmt->bind_param("iii", $roomId, $endTimestamp, $startTimestamp);
  $stmt->execute();
  $conflict = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($conflict) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Ruangan sudah dibooking di jam tersebut. Pilih jam lain."]);
    exit;
  }

  $stmt = $conn->prepare("
    INSERT INTO bookings
    (customer_name, email, phone, room_id, duration, start_time, end_time, user_id, total_price)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  $stmt->bind_param(
    "sssiiiiii",
    $customer,
    $email,
    $phone,
    $roomId,
    $duration,
    $startTimestamp,
    $endTimestamp,
    $user_id,
    $totalPrice
  );

  $stmt->execute();
  $bookingId = $stmt->insert_id;
  $stmt->close();

  $orderCode = "GZ-$bookingId";
  $stmt = $conn->prepare("UPDATE bookings SET order_code = ? WHERE id = ?");
  $stmt->bind_param("si", $orderCode, $bookingId);
  $stmt->execute();
  $stmt->close();

  $conn->commit();

  echo json_encode(["status" => "success", "order_code" => $orderCode, "total_price" => $totalPrice]);
} catch (\Throwable $e) {
  $conn->rollback();
  error_log("Booking failed: " . $e->getMessage());
  echo json_encode(["status" => "error", "message" => "Gagal membuat booking"]);
}
exit;
