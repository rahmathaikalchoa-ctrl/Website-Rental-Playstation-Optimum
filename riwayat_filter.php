<?php
// filter Riwayat dari query string, dipakai halaman Riwayat & export CSV biar hasilnya sama
// return values, where, types & params, query

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

  // tanggal ikut jadwal main, bukan waktu booking dibuat
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
