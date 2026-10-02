<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header("Content-Type: application/json");
require __DIR__ . "/db.php";
require_once __DIR__ . "/booking_lib.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}

if (!isset($_SESSION['user_id'])) {
  echo json_encode(["status" => "error", "message" => "Silakan login terlebih dahulu"]);
  exit;
}

if (!checkRateLimit('booking', 20, 300)) {
  echo json_encode(["status" => "error", "message" => "Terlalu banyak permintaan booking. Coba lagi dalam beberapa menit."]);
  exit;
}

$customer = trim($_POST["name"] ?? "");
$email    = trim($_POST["email"] ?? "");
$phone    = trim($_POST["phone"] ?? "");
$roomId   = intval($_POST["room_id"] ?? 0);
$duration = intval($_POST["duration"] ?? 0);

if ($customer === "" || $roomId <= 0) {
  echo json_encode(["status" => "error", "message" => "Isi nama dan pilih ruangan terlebih dahulu"]);
  exit;
}
if (mb_strlen($customer) > 100) {
  echo json_encode(["status" => "error", "message" => "Nama maksimal 100 karakter"]);
  exit;
}

// Satu akun maksimal punya 2 reservasi mendatang yang belum dibayar,
// supaya slot tidak diborong satu orang
$userId = intval($_SESSION['user_id']);
$stmt = $conn->prepare("
  SELECT COUNT(*) AS n FROM bookings
  WHERE user_id = ? AND payment_status = 'unpaid' AND end_time > UNIX_TIMESTAMP()
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$activeUnpaid = intval($stmt->get_result()->fetch_assoc()['n']);
$stmt->close();
if ($activeUnpaid >= MAX_ACTIVE_UNPAID) {
  echo json_encode(["status" => "error", "message" =>
    "Kamu sudah punya " . MAX_ACTIVE_UNPAID . " reservasi yang belum dibayar. Bayar di kasir atau batalkan salah satunya dulu."]);
  exit;
}
if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  echo json_encode(["status" => "error", "message" => "Format email tidak valid"]);
  exit;
}
if ($phone !== "" && !preg_match('/^[0-9]{9,14}$/', $phone)) {
  echo json_encode(["status" => "error", "message" => "Nomor HP tidak valid (hanya angka, 9-14 digit)"]);
  exit;
}

$slot = scheduledStart(trim($_POST["date"] ?? date('Y-m-d')), trim($_POST["time"] ?? ""));
if (!$slot['ok']) {
  echo json_encode(["status" => "error", "message" => $slot['message']]);
  exit;
}

$res = createBooking($conn, [
  'room_id'        => $roomId,
  'start'          => $slot['start'],
  'duration'       => $duration,
  'customer_name'  => $customer,
  'email'          => $email,
  'phone'          => $phone,
  'user_id'        => $userId,
  'source'         => 'online',
  'payment_status' => 'unpaid',
  // Wajib lapor ke kasir paling lambat 15 menit setelah jam mulai
  'expires_at'     => $slot['start'] + BOOKING_CHECKIN_SEC,
]);

echo json_encode($res['ok']
  ? ["status" => "success"] + $res['booking']
  : ["status" => "error", "message" => $res['message']]);
