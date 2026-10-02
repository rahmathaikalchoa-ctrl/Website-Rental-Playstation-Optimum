<?php
// Filter Riwayat Booking dari query string, dipakai halaman Riwayat (KITSUNE-ADMIN.php)
// dan export CSV (admin_export.php) supaya hasilnya selalu sama.
// Return: values (untuk mengisi form), where (SQL), types & params (bind_param), query (untuk link).

function riwayatFilter(array $get) {
  $isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

  $v = [
    'from'   => $isDate($get['from'] ?? '') ? $get['from'] : '',
    'to'     => $isDate($get['to'] ?? '') ? $get['to'] : '',
    'status' => in_array($get['status'] ?? '', ['unpaid', 'paid', 'cancelled'], true) ? $get['status'] : '',
    'q'      => mb_substr(trim(is_string($get['q'] ?? null) ? $get['q'] : ''), 0, 60),
  ];

  $where = [];
  $types = '';
  $params = [];

  // Tanggal mengacu ke jadwal main (start_time), bukan waktu booking dibuat
  if ($v['from'] !== '') {
    $where[] = 'b.start_time >= ?';
    $types .= 'i';
    $params[] = strtotime($v['from'] . ' 00:00:00');
  }
  if ($v['to'] !== '') {
    $where[] = 'b.start_time < ?';
    $types .= 'i';
    $params[] = strtotime($v['to'] . ' 00:00:00') + 86400;
  }
  if ($v['status'] !== '') {
    $where[] = 'b.payment_status = ?';
    $types .= 's';
    $params[] = $v['status'];
  }
  if ($v['q'] !== '') {
    $where[] = '(b.customer_name LIKE ? OR b.order_code LIKE ?)';
    $types .= 'ss';
    $like = '%' . addcslashes($v['q'], '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
  }

  return [
    'values' => $v,
    'where'  => $where ? 'WHERE ' . implode(' AND ', $where) : '',
    'types'  => $types,
    'params' => $params,
    'query'  => http_build_query(array_filter($v, fn($x) => $x !== '')),
  ];
}
