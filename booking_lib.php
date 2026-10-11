<?php
// Logika inti pembuatan & perpanjangan booking, dipakai bersama oleh
// halaman user (apikr.php, extend_booking.php) dan admin (admin_api.php).

// Jam operasional (detik sejak 00:00 WIB): buka 10:30, tutup 22:00. Sesi tidak boleh lewat jam tutup.
const BOOKING_OPEN_SEC     = 10 * 3600 + 30 * 60;
const BOOKING_CLOSE_SEC    = 22 * 3600;
const BOOKING_MAX_DURATION = 12;
const BOOKING_EXTEND_MAX   = 3;
// Batas lapor ke kasir setelah jam mulai; lewat dari ini booking batal otomatis
const BOOKING_CHECKIN_SEC  = 15 * 60;
// Maksimal reservasi mendatang yang belum dibayar per akun user
const MAX_ACTIVE_UNPAID    = 2;

function bookingFail($message) {
  return ['ok' => false, 'message' => $message];
}

// Jam buka & jam tutup pada hari dari timestamp $ts
function openTs($ts) {
  return strtotime(date('Y-m-d', $ts)) + BOOKING_OPEN_SEC;
}
function closeTs($ts) {
  return strtotime(date('Y-m-d', $ts)) + BOOKING_CLOSE_SEC;
}

// Catat uang yang diterima kasir ke buku pembayaran. Dashboard menghitung pendapatan dari
// tabel ini menurut waktu bayar, jadi pelunasan tambahan jam di hari lain tidak memindahkan
// pembayaran sebelumnya ke tanggal baru.
function recordPayment($conn, $bookingId, $amount, $paidAt) {
  $bookingId = intval($bookingId);
  $amount    = intval($amount);
  $paidAt    = intval($paidAt);
  if ($amount <= 0) return;
  $stmt = $conn->prepare("INSERT INTO booking_payments (booking_id, amount, paid_at) VALUES (?, ?, ?)");
  $stmt->bind_param("iii", $bookingId, $amount, $paidAt);
  $stmt->execute();
  $stmt->close();
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

  // Jam mulai kelipatan 30 menit (10:30, 11:00, ... 21:00)
  if (!preg_match('/^([01]?\d|2[0-3]):(00|30)$/', $time)) return bookingFail("Format jam tidak valid");

  $start = strtotime("$date $time");
  if ($start === false) return bookingFail("Format waktu tidak valid");
  // Minimal 1 jam sebelum tutup, jadi jam mulai terakhir 21:00
  if ($start < openTs($start) || $start + 3600 > closeTs($start)) {
    return bookingFail("Jam mulai harus di antara 10:30-21:00");
  }
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
  if ($end > closeTs($start)) {
    return bookingFail("Durasi melewati jam tutup (22:00)");
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

    // Batas reservasi belum bayar per akun. Baris user dikunci supaya request
    // booking yang dikirim bersamaan tidak lolos semua dari hitungan ini.
    if (isset($o['max_unpaid']) && $userId !== null) {
      $uid = intval($userId);
      $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
      $stmt->bind_param("i", $uid);
      $stmt->execute();
      $stmt->get_result();
      $stmt->close();

      $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM bookings
        WHERE user_id = ? AND payment_status = 'unpaid' AND end_time > UNIX_TIMESTAMP()
      ");
      $stmt->bind_param("i", $uid);
      $stmt->execute();
      $activeUnpaid = intval($stmt->get_result()->fetch_assoc()['n']);
      $stmt->close();
      if ($activeUnpaid >= $o['max_unpaid']) {
        $conn->rollback();
        return bookingFail("Kamu sudah punya {$o['max_unpaid']} reservasi yang belum dibayar. Bayar di kasir atau batalkan salah satunya dulu.");
      }
    }

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
       total_price, amount_paid, payment_status, source, expires_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssiiiiiiissi",
      $name, $email, $phone, $roomId, $duration, $start, $end, $userId,
      $total, $amountPaid, $payStat, $source, $expires
    );
    $stmt->execute();
    $bookingId = $stmt->insert_id;
    $stmt->close();

    $code = "GZ-$bookingId";
    $stmt = $conn->prepare("UPDATE bookings SET order_code = ? WHERE id = ?");
    $stmt->bind_param("si", $code, $bookingId);
    $stmt->execute();
    $stmt->close();

    recordPayment($conn, $bookingId, $amountPaid, $paidAt);

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
    // Konsumen harus sudah lapor ke kasir (check-in) sebelum sesi bisa diperpanjang, baik
    // oleh user maupun admin. Tanpa ini booking lunas yang belum datang berubah jadi
    // "belum bayar" lalu ikut terbatal otomatis sebagai no-show.
    if ($booking['expires_at'] !== null) {
      $conn->rollback();
      return bookingFail($userId !== null
        ? "Lapor ke kasir dulu sebelum memperpanjang sesi"
        : "Konsumen belum ditandai hadir. Klik Hadir dulu sebelum menambah jam.");
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
    if ($newEnd > closeTs(intval($booking['start_time']))) {
      $conn->rollback();
      return bookingFail("Perpanjangan melewati jam tutup (22:00)");
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
