<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/booking_lib.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(["status" => "error", "message" => "Silakan login terlebih dahulu"]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}

$res = extendBookingCore($conn, $_POST['booking_id'] ?? 0, $_POST['extra_hours'] ?? 0, intval($_SESSION['user_id']));

echo json_encode($res['ok']
  ? ["status" => "ok", "new_end" => $res['new_end'], "extra_cost" => $res['extra_cost']]
  : ["status" => "error", "message" => $res['message']]);
