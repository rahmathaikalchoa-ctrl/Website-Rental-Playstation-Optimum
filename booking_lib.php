<?php
// Logika inti pembuatan & perpanjangan booking, dipakai bersama oleh
// halaman user (apikr.php, extend_booking.php) dan admin (admin_api.php).

const BOOKING_OPEN_HOUR    = 11;
const BOOKING_MAX_DURATION = 12;
const BOOKING_EXTEND_MAX   = 3;
// Batas lapor ke kasir setelah jam mulai; lewat dari ini booking batal otomatis
const BOOKING_CHECKIN_SEC  = 15 * 60;
// Maksimal reservasi mendatang yang belum dibayar per akun user
const MAX_ACTIVE_UNPAID    = 2;

function bookingFail($message) {
  return ['ok' => false, 'message' => $message];
}

// Batalkan booking yang belum lapor ke kasir sampai batas check-in (no-show).
// Booking yang sudah lunas tidak pernah dianggap no-show.
function releaseNoShows($conn, $now = null) {
  $now = $now ?? time();
  $stmt = $conn->prepare("
    UPDATE bookings SET payment_status = 'cancelled'
    WHERE expires_at IS NOT NULL AND expires_at < ? AND payment_status = 'unpaid'
  ");
  $stmt->bind_param("i", $now);
  $stmt->execute();
  $n = $stmt->affected_rows;
  $stmt->close();
  return $n;
}

// Hitung timestamp mulai reservasi dari tanggal (hari ini s/d lusa) dan jam "HH:MM".
function scheduledStart($date, $time) {
  $today   = date('Y-m-d');
  $allowed = [$today, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+2 days'))];
  if (!in_array($date, $allowed, true)) return bookingFail("Tanggal booking tidak valid");

  if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $tm)) return bookingFail("Format jam tidak valid");
  $hour = intval($tm[1]);
  if ($hour < BOOKING_OPEN_HOUR || $hour > 23) return bookingFail("Jam booking harus di antara 11:00-23:00");

  $start = strtotime("$date $time");
  if ($start === false) return bookingFail("Format waktu tidak valid");
  if ($start < time()) return bookingFail("Jam mulai sudah lewat, pilih jam lain");

  return ['ok' => true, 'start' => $start];
}

