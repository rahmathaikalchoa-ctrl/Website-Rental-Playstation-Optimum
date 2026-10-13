<?php
// konsumen pindah jadwal booking sendiri sebelum mulai (logika di rescheduleBooking)
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/queue_lib.php';

if (!isset($_SESSION['user_id'])) {
  // sesi habis: script.js baca header ini terus buka modal login
  header('X-Auth-Required: 1');
  echo json_encode(["status" => "error", "message" => "Silakan login terlebih dahulu"]);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}
$userId = intval($_SESSION['user_id']);
if (!checkRateLimit('reschedule', 10, 600, (string) $userId)) {
  echo json_encode(["status" => "error", "message" => "Terlalu sering mengubah jadwal. Coba lagi beberapa menit lagi."]);
  exit;
}

$slot = scheduledStart(trim($_POST['date'] ?? ''), trim($_POST['time'] ?? ''));
if (!$slot['ok']) {
  echo json_encode(["status" => "error", "message" => $slot['message']]);
  exit;
}

$res = rescheduleBooking($conn, intval($_POST['booking_id'] ?? 0), $userId,
  intval($_POST['room_id'] ?? 0), $slot['start'], intval($_POST['duration'] ?? 0));
if ($res['ok']) processQueue($conn); // slot lama kosong, bisa langsung dipakai antrian

echo json_encode($res['ok']
  ? ["status" => "ok"] + $res['booking']
  : ["status" => "error", "message" => $res['message']]);
