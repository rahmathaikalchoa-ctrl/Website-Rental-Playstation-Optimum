<?php
// Export Riwayat Booking ke CSV (bisa dibuka di Excel), mengikuti filter yang sedang aktif.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/riwayat_filter.php';
require_once __DIR__ . '/booking_lib.php';

if (!isAdminSession($conn)) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Sesi admin habis, silakan login ulang.";
  exit;
}

// Status no-show diperbarui dulu supaya sama dengan halaman Riwayat
releaseNoShows($conn);
$f = riwayatFilter($_GET);
$stmt = $conn->prepare("
  SELECT b.order_code, b.customer_name, b.phone, b.source, r.title AS room_title,
         b.start_time, b.end_time, b.duration, b.with_nintendo, b.is_student,
         b.total_price, b.amount_paid, b.payment_status, b.created_at
  FROM bookings b
  LEFT JOIN rooms r ON r.id = b.room_id
  {$f['where']}
  ORDER BY b.created_at DESC
");
if ($f['types'] !== '') $stmt->bind_param($f['types'], ...$f['params']);
$stmt->execute();
$rows = $stmt->get_result();

// Cegah formula injection: sel teks yang diawali = + - @ (atau tab/CR) akan dijalankan
// sebagai rumus oleh Excel. Diberi awalan petik supaya terbaca sebagai teks biasa.
$csvText = fn($v) => ($v !== null && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : ($v ?? '');

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
               'Durasi (jam)', 'Keterangan', 'Total (Rp)', 'Dibayar (Rp)', 'Status', 'Dibuat'], $sep);
while ($b = $rows->fetch_assoc()) {
  fputcsv($out, [
    $csvText($b['order_code']),
    $csvText($b['customer_name']),
    $csvText($b['phone']),
    $b['source'] === 'walkin' ? 'Offline' : 'Online',
    $b['room_title'] !== null ? $csvText($b['room_title']) : '-',
    date('Y-m-d', intval($b['start_time'])),
    date('H:i', intval($b['start_time'])),
    date('H:i', intval($b['end_time'])),
    intval($b['duration']),
    implode(', ', array_filter([$b['with_nintendo'] ? 'Tambah Nintendo' : '', $b['is_student'] ? 'Harga pelajar' : ''])),
    intval($b['total_price']),
    intval($b['amount_paid']),
    $statusLabel[$b['payment_status']] ?? $b['payment_status'],
    $b['created_at'],
  ], $sep);
}
fclose($out);
$stmt->close();
