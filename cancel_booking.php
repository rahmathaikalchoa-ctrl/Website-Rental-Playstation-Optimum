<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/queue_lib.php';
require_once __DIR__ . '/midtrans_lib.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(["status" => "error", "message" => "Silakan login terlebih dahulu"]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}

$userId    = intval($_SESSION['user_id']);
$bookingId = intval($_POST['booking_id'] ?? 0);

if ($bookingId <= 0) {
  echo json_encode(["status" => "error", "message" => "ID booking tidak valid"]);
  exit;
}

// Boleh batal jika belum mulai, atau sudah mulai tapi belum lapor ke kasir (belum check-in)
$stmt = $conn->prepare("
  SELECT payment_status, midtrans_order_id FROM bookings
  WHERE id = ? AND user_id = ? AND payment_status <> 'cancelled'
    AND (start_time > UNIX_TIMESTAMP() OR expires_at IS NOT NULL)
  LIMIT 1
");
$stmt->bind_param("ii", $bookingId, $userId);
$stmt->execute();
$found = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$found) {
  echo json_encode(["status" => "error", "message" => "Booking tidak ditemukan atau sesi sudah berjalan"]);
  exit;
}

// Sedang menunggu pembayaran online: pastikan belum terbayar, lalu batalkan transaksi
// Midtrans-nya supaya user tidak bisa membayar booking yang sudah batal.
if ($found['payment_status'] === 'pending' && $found['midtrans_order_id'] && midtransConfigured()) {
  $st = midtransFetchStatus($found['midtrans_order_id']);
  if ($st && !empty($st['transaction_status'])) {
    $found['payment_status'] = midtransApplyStatus($conn, $st) ?? $found['payment_status'];
  }
  if ($found['payment_status'] === 'pending') {
    midtransRequest('POST', midtransApiUrl() . '/v2/' . rawurlencode($found['midtrans_order_id']) . '/cancel');
  }
}

if ($found['payment_status'] === 'paid') {
  echo json_encode(["status" => "error", "message" => "Booking sudah dibayar. Hubungi kasir untuk pembatalan."]);
  exit;
}

// Soft cancel: data tetap tersimpan di riwayat. expires_at dikosongkan supaya
// tidak terbaca sebagai "batal karena tidak hadir".
// Syarat diulang di UPDATE supaya tidak lolos bila kasir menandai lunas/hadir
// di antara SELECT di atas dan UPDATE ini.
$stmt = $conn->prepare("
  UPDATE bookings SET payment_status = 'cancelled', expires_at = NULL
  WHERE id = ? AND user_id = ? AND payment_status IN ('unpaid', 'pending')
    AND (start_time > UNIX_TIMESTAMP() OR expires_at IS NOT NULL)
");
$stmt->bind_param("ii", $bookingId, $userId);
$stmt->execute();
$ok = $stmt->affected_rows > 0;
$stmt->close();

if ($ok) {
  // Booking hasil antrian: tandai giliran juga batal (bukan tampil "dibatalkan admin")
  $stmt = $conn->prepare("UPDATE booking_queue SET status = 'cancelled' WHERE booking_id = ?");
  $stmt->bind_param("i", $bookingId);
  $stmt->execute();
  $stmt->close();
  processQueue($conn);
}

echo json_encode($ok
  ? ["status" => "ok"]
  : ["status" => "error", "message" => "Gagal membatalkan booking"]
);