// $o: room_id, start, duration, customer_name, email, phone, user_id,
//     source ('online'|'walkin'), payment_status ('unpaid'|'paid'), expires_at
function createBooking($conn, array $o) {
  $roomId   = intval($o['room_id']);
  $start    = intval($o['start']);
  $duration = intval($o['duration']);
  $end      = $start + $duration * 3600;

  if ($duration < 1 || $duration > BOOKING_MAX_DURATION) {
    return bookingFail("Durasi harus 1-" . BOOKING_MAX_DURATION . " jam");
  }
  // Timestamp penuh (bukan bulatan jam) supaya mulai 23:30 tidak lolos lewat tengah malam
  if ($end > strtotime(date('Y-m-d', $start)) + 24 * 3600) {
    return bookingFail("Durasi melewati jam tutup (tengah malam)");
  }

  $name    = $o['customer_name'];
  $email   = ($o['email'] ?? '') === '' ? null : $o['email'];
  $phone   = ($o['phone'] ?? '') === '' ? null : $o['phone'];
  $userId  = $o['user_id'] ?? null;
  $source  = $o['source'];
  $payStat = $o['payment_status'];
  $paidAt  = $payStat === 'paid' ? time() : null;
  $expires = $o['expires_at'] ?? null;

  // Slot milik konsumen yang tidak datang harus bebas sebelum cek bentrok
  releaseNoShows($conn);

  // Transaksi + lock baris ruangan: cek bentrok dan insert atomik (anti double booking)
  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT id, title, price, status FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$room) { $conn->rollback(); return bookingFail("Ruangan tidak ditemukan"); }
    if ($room['status'] !== 'available') { $conn->rollback(); return bookingFail("Ruangan sedang dalam perawatan"); }

    $stmt = $conn->prepare("
      SELECT start_time FROM bookings
      WHERE room_id = ? AND payment_status <> 'cancelled' AND start_time < ? AND end_time > ?
      ORDER BY start_time LIMIT 1
    ");
    $stmt->bind_param("iii", $roomId, $end, $start);
    $stmt->execute();
    $conflict = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($conflict) {
      $conn->rollback();
      return bookingFail("Ruangan sudah dibooking di jam tersebut. Pilih jam atau ruangan lain.");
    }

    $total = intval($room['price']) * $duration;
    $amountPaid = $payStat === 'paid' ? $total : 0;
    $stmt = $conn->prepare("
      INSERT INTO bookings
      (customer_name, email, phone, room_id, duration, start_time, end_time, user_id,
       total_price, amount_paid, payment_status, source, paid_at, expires_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssiiiiiiissii",
      $name, $email, $phone, $roomId, $duration, $start, $end, $userId,
      $total, $amountPaid, $payStat, $source, $paidAt, $expires
    );
    $stmt->execute();
    $bookingId = $stmt->insert_id;
    $stmt->close();

    $code = "GZ-$bookingId";
    $stmt = $conn->prepare("UPDATE bookings SET order_code = ? WHERE id = ?");
    $stmt->bind_param("si", $code, $bookingId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("createBooking failed: " . $e->getMessage());
    return bookingFail("Gagal membuat booking");
  }

  return ['ok' => true, 'booking' => [
    'id'          => $bookingId,
    'order_code'  => $code,
    'room'        => $room['title'],
    'start_time'  => $start,
    'end_time'    => $end,
    'duration'    => $duration,
    'total_price' => $total,
    'expires_at'  => $expires,
  ]];
}

// Perpanjang sesi yang sedang berjalan. $userId null = oleh admin (tanpa cek pemilik).
function extendBookingCore($conn, $bookingId, $extraHours, $userId = null) {
  $bookingId  = intval($bookingId);
  $extraHours = intval($extraHours);
  if ($bookingId <= 0 || $extraHours < 1 || $extraHours > BOOKING_EXTEND_MAX) {
    return bookingFail("Tambahan jam harus 1-" . BOOKING_EXTEND_MAX . " jam");
  }

  $stmt = $conn->prepare("SELECT room_id, user_id FROM bookings WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $bookingId);
  $stmt->execute();
  $pre = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$pre || ($userId !== null && intval($pre['user_id']) !== intval($userId))) {
    return bookingFail("Booking tidak ditemukan");
  }
  $roomId = intval($pre['room_id']);

  // Lock room lalu booking (urutan sama dengan createBooking) supaya dua
  // perpanjangan bersamaan tidak memakai end_time/duration yang basi.
  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT price, status FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$room) { $conn->rollback(); return bookingFail("Ruangan tidak ditemukan"); }
    if ($room['status'] !== 'available') { $conn->rollback(); return bookingFail("Ruangan sedang dalam perawatan"); }

    $stmt = $conn->prepare("
      SELECT start_time, end_time, duration, expires_at FROM bookings
      WHERE id = ? AND room_id = ? AND payment_status <> 'cancelled'
      LIMIT 1 FOR UPDATE
    ");
    $stmt->bind_param("ii", $bookingId, $roomId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$booking) { $conn->rollback(); return bookingFail("Booking tidak ditemukan"); }
    // User harus sudah lapor ke kasir (check-in) sebelum bisa memperpanjang
    if ($userId !== null && $booking['expires_at'] !== null) {
      $conn->rollback();
      return bookingFail("Lapor ke kasir dulu sebelum memperpanjang sesi");
    }

    $now = time();
    if ($now < $booking['start_time'] || $now >= $booking['end_time']) {
      $conn->rollback();
      return bookingFail("Hanya sesi yang sedang berjalan yang bisa diperpanjang");
    }
    if (intval($booking['duration']) + $extraHours > BOOKING_MAX_DURATION) {
      $conn->rollback();
      return bookingFail("Total durasi tidak boleh lebih dari " . BOOKING_MAX_DURATION . " jam");
    }

    $currentEnd = intval($booking['end_time']);
    $newEnd     = $currentEnd + $extraHours * 3600;
    if ($newEnd > strtotime(date('Y-m-d', intval($booking['start_time']))) + 24 * 3600) {
      $conn->rollback();
      return bookingFail("Perpanjangan melewati jam tutup (tengah malam)");
    }

    $stmt = $conn->prepare("
      SELECT 1 FROM bookings
      WHERE room_id = ? AND id != ? AND payment_status <> 'cancelled' AND start_time < ? AND end_time > ?
      LIMIT 1
    ");
    $stmt->bind_param("iiii", $roomId, $bookingId, $newEnd, $currentEnd);
    $stmt->execute();
    $conflict = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($conflict) {
      $conn->rollback();
      return bookingFail("Ruangan sudah dibooking orang lain di jam tambahan tersebut");
    }

    $extraCost = intval($room['price']) * $extraHours;
    // Ada tambahan biaya: status kembali "belum bayar" supaya kasir menagih kekurangannya
    // (total_price - amount_paid). Yang sudah dibayar tetap tercatat di amount_paid.
    $stmt = $conn->prepare("
      UPDATE bookings
      SET end_time = ?, duration = duration + ?, total_price = total_price + ?,
          payment_status = 'unpaid'
      WHERE id = ?
    ");
    $stmt->bind_param("iiii", $newEnd, $extraHours, $extraCost, $bookingId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("extendBookingCore failed: " . $e->getMessage());
    return bookingFail("Gagal memperpanjang sesi");
  }

  return ['ok' => true, 'new_end' => $newEnd, 'extra_cost' => $extraCost];
}
