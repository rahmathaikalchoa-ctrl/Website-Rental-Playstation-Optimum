<?php
// konsumen tukar / batal tukar 10 poin buat booking yg belum bayar (aturan sama kayak kasir)
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/booking_lib.php';

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

$res = toggleRedeemPoints($conn, intval($_POST['booking_id'] ?? 0), intval($_SESSION['user_id']));
echo json_encode($res['ok']
  ? ["status" => "ok", "redeemed" => $res['redeemed']]
  : ["status" => "error", "message" => $res['message']]);
