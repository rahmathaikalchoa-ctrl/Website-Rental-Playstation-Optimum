<?php
// Integrasi Midtrans Snap tanpa SDK: request HTTP langsung dengan cURL.
// Dokumentasi: https://docs.midtrans.com/reference/snap-api-overview

if (is_file(__DIR__ . '/midtrans_config.php')) {
  require_once __DIR__ . '/midtrans_config.php';
}

// Batas waktu menyelesaikan pembayaran online
const MIDTRANS_PAY_WINDOW_MIN = 30;
// Sisa waktu minimal sebelum batas check-in agar masih boleh bayar online
const MIDTRANS_MIN_WINDOW_MIN = 5;

function midtransConfigured() {
  return defined('MIDTRANS_SERVER_KEY') && MIDTRANS_SERVER_KEY !== ''
      && defined('MIDTRANS_CLIENT_KEY') && MIDTRANS_CLIENT_KEY !== '';
}

function midtransSnapUrl() {
  return MIDTRANS_IS_PRODUCTION ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
}

function midtransApiUrl() {
  return MIDTRANS_IS_PRODUCTION ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
}

// Return [httpCode, decodedBody|null]
function midtransRequest($method, $url, $body = null) {
  $ch = curl_init($url);
  $headers = [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Basic ' . base64_encode(MIDTRANS_SERVER_KEY . ':'),
  ];
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 20,
  ]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
  $raw  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($raw === false) {
    error_log("Midtrans request failed: $err");
    return [0, null];
  }
  return [$code, json_decode($raw, true)];
}

// Buat transaksi Snap untuk satu booking. Return ['ok'=>true,'token'=>..] atau ['ok'=>false,'message'=>..]
function midtransCreateSnap(array $booking, $orderId, $expiryMinutes) {
  $itemName = mb_substr("Sewa {$booking['room']} {$booking['duration']} jam", 0, 50);
  $customer = ['first_name' => mb_substr($booking['customer_name'], 0, 50)];
  if (!empty($booking['email'])) $customer['email'] = $booking['email'];
  if (!empty($booking['phone'])) $customer['phone'] = $booking['phone'];

  [$code, $res] = midtransRequest('POST', midtransSnapUrl() . '/snap/v1/transactions', [
    'transaction_details' => [
      'order_id'     => $orderId,
      'gross_amount' => intval($booking['total_price']),
    ],
    'item_details' => [[
      'id'       => $booking['order_code'],
      'price'    => intval($booking['total_price']),
      'quantity' => 1,
      'name'     => $itemName,
    ]],
    'customer_details' => $customer,
    'expiry' => ['unit' => 'minute', 'duration' => $expiryMinutes],
  ]);

  if ($code === 201 && !empty($res['token'])) return ['ok' => true, 'token' => $res['token']];
  error_log("Midtrans Snap error ($code): " . json_encode($res));
  return ['ok' => false, 'message' => 'Gagal membuat transaksi pembayaran. Coba lagi atau bayar di kasir.'];
}

// Ambil status transaksi langsung dari Midtrans (sumber kebenaran, bukan data dari browser)
function midtransFetchStatus($orderId) {
  [$code, $res] = midtransRequest('GET', midtransApiUrl() . '/v2/' . rawurlencode($orderId) . '/status');
  return ($code === 200 && is_array($res)) ? $res : null;
}

// Signature notifikasi: sha512(order_id + status_code + gross_amount + server_key)
function midtransValidSignature(array $n) {
  if (!isset($n['order_id'], $n['status_code'], $n['gross_amount'], $n['signature_key'])) return false;
  $expected = hash('sha512', $n['order_id'] . $n['status_code'] . $n['gross_amount'] . MIDTRANS_SERVER_KEY);
  return hash_equals($expected, $n['signature_key']);
}

// Terapkan status Midtrans ke booking. Return payment_status booking setelah diperbarui.
function midtransApplyStatus($conn, array $st) {
  $orderId = $st['order_id'] ?? '';
  $stmt = $conn->prepare("SELECT id, total_price, payment_status FROM bookings WHERE midtrans_order_id = ? LIMIT 1");
  $stmt->bind_param("s", $orderId);
  $stmt->execute();
  $b = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$b) return null;

  $tx    = $st['transaction_status'] ?? '';
  $fraud = $st['fraud_status'] ?? 'accept';
  // Nominal harus sama persis dengan tagihan booking
  $amountOk = intval(round(floatval($st['gross_amount'] ?? 0))) === intval($b['total_price']);
  $id = intval($b['id']);

  if ((($tx === 'capture' && $fraud === 'accept') || $tx === 'settlement') && $amountOk) {
    if ($b['payment_status'] === 'cancelled') {
      // Dibayar setelah booking batal: perlu refund manual oleh admin
      error_log("Midtrans: pembayaran masuk untuk booking #$id yang sudah dibatalkan ($orderId)");
      return 'cancelled';
    }
    $now = time();
    $stmt = $conn->prepare("
      UPDATE bookings SET payment_status = 'paid', paid_at = ?, payment_method = 'midtrans'
      WHERE id = ? AND payment_status <> 'paid'
    ");
    $stmt->bind_param("ii", $now, $id);
    $stmt->execute();
    $stmt->close();
    return 'paid';
  }

  if (in_array($tx, ['deny', 'cancel', 'expire', 'failure'], true)) {
    // Gagal/kedaluwarsa: kembali "belum bayar", masih bisa bayar di kasir atau coba lagi
    $stmt = $conn->prepare("
      UPDATE bookings SET payment_status = 'unpaid', snap_token = NULL, payment_expires_at = NULL
      WHERE id = ? AND payment_status = 'pending' AND midtrans_order_id = ?
    ");
    $stmt->bind_param("is", $id, $orderId);
    $stmt->execute();
    $stmt->close();
    return $b['payment_status'] === 'pending' ? 'unpaid' : $b['payment_status'];
  }

  return $b['payment_status'];
}
