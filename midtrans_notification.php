<?php
// Webhook "Payment Notification URL" Midtrans. Isi URL ini di dashboard Midtrans
// (Settings > Configuration) saat website sudah online, mis. https://domain/midtrans_notification.php.
// Di localhost Midtrans tidak bisa mengirim notifikasi, jadi status dicek lewat payment_api.php.
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/midtrans_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !midtransConfigured()) {
  http_response_code(400);
  echo json_encode(["status" => "error"]);
  exit;
}

$notif = json_decode(file_get_contents('php://input'), true);
if (!is_array($notif) || !midtransValidSignature($notif)) {
  http_response_code(403);
  echo json_encode(["status" => "error", "message" => "invalid signature"]);
  exit;
}

// Jangan percaya isi notifikasi mentah: ambil ulang status resmi dari API Midtrans
$st = midtransFetchStatus($notif['order_id']);
if ($st) midtransApplyStatus($conn, $st);

echo json_encode(["status" => "ok"]);
