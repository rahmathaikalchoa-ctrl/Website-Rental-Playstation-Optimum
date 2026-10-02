<?php
// Export Riwayat Booking ke CSV (bisa dibuka di Excel), mengikuti filter yang sedang aktif.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/riwayat_filter.php';

if (!isAdminSession($conn)) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Sesi admin habis, silakan login ulang.";
  exit;
}

$f = riwayatFilter($_GET);
$stmt = $conn->prepare("
  SELECT b.order_code, b.customer_name, b.phone, b.source, r.title AS room_title,
         b.start_time, b.end_time, b.duration, b.total_price, b.amount_paid, b.payment_status, b.created_at
  FROM bookings b
  LEFT JOIN rooms r ON r.id = b.room_id
  {$f['where']}
  ORDER BY b.start_time DESC
");
if ($f['types'] !== '') $stmt->bind_param($f['types'], ...$f['params']);
$stmt->execute();
$rows = $stmt->get_result();

$statusLabel = ['unpaid' => 'Belum bayar', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'];
$filename = 'riwayat-booking-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// BOM UTF-8 supaya Excel membaca karakter dengan benar
fwrite($out, "\xEF\xBB\xBF");
// Pemisah titik koma: format default Excel dengan pengaturan regional Indonesia
$sep = ';';
fputcsv($out, ['Kode', 'Nama', 'No. HP', 'Sumber', 'Ruangan', 'Tanggal', 'Jam Mulai', 'Jam Selesai',
               'Durasi (jam)', 'Total (Rp)', 'Dibayar (Rp)', 'Status', 'Dibuat'], $sep);
while ($b = $rows->fetch_assoc()) {
  fputcsv($out, [
    $b['order_code'],
    $b['customer_name'],
    $b['phone'] ?? '',
    $b['source'] === 'walkin' ? 'Offline' : 'Online',
    $b['room_title'] ?? '-',
    date('Y-m-d', intval($b['start_time'])),
    date('H:i', intval($b['start_time'])),
    date('H:i', intval($b['end_time'])),
    intval($b['duration']),
    intval($b['total_price']),
    intval($b['amount_paid']),
    $statusLabel[$b['payment_status']] ?? $b['payment_status'],
    $b['created_at'],
  ], $sep);
}
fclose($out);
$stmt->close();
