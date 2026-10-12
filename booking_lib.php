<?php
// Logika inti pembuatan & perpanjangan booking, dipakai bersama oleh
// halaman user (apikr.php, extend_booking.php) dan admin (admin_api.php).

// Jam operasional (detik sejak 00:00 WIB): buka 10:00, tutup 22:00. Sesi tidak boleh lewat jam tutup.
const BOOKING_OPEN_SEC     = 10 * 3600;
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
// "HH:MM" dari detik sejak 00:00 (untuk pesan error)
function secToHHMM($sec) {
  return sprintf('%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
}

// ===== HARGA (sesuai pricelist) =====
// Paket Main Seru: tarif per jam => total harga 1-5 jam. Berlaku otomatis untuk ruangan
// dengan tarif tersebut; jam ke-6 dst. memakai tarif biasa.
const PRICE_PACKAGES = [
  12000 => [12000, 22000, 31000, 40000, 48000],   // Public PS4 Slim
  15000 => [15000, 28000, 40000, 52000, 63000],   // Public PS4 Pro
  25000 => [25000, 47000, 68000, 89000, 108000],  // Public PS5
  30000 => [30000, 55000, 80000, 105000, 130000], // VIP (PS5/Nintendo + Netflix)
  50000 => [50000, 95000, 140000, 185000, 230000], // VVIP + Nintendo
  55000 => [55000, 105000, 155000, 205000, 255000], // VIP Special (PS5 Pro + Nintendo)
];
// Paket Pelajar (Senin-Jumat, wajib kartu pelajar, diterapkan kasir): tarif => [1 jam, 3 jam]
const STUDENT_PRICES = [
  12000 => [10000, 27000],
  15000 => [12000, 33000],
  25000 => [20000, 57000],
  30000 => [25000, 75000],
];

function isWeekendTs($ts) {
  return intval(date('N', $ts)) >= 6;
}

// Tarif per jam hari biasa, termasuk tambahan Nintendo Switch bila dipilih
function roomHourlyRate(array $room, $withNintendo) {
  return intval($room['price']) + ($withNintendo && $room['nintendo_extra'] !== null ? intval($room['nintendo_extra']) : 0);
}

// Harga pelajar hanya untuk hari kerja dan tarif yang ada di daftar Paket Pelajar
function studentEligible(array $room, $withNintendo, $startTs) {
  return !isWeekendTs($startTs) && isset(STUDENT_PRICES[roomHourlyRate($room, $withNintendo)]);
}

// Total harga booking. Urutan: harga pelajar (bila berlaku) > paket 1-5 jam > tarif x jam,
// lalu ditambah biaya weekend per jam (Sabtu/Minggu, menurut tanggal mulai).
function bookingPrice(array $room, $hours, $startTs, $withNintendo = false, $isStudent = false) {
  $hours = intval($hours);
  $rate  = roomHourlyRate($room, $withNintendo);
  if ($isStudent && studentEligible($room, $withNintendo, $startTs)) {
    [$one, $three] = STUDENT_PRICES[$rate];
    return intdiv($hours, 3) * $three + ($hours % 3) * $one;
  }
  if (isset(PRICE_PACKAGES[$rate])) {
    $pkg   = PRICE_PACKAGES[$rate];
    $total = $hours <= 5 ? $pkg[$hours - 1] : $pkg[4] + ($hours - 5) * $rate;
  } else {
    $total = $rate * $hours;
  }
  if (isWeekendTs($startTs)) $total += intval($room['weekend_extra']) * $hours;
  return $total;
}

// Data harga untuk halaman (script.js / admin.js menghitung estimasi dengan rumus yang sama)
function pricingJson() {
  return json_encode(['packages' => PRICE_PACKAGES, 'student' => STUDENT_PRICES]);
}

// ===== POIN LOYALITAS =====
// 1 poin per jam main yang lunas (akun online). 10 poin ditukar gratis 1 jam di kasir.
const POINTS_PER_FREE_HOUR = 10;

// Baris poin dari booking yang dibatalkan tidak dihitung: poin yang didapat hilang dan
// poin yang sudah ditukar kembali, dari jalur pembatalan mana pun (user, kasir, no-show).
function pointsBalance($conn, $userId) {
  $uid = intval($userId);
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(lp.points), 0) AS p
    FROM loyalty_points lp LEFT JOIN bookings b ON b.id = lp.booking_id
    WHERE lp.user_id = ? AND (b.id IS NULL OR b.payment_status <> 'cancelled')
  ");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  $p = intval($stmt->get_result()->fetch_assoc()['p']);
  $stmt->close();
  return $p;
}

