<?php
// logika inti booking & perpanjangan, dipakai user (apikr.php, extend_booking.php) & admin (admin_api.php)

// jam operasional (detik dari 00:00 WIB): 10:00-22:00, sesi gak boleh lewat tutup
const BOOKING_OPEN_SEC     = 10 * 3600;
const BOOKING_CLOSE_SEC    = 22 * 3600;
const BOOKING_MAX_DURATION = 12;
const BOOKING_EXTEND_MAX   = 3;
// batas lapor kasir setelah jam mulai, lewat = batal otomatis
const BOOKING_CHECKIN_SEC  = 15 * 60;
// maks reservasi belum bayar per akun
const MAX_ACTIVE_UNPAID    = 2;

function bookingFail($message) {
  return ['ok' => false, 'message' => $message];
}

// jam buka & tutup di hari $ts
function openTs($ts) {
  return strtotime(date('Y-m-d', $ts)) + BOOKING_OPEN_SEC;
}
function closeTs($ts) {
  return strtotime(date('Y-m-d', $ts)) + BOOKING_CLOSE_SEC;
}
// HH:MM dari detik (buat pesan error)
function secToHHMM($sec) {
  return sprintf('%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
}

// ===== HARGA (pricelist, diatur di halaman Harga) =====
// paket main seru (price_packages): tarif => total 1-5 jam, jam ke-6 dst tarif biasa
// paket pelajar (student_prices): tarif => [1 jam, 3 jam], senin-jumat, dipasang kasir
const PACKAGE_HOURS = 5;

// dibaca sekali per request
function pricePackages() {
  static $cache = null;
  if ($cache === null) {
    global $conn;
    $cache = [];
    foreach ($conn->query("SELECT rate, hours, price FROM price_packages ORDER BY rate, hours") as $r) {
      $cache[intval($r["rate"])][intval($r["hours"]) - 1] = intval($r["price"]);
    }
    // paket yg gak lengkap 1-5 jam diskip biar index gak salah
    $cache = array_filter($cache, fn($p) => count($p) === PACKAGE_HOURS);
  }
  return $cache;
}
function studentPrices() {
  static $cache = null;
  if ($cache === null) {
    global $conn;
    $cache = [];
    foreach ($conn->query("SELECT rate, price_1h, price_3h FROM student_prices ORDER BY rate") as $r) {
      $cache[intval($r["rate"])] = [intval($r["price_1h"]), intval($r["price_3h"])];
    }
  }
  return $cache;
}
function isWeekendTs($ts) {
  return intval(date('N', $ts)) >= 6;
}

// tarif per jam hari biasa (+ Nintendo kalo dipilih)
function roomHourlyRate(array $room, $withNintendo) {
  return intval($room['price']) + ($withNintendo && $room['nintendo_extra'] !== null ? intval($room['nintendo_extra']) : 0);
}

// harga pelajar cuma hari kerja & tarif yg ada di paket pelajar
function studentEligible(array $room, $withNintendo, $startTs) {
  return !isWeekendTs($startTs) && isset(studentPrices()[roomHourlyRate($room, $withNintendo)]);
}

// total harga: pelajar > paket 1-5 jam > tarif x jam, terus + weekend per jam (sabtu/minggu tgl mulai)
function bookingPrice(array $room, $hours, $startTs, $withNintendo = false, $isStudent = false) {
  $hours = intval($hours);
  $rate  = roomHourlyRate($room, $withNintendo);
  if ($isStudent && studentEligible($room, $withNintendo, $startTs)) {
    [$one, $three] = studentPrices()[$rate];
    return intdiv($hours, 3) * $three + ($hours % 3) * $one;
  }
  $packages = pricePackages();
  if (isset($packages[$rate])) {
    $pkg   = $packages[$rate];
    $total = $hours <= 5 ? $pkg[$hours - 1] : $pkg[4] + ($hours - 5) * $rate;
  } else {
    $total = $rate * $hours;
  }
  if (isWeekendTs($startTs)) $total += intval($room['weekend_extra']) * $hours;
  return $total;
}

// data harga buat JS (rumus estimasi sama)
function pricingJson() {
  return json_encode(['packages' => pricePackages(), 'student' => studentPrices()]);
}

// ===== POIN LOYALITAS =====
// 1 poin per jam lunas (akun online), 10 poin = gratis 1 jam
const POINTS_PER_FREE_HOUR = 10;

// poin dari booking batal gak dihitung (poin dapet hilang, poin ditukar balik)
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

// kasih poin buat booking yg baru lunas, kalo diperpanjang terus lunas lagi disesuaikan (gak dobel)
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

// catat uang masuk kasir ke booking_payments
// dashboard hitung per waktu bayar, jadi pelunasan tambahan di hari lain gak mindahin bayaran lama
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

// batalin booking yg gak lapor kasir sampai batas check-in (no-show), yg lunas gak pernah
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

// timestamp mulai dari tanggal (hari ini s/d lusa) + jam HH:MM
function scheduledStart($date, $time) {
  $today   = date('Y-m-d');
  $allowed = [$today, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+2 days'))];
  if (!in_array($date, $allowed, true)) return bookingFail("Tanggal booking tidak valid");

  // jam mulai kelipatan 30 menit
  if (!preg_match('/^([01]?\d|2[0-3]):(00|30)$/', $time)) return bookingFail("Format jam tidak valid");

  $start = strtotime("$date $time");
  if ($start === false) return bookingFail("Format waktu tidak valid");
  // jam mulai terakhir 1 jam sebelum tutup
  if ($start < openTs($start) || $start + 3600 > closeTs($start)) {
    return bookingFail("Jam mulai harus di antara " . secToHHMM(BOOKING_OPEN_SEC) . "-" . secToHHMM(BOOKING_CLOSE_SEC - 3600));
  }
  if ($start < time()) return bookingFail("Jam mulai sudah lewat, pilih jam lain");

  return ['ok' => true, 'start' => $start];
}

// $o: room_id, start, duration, customer_name, email, phone, user_id,
//     source (online|walkin), payment_status (unpaid|paid), expires_at,
//     with_nintendo, is_student (kasir aja), lock_room (opsional)
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

  // bebasin slot no-show dulu sebelum cek bentrok
  releaseNoShows($conn);

  // transaksi + lock ruangan: cek bentrok & insert atomik (anti double booking)
  try {
    $conn->begin_transaction();

    // batas belum bayar per akun, baris user dikunci biar request barengan gak lolos semua
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

    // wajib FOR UPDATE: tanpa ini kebaca snapshot lama (sebelum ruangan dikunci)
    // booking orang lain yg baru masuk gak keliatan, slot bisa kejual 2x
    $stmt = $conn->prepare("
      SELECT start_time FROM bookings
      WHERE room_id = ? AND payment_status <> 'cancelled' AND start_time < ? AND end_time > ?
      ORDER BY start_time LIMIT 1 FOR UPDATE
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
    $lock  = !empty($o['lock_room']) ? 1 : 0;
    $stmt = $conn->prepare("
      INSERT INTO bookings
      (customer_name, email, phone, room_id, duration, with_nintendo, is_student, lock_room, start_time, end_time, user_id,
       total_price, amount_paid, payment_status, source, expires_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssiiiiiiiiiissi",
      $name, $email, $phone, $roomId, $duration, $withN, $isStu, $lock, $start, $end, $userId,
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

// perpanjang sesi yg lagi jalan, $userId null = admin
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

  // lock room dulu baru booking (urutan sama kayak createBooking) biar gak pakai data basi
  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
      SELECT id, title, tier, console_model, price, weekend_extra, nintendo_extra, max_players, status
      FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE
    ");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$room) { $conn->rollback(); return bookingFail("Ruangan tidak ditemukan"); }
    if ($room['status'] !== 'available') { $conn->rollback(); return bookingFail("Ruangan sedang dalam perawatan"); }

    $stmt = $conn->prepare("
      SELECT id, start_time, end_time, duration, with_nintendo, is_student, expires_at FROM bookings
      WHERE id = ? AND room_id = ? AND payment_status <> 'cancelled'
      LIMIT 1 FOR UPDATE
    ");
    $stmt->bind_param("ii", $bookingId, $roomId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$booking) { $conn->rollback(); return bookingFail("Booking tidak ditemukan"); }
    // harus udah check-in dulu baru bisa diperpanjang
    // kalo gak, booking lunas yg belum dateng jadi belum bayar terus ikut batal no-show
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

    // reservasi berikutnya yg belum dateng dipindah ke ruangan setara
    // kalo gak bisa (udah dateng, dikunci, sekelas penuh) ditolak
    $plan = planExtension($conn, $booking, $room, $newEnd, true);
    if (!$plan['ok']) {
      $conn->rollback();
      return bookingFail("Ruangan ini sudah dipesan orang lain mulai jam " . date('H:i', $plan['blocked_at'])
        . ". Coba lanjut di ruangan sekelas yang masih kosong.");
    }
    applyRelocations($conn, $plan['moves']);

    // selisih harga durasi baru - lama biar paket/pelajar/weekend tetap kepake
    $startTs   = intval($booking['start_time']);
    $curDur    = intval($booking['duration']);
    $nintendo  = (bool) $booking['with_nintendo'];
    $student   = (bool) $booking['is_student'];
    $extraCost = bookingPrice($room, $curDur + $extraHours, $startTs, $nintendo, $student)
               - bookingPrice($room, $curDur, $startTs, $nintendo, $student);
    // ada tambahan biaya: balik belum bayar biar kasir tagih kurangnya, yg udah dibayar tetap
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

  return ['ok' => true, 'new_end' => $newEnd, 'extra_cost' => $extraCost,
          'relocated' => array_map(fn($m) => ['booking_id' => $m[0], 'room' => $m[2], 'start_time' => $m[3]], $plan['moves'])];
}

// pasang/lepas gratis 1 jam pakai 10 poin, cuma yg belum lunas
// $userId diisi kalo konsumen sendiri, null = kasir
function toggleRedeemPoints($conn, $bookingId, $userId = null) {
  $id = intval($bookingId);
  try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("
      SELECT b.user_id, b.discount, b.total_price, b.amount_paid, b.payment_status, r.price
      FROM bookings b JOIN rooms r ON r.id = b.room_id
      WHERE b.id = ? FOR UPDATE
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $error = null;
    if (!$b || $b['payment_status'] === 'cancelled'
        || ($userId !== null && intval($b['user_id']) !== intval($userId))) $error = "Booking tidak ditemukan atau sudah dibatalkan";
    elseif ($b['payment_status'] === 'paid') $error = "Booking sudah lunas, poin tidak bisa dipakai/dikembalikan lagi";
    elseif ($b['user_id'] === null) $error = "Poin hanya untuk booking dari akun website";
    if ($error) {
      $conn->rollback();
      return bookingFail($error);
    }
    $uid = intval($b['user_id']);
    // kunci baris user biar poin gak kepake 2x
    $lock = $conn->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
    $lock->bind_param("i", $uid);
    $lock->execute();
    $lock->get_result();
    $lock->close();

    if (intval($b['discount']) > 0) {
      // batal pakai poin: harga normal, 10 poin balik
      $stmt = $conn->prepare("UPDATE bookings SET total_price = total_price + discount, discount = 0 WHERE id = ?");
      $stmt->bind_param("i", $id);
      $stmt->execute();
      $stmt->close();
      $stmt = $conn->prepare("DELETE FROM loyalty_points WHERE booking_id = ? AND points < 0");
      $stmt->bind_param("i", $id);
      $stmt->execute();
      $stmt->close();
      $redeemed = false;
    } else {
      if (pointsBalance($conn, $uid) < POINTS_PER_FREE_HOUR) {
        $conn->rollback();
        return bookingFail("Poin belum cukup (butuh " . POINTS_PER_FREE_HOUR . " poin)");
      }
      $discount = min(intval($b['price']), intval($b['total_price']) - intval($b['amount_paid']));
      $stmt = $conn->prepare("UPDATE bookings SET discount = ?, total_price = total_price - ? WHERE id = ?");
      $stmt->bind_param("iii", $discount, $discount, $id);
      $stmt->execute();
      $stmt->close();
      $minus = -POINTS_PER_FREE_HOUR;
      $note = "Tukar gratis 1 jam";
      $stmt = $conn->prepare("INSERT INTO loyalty_points (user_id, booking_id, points, note) VALUES (?, ?, ?, ?)");
      $stmt->bind_param("iiis", $uid, $id, $minus, $note);
      $stmt->execute();
      $stmt->close();
      $redeemed = true;
    }
    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("toggleRedeemPoints failed: " . $e->getMessage());
    return bookingFail("Gagal memproses poin");
  }
  return ['ok' => true, 'redeemed' => $redeemed];
}

// pindah jadwal oleh konsumen: ruangan, tanggal/jam, durasi
// cuma sebelum mulai & sebelum check-in. kalo lebih mahal sisanya bayar di kasir, lebih murah dikembaliin kasir (refund)
function rescheduleBooking($conn, $bookingId, $userId, $roomId, $start, $duration) {
  $bookingId = intval($bookingId);
  $roomId    = intval($roomId);
  $start     = intval($start);
  $duration  = intval($duration);
  $end       = $start + $duration * 3600;

  if ($duration < 1 || $duration > BOOKING_MAX_DURATION) {
    return bookingFail("Durasi harus 1-" . BOOKING_MAX_DURATION . " jam");
  }
  if ($end > closeTs($start)) {
    return bookingFail("Durasi melewati jam tutup (" . secToHHMM(BOOKING_CLOSE_SEC) . ")");
  }
  releaseNoShows($conn);

  try {
    $conn->begin_transaction();
    // urutan kunci sama kayak createBooking: ruangan dulu baru booking
    $stmt = $conn->prepare("SELECT id, title, price, weekend_extra, nintendo_extra, status FROM rooms WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$room) { $conn->rollback(); return bookingFail("Ruangan tidak ditemukan"); }
    if ($room['status'] !== 'available') { $conn->rollback(); return bookingFail("Ruangan sedang dalam perawatan"); }

    $stmt = $conn->prepare("
      SELECT id, start_time, with_nintendo, is_student, discount, amount_paid, payment_status, expires_at
      FROM bookings WHERE id = ? AND user_id = ? FOR UPDATE
    ");
    $uid = intval($userId);
    $stmt->bind_param("ii", $bookingId, $uid);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b || $b['payment_status'] === 'cancelled') { $conn->rollback(); return bookingFail("Booking tidak ditemukan atau sudah dibatalkan"); }
    if ($b['expires_at'] === null || intval($b['start_time']) <= time()) {
      $conn->rollback();
      return bookingFail("Jadwal hanya bisa diubah sebelum sesi dimulai dan sebelum lapor ke kasir");
    }

    $stmt = $conn->prepare("
      SELECT 1 FROM bookings
      WHERE room_id = ? AND id <> ? AND payment_status <> 'cancelled' AND start_time < ? AND end_time > ?
      LIMIT 1 FOR UPDATE
    ");
    $stmt->bind_param("iiii", $roomId, $bookingId, $end, $start);
    $stmt->execute();
    $conflict = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($conflict) { $conn->rollback(); return bookingFail("Ruangan sudah dibooking di jam tersebut. Pilih jam atau ruangan lain."); }

    // Nintendo & harga pelajar ikut kalo masih berlaku di jadwal baru
    $nintendo = (bool) $b['with_nintendo'] && $room['nintendo_extra'] !== null;
    $student  = (bool) $b['is_student'] && studentEligible($room, $nintendo, $start);
    $gross    = bookingPrice($room, $duration, $start, $nintendo, $student);
    // potongan poin ikut tarif ruangan baru
    $discount = intval($b['discount']) > 0 ? min(intval($room['price']), $gross) : 0;
    $total    = $gross - $discount;
    $paid     = intval($b['amount_paid']);
    $status   = $paid > 0 && $paid >= $total ? 'paid' : 'unpaid';
    $expires  = $start + BOOKING_CHECKIN_SEC;
    $withN = $nintendo ? 1 : 0;
    $isStu = $student ? 1 : 0;

    $stmt = $conn->prepare("
      UPDATE bookings
      SET room_id = ?, start_time = ?, end_time = ?, duration = ?, with_nintendo = ?, is_student = ?,
          total_price = ?, discount = ?, payment_status = ?, expires_at = ?,
          relocated_from = NULL, relocated_at = NULL
      WHERE id = ?
    ");
    $stmt->bind_param("iiiiiiiisii", $roomId, $start, $end, $duration, $withN, $isStu, $total, $discount, $status, $expires, $bookingId);
    $stmt->execute();
    $stmt->close();

    // poin booking lunas gak boleh lebih dari durasi baru
    $stmt = $conn->prepare("UPDATE loyalty_points SET points = LEAST(points, ?) WHERE booking_id = ? AND points > 0");
    $stmt->bind_param("ii", $duration, $bookingId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("rescheduleBooking failed: " . $e->getMessage());
    return bookingFail("Gagal mengubah jadwal");
  }

  return ['ok' => true, 'booking' => [
    'room'        => $room['title'],
    'start_time'  => $start,
    'end_time'    => $end,
    'duration'    => $duration,
    'total_price' => $total,
    'due'         => max(0, $total - $paid),
    'refund'      => max(0, $paid - $total),
    'nintendo_dropped' => (bool) $b['with_nintendo'] && !$nintendo,
    'student_dropped'  => (bool) $b['is_student'] && !$student,
  ]];
}

// ===== PERPANJANG TANPA NGERUGIIN RESERVASI BERIKUTNYA =====
// yg dijamin kelas ruangan + jam, bukan nomor ruangan
// kalo bentrok sama reservasi yg belum dateng, reservasi itu dipindah ke ruangan setara yg kosong
// yg udah main & yg lock_room gak pernah dipindah

// ruangan setara $room yg kosong di [$start, $end)
// $exclude: ruangan yg gak boleh, $planned: pindahan lain yg lagi direncanain, $ignore: booking yg dianggap gak ada
function findEquivalentRoom($conn, array $room, $withNintendo, $start, $end, array $exclude = [], array $planned = [], array $ignore = [], $forUpdate = false) {
  $exclude = array_map('intval', $exclude);
  $ignore  = array_map('intval', $ignore) ?: [0];
  $sql = "
    SELECT r.id, r.title FROM rooms r
    WHERE r.status = 'available' AND r.tier = ? AND r.console_model = ? AND r.price = ? AND r.weekend_extra = ?
      AND r.max_players >= ?
      " . ($withNintendo ? "AND r.nintendo_extra = " . intval($room['nintendo_extra']) : "") . "
      " . ($exclude ? "AND r.id NOT IN (" . implode(',', $exclude) . ")" : "") . "
      AND NOT EXISTS (
        SELECT 1 FROM bookings b
        WHERE b.room_id = r.id AND b.payment_status <> 'cancelled' AND b.start_time < ? AND b.end_time > ?
          AND b.id NOT IN (" . implode(',', $ignore) . ")
      )
    ORDER BY r.id" . ($forUpdate ? " FOR UPDATE" : "");
  $stmt = $conn->prepare($sql);
  $price = intval($room['price']); $wk = intval($room['weekend_extra']); $mp = intval($room['max_players']);
  $stmt->bind_param("ssiiiii", $room['tier'], $room['console_model'], $price, $wk, $mp, $end, $start);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  foreach ($rows as $r) {
    $busy = false;
    foreach ($planned as [$pRoom, $pStart, $pEnd]) {
      if (intval($pRoom) === intval($r['id']) && $start < $pEnd && $end > $pStart) { $busy = true; break; }
    }
    if (!$busy) return $r;
  }
  return null;
}

// rencana perpanjang $b sampai $newEnd
// return ok + moves [[bookingId, roomId, roomTitle, start, end]] atau gagal + blocked_at
function planExtension($conn, array $b, array $room, $newEnd, $forUpdate = false) {
  $currentEnd = intval($b['end_time']);
  $stmt = $conn->prepare("
    SELECT id, start_time, end_time, expires_at, lock_room, with_nintendo FROM bookings
    WHERE room_id = ? AND id <> ? AND payment_status <> 'cancelled' AND start_time < ? AND end_time > ?
    ORDER BY start_time" . ($forUpdate ? " FOR UPDATE" : ""));
  $rid = intval($room['id']); $bid = intval($b['id']);
  $stmt->bind_param("iiii", $rid, $bid, $newEnd, $currentEnd);
  $stmt->execute();
  $conflicts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $moves = [];
  $planned = [];
  foreach ($conflicts as $c) {
    // udah dateng atau dikunci: gak bisa dipindah
    if ($c['expires_at'] === null || $c['lock_room']) return ['ok' => false, 'blocked_at' => intval($c['start_time'])];
    $target = findEquivalentRoom($conn, $room, (bool) $c['with_nintendo'], intval($c['start_time']), intval($c['end_time']),
                                 [$rid], $planned, [intval($c['id'])], $forUpdate);
    if (!$target) return ['ok' => false, 'blocked_at' => intval($c['start_time'])];
    $moves[]   = [intval($c['id']), intval($target['id']), $target['title'], intval($c['start_time']), intval($c['end_time'])];
    $planned[] = [intval($target['id']), intval($c['start_time']), intval($c['end_time'])];
  }
  return ['ok' => true, 'moves' => $moves];
}

// terapin pindahan, relocated_from simpan ruangan asal pertama biar info ke konsumen tetap bener
function applyRelocations($conn, array $moves) {
  $stmt = $conn->prepare("
    UPDATE bookings SET relocated_from = COALESCE(relocated_from, room_id), room_id = ?, relocated_at = UNIX_TIMESTAMP()
    WHERE id = ? AND expires_at IS NOT NULL AND lock_room = 0
  ");
  foreach ($moves as [$bookingId, $roomId]) {
    $stmt->bind_param("ii", $roomId, $bookingId);
    $stmt->execute();
  }
  $stmt->close();
}

// opsi tambah 1..BOOKING_EXTEND_MAX jam: di ruangan ini (bisa mindahin reservasi berikutnya) atau lanjut di ruangan setara
// read only, $room wajib ada id, tier, console_model, price, weekend_extra, nintendo_extra, max_players
function extensionOptions($conn, array $b, array $room) {
  $opts  = [];
  $start = intval($b['start_time']);
  $end   = intval($b['end_time']);
  $dur   = intval($b['duration']);
  $nint  = (bool) $b['with_nintendo'];
  $stud  = (bool) $b['is_student'];
  $until = $end;
  $sameOk = true;
  for ($h = 1; $h <= BOOKING_EXTEND_MAX; $h++) {
    $newEnd = $end + $h * 3600;
    $o = ['hours' => $h, 'cost' => bookingPrice($room, $dur + $h, $start, $nint, $stud) - bookingPrice($room, $dur, $start, $nint, $stud)];
    if ($dur + $h > BOOKING_MAX_DURATION) { $o['reason'] = 'max'; }
    elseif ($newEnd > closeTs($start)) { $o['reason'] = 'close'; }
    else {
      $plan = $sameOk ? planExtension($conn, $b, $room, $newEnd) : ['ok' => false];
      if ($plan['ok']) {
        $o['same_room'] = true;
        $o['moves'] = count($plan['moves']);
        $until = $newEnd;
      } else {
        $sameOk = false;
        if (isset($plan['blocked_at'])) $o['blocked_at'] = $plan['blocked_at'];
        $alt = findEquivalentRoom($conn, $room, $nint, $end, $newEnd, [intval($room['id'])]);
        if ($alt) { $o['alt_room_id'] = intval($alt['id']); $o['alt_room'] = $alt['title']; }
        else { $o['reason'] = 'full'; }
      }
    }
    $opts[$h] = $o;
  }
  return ['options' => $opts, 'until' => $until];
}

// lanjut main di ruangan setara mulai jam selesai, kalo ruangan ini gak bisa diperpanjang
// dibuat booking baru (udah hadir, belum bayar), harga sama kayak perpanjang biasa
function continueInOtherRoom($conn, $bookingId, $extraHours, $userId = null) {
  $bookingId  = intval($bookingId);
  $extraHours = intval($extraHours);
  if ($extraHours < 1 || $extraHours > BOOKING_EXTEND_MAX) {
    return bookingFail("Tambahan jam harus 1-" . BOOKING_EXTEND_MAX . " jam");
  }
  try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("
      SELECT b.*, r.title, r.tier, r.console_model, r.price, r.weekend_extra, r.nintendo_extra, r.max_players
      FROM bookings b JOIN rooms r ON r.id = b.room_id
      WHERE b.id = ? AND b.payment_status <> 'cancelled' FOR UPDATE
    ");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $now = time();
    if (!$b || ($userId !== null && intval($b['user_id']) !== intval($userId))) { $conn->rollback(); return bookingFail("Booking tidak ditemukan"); }
    if ($b['expires_at'] !== null || $now < intval($b['start_time']) || $now >= intval($b['end_time'])) {
      $conn->rollback();
      return bookingFail("Hanya sesi yang sedang berjalan yang bisa dilanjutkan");
    }
    $start = intval($b['end_time']);
    $end   = $start + $extraHours * 3600;
    if ($end > closeTs(intval($b['start_time']))) { $conn->rollback(); return bookingFail("Melewati jam tutup (" . secToHHMM(BOOKING_CLOSE_SEC) . ")"); }
    $room = ['id' => $b['room_id'], 'tier' => $b['tier'], 'console_model' => $b['console_model'], 'price' => $b['price'],
             'weekend_extra' => $b['weekend_extra'], 'nintendo_extra' => $b['nintendo_extra'], 'max_players' => $b['max_players']];
    $nint = (bool) $b['with_nintendo'];
    $target = findEquivalentRoom($conn, $room, $nint, $start, $end, [intval($b['room_id'])], [], [], true);
    if (!$target) { $conn->rollback(); return bookingFail("Semua ruangan sekelas sudah terpesan di jam tersebut"); }

    $dur  = intval($b['duration']);
    $cost = bookingPrice($room, $dur + $extraHours, intval($b['start_time']), $nint, (bool) $b['is_student'])
          - bookingPrice($room, $dur, intval($b['start_time']), $nint, (bool) $b['is_student']);
    $tid   = intval($target['id']);
    $withN = $nint ? 1 : 0;
    $uid   = $b['user_id'] !== null ? intval($b['user_id']) : null;
    $stmt = $conn->prepare("
      INSERT INTO bookings (customer_name, email, phone, room_id, duration, with_nintendo, start_time, end_time, user_id,
                            total_price, amount_paid, payment_status, source)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'unpaid', ?)
    ");
    $stmt->bind_param("sssiiiiiiis", $b['customer_name'], $b['email'], $b['phone'], $tid, $extraHours, $withN,
                      $start, $end, $uid, $cost, $b['source']);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    $code = "GZ-$newId";
    $stmt = $conn->prepare("UPDATE bookings SET order_code = ? WHERE id = ?");
    $stmt->bind_param("si", $code, $newId);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("continueInOtherRoom failed: " . $e->getMessage());
    return bookingFail("Gagal membuat lanjutan sesi");
  }
  return ['ok' => true, 'room' => $target['title'], 'order_code' => $code, 'start_time' => $start, 'end_time' => $end, 'extra_cost' => $cost];
}

// kasir mindahin reservasi yg belum dateng ke ruangan setara (cth PS rusak)
// kelas, jam, harga tetap. lock_room diabaikan karena ruangannya emang gak bisa dipake
function relocateBooking($conn, $bookingId) {
  $bookingId = intval($bookingId);
  try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("
      SELECT b.id, b.room_id, b.start_time, b.end_time, b.with_nintendo, b.expires_at,
             r.tier, r.console_model, r.price, r.weekend_extra, r.nintendo_extra, r.max_players
      FROM bookings b JOIN rooms r ON r.id = b.room_id
      WHERE b.id = ? AND b.payment_status <> 'cancelled' FOR UPDATE
    ");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) { $conn->rollback(); return bookingFail("Booking tidak ditemukan"); }
    if ($b['expires_at'] === null || intval($b['end_time']) <= time()) {
      $conn->rollback();
      return bookingFail("Hanya reservasi yang konsumennya belum datang yang bisa dipindah");
    }
    $room = ['id' => $b['room_id']] + $b;
    $target = findEquivalentRoom($conn, $room, (bool) $b['with_nintendo'], intval($b['start_time']), intval($b['end_time']),
                                 [intval($b['room_id'])], [], [$bookingId], true);
    if (!$target) { $conn->rollback(); return bookingFail("Tidak ada ruangan sekelas yang kosong di jam tersebut"); }

    $tid = intval($target['id']);
    $stmt = $conn->prepare("
      UPDATE bookings SET relocated_from = COALESCE(relocated_from, room_id), room_id = ?, relocated_at = UNIX_TIMESTAMP()
      WHERE id = ?
    ");
    $stmt->bind_param("ii", $tid, $bookingId);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("relocateBooking failed: " . $e->getMessage());
    return bookingFail("Gagal memindahkan booking");
  }
  return ['ok' => true, 'room' => $target['title']];
}