// Beri poin untuk booking yang baru lunas. Booking yang diperpanjang lalu dilunasi lagi
// poinnya disesuaikan dengan durasi terbaru (tidak dobel).
function awardPoints($conn, $bookingId) {
  $bid = intval($bookingId);
  $stmt = $conn->prepare("SELECT user_id, duration FROM bookings WHERE id = ? AND payment_status = 'paid'");
  $stmt->bind_param("i", $bid);
  $stmt->execute();
  $b = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$b || $b['user_id'] === null) return;
  $uid = intval($b['user_id']);
  $dur = intval($b['duration']);

  $stmt = $conn->prepare("SELECT id, points FROM loyalty_points WHERE booking_id = ? AND points > 0 LIMIT 1");
  $stmt->bind_param("i", $bid);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) {
    if ($dur > intval($row['points'])) {
      $rid = intval($row['id']);
      $stmt = $conn->prepare("UPDATE loyalty_points SET points = ? WHERE id = ?");
      $stmt->bind_param("ii", $dur, $rid);
      $stmt->execute();
      $stmt->close();
    }
    return;
  }
  $note = "Main $dur jam";
  $stmt = $conn->prepare("INSERT INTO loyalty_points (user_id, booking_id, points, note) VALUES (?, ?, ?, ?)");
  $stmt->bind_param("iiis", $uid, $bid, $dur, $note);
  $stmt->execute();
  $stmt->close();
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

  // Jam mulai kelipatan 30 menit (10:00, 10:30, ... 21:00)
  if (!preg_match('/^([01]?\d|2[0-3]):(00|30)$/', $time)) return bookingFail("Format jam tidak valid");

  $start = strtotime("$date $time");
  if ($start === false) return bookingFail("Format waktu tidak valid");
  // Minimal 1 jam sebelum tutup, jadi jam mulai terakhir 1 jam sebelum tutup
  if ($start < openTs($start) || $start + 3600 > closeTs($start)) {
    return bookingFail("Jam mulai harus di antara " . secToHHMM(BOOKING_OPEN_SEC) . "-" . secToHHMM(BOOKING_CLOSE_SEC - 3600));
  }
  if ($start < time()) return bookingFail("Jam mulai sudah lewat, pilih jam lain");

  return ['ok' => true, 'start' => $start];
}

// $o: room_id, start, duration, customer_name, email, phone, user_id,
//     source ('online'|'walkin'), payment_status ('unpaid'|'paid'), expires_at,
//     with_nintendo (opsional, tambah Nintendo Switch bila ruangan menyediakan),
//     is_student (opsional, harga Paket Pelajar; hanya dari kasir/admin)
function createBooking($conn, array $o) {
  $roomId   = intval($o['room_id']);
  $start    = intval($o['start']);
  $duration = intval($o['duration']);
  $end      = $start + $duration * 3600;
  $nintendo = !empty($o['with_nintendo']);
  $student  = !empty($o['is_student']);

  if ($duration < 1 || $duration > BOOKING_MAX_DURATION) {
    return bookingFail("Durasi harus 1-" . BOOKING_MAX_DURATION . " jam");
  }
  if ($end > closeTs($start)) {
    return bookingFail("Durasi melewati jam tutup (" . secToHHMM(BOOKING_CLOSE_SEC) . ")");
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

    $stmt = $conn->prepare("SELECT id, title, price, weekend_extra, nintendo_extra, status FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$room) { $conn->rollback(); return bookingFail("Ruangan tidak ditemukan"); }
    if ($room['status'] !== 'available') { $conn->rollback(); return bookingFail("Ruangan sedang dalam perawatan"); }
    if ($nintendo && $room['nintendo_extra'] === null) {
      $conn->rollback();
      return bookingFail("Ruangan ini tidak menyediakan tambahan Nintendo Switch");
    }
    if ($student && !studentEligible($room, $nintendo, $start)) {
      $conn->rollback();
      return bookingFail("Harga pelajar hanya untuk hari Senin-Jumat dan ruangan yang ada di Paket Pelajar");
    }

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

    $total = bookingPrice($room, $duration, $start, $nintendo, $student);
    $amountPaid = $payStat === 'paid' ? $total : 0;
    $withN = $nintendo ? 1 : 0;
    $isStu = $student ? 1 : 0;
    $stmt = $conn->prepare("
      INSERT INTO bookings
      (customer_name, email, phone, room_id, duration, with_nintendo, is_student, start_time, end_time, user_id,
       total_price, amount_paid, payment_status, source, expires_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssiiiiiiiiissi",
      $name, $email, $phone, $roomId, $duration, $withN, $isStu, $start, $end, $userId,
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

    $stmt = $conn->prepare("SELECT price, weekend_extra, nintendo_extra, status FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$room) { $conn->rollback(); return bookingFail("Ruangan tidak ditemukan"); }
    if ($room['status'] !== 'available') { $conn->rollback(); return bookingFail("Ruangan sedang dalam perawatan"); }

    $stmt = $conn->prepare("
      SELECT start_time, end_time, duration, with_nintendo, is_student, expires_at FROM bookings
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
      return bookingFail("Perpanjangan melewati jam tutup (" . secToHHMM(BOOKING_CLOSE_SEC) . ")");
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

    // Selisih harga durasi baru dan lama, supaya harga paket/pelajar/weekend tetap berlaku
    $startTs   = intval($booking['start_time']);
    $curDur    = intval($booking['duration']);
    $nintendo  = (bool) $booking['with_nintendo'];
    $student   = (bool) $booking['is_student'];
    $extraCost = bookingPrice($room, $curDur + $extraHours, $startTs, $nintendo, $student)
               - bookingPrice($room, $curDur, $startTs, $nintendo, $student);
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
