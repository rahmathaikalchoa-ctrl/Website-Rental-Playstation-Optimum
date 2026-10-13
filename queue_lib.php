<?php
// antrian gabungan (online + walk-in) main sekarang, per kelas ruangan (cth VIP PS5)
// reservasi terjadwal tetap prioritas, antrian cuma dapat ruangan yg kosong sampai durasinya selesai

require_once __DIR__ . '/booking_lib.php';

// jenis konsol
const CONSOLE_TYPES = ['PS4', 'PS5', 'SWITCH'];

// kelas ruangan = tier + model konsol
const ROOM_CLASS_SQL = "CONCAT(tier, ' ', console_model)";

// kelas ruangan aktif + tarif termurah, urut murah
// nintendo_extra NULL = gak ada yg bisa Nintendo
function roomClasses($conn) {
  return $conn->query("
    SELECT " . ROOM_CLASS_SQL . " AS room_class, MIN(price) AS price, COUNT(*) AS rooms,
           MAX(weekend_extra) AS weekend_extra, MIN(nintendo_extra) AS nintendo_extra
    FROM rooms WHERE status = 'available'
    GROUP BY room_class ORDER BY price, room_class
  ")->fetch_all(MYSQLI_ASSOC);
}

// validasi Nintendo/pelajar buat antrian $class, return error / null
function queueOptionError($conn, $class, $withNintendo, $isStudent) {
  $info = null;
  foreach (roomClasses($conn) as $c) if ($c['room_class'] === $class) $info = $c;
  if (!$info) return "Kelas ruangan tidak valid";
  if ($withNintendo && $info['nintendo_extra'] === null) return "Kelas $class tidak menyediakan tambahan Nintendo Switch";
  // harga pelajar dicek pakai tarif kelas
  if ($isStudent && !studentEligible($info, $withNintendo, time())) {
    return "Harga pelajar hanya untuk hari Senin-Jumat dan kelas ruangan yang ada di Paket Pelajar";
  }
  return null;
}

// kasih ruangan kosong ke antrian terdepan, return jumlah yg dapat
function processQueue($conn, $now = null) {
  $now = $now ?? time();
  $dayStart   = strtotime(date('Y-m-d', $now));
  $closeLimit = closeTs($now);
  $assigned = 0;

  try {
    $conn->begin_transaction();

    // kunci semua room (urut id) biar serial sama apikr.php & extend_booking.php
    $rooms = $conn->query("SELECT id, " . ROOM_CLASS_SQL . " AS room_class, price, weekend_extra, nintendo_extra, status FROM rooms ORDER BY id FOR UPDATE")
                  ->fetch_all(MYSQLI_ASSOC);

    // yg gak lapor sampai batas check-in: ruangan bebas lagi
    releaseNoShows($conn, $now);

    // antrian kemarin atau yg durasinya udah gak muat sebelum tutup
    $stmt = $conn->prepare("
      UPDATE booking_queue SET status = 'cancelled'
      WHERE status = 'waiting'
        AND (created_at < FROM_UNIXTIME(?) OR ? + duration * 3600 > ?)
    ");
    $stmt->bind_param("iii", $dayStart, $now, $closeLimit);
    $stmt->execute();
    $stmt->close();

    if ($now < openTs($now)) {
      $conn->commit();
      return 0;
    }

    $queue = $conn->query("SELECT * FROM booking_queue WHERE status = 'waiting' ORDER BY created_at, id FOR UPDATE")
                  ->fetch_all(MYSQLI_ASSOC);

    $conflict = $conn->prepare("
      SELECT 1 FROM bookings
      WHERE room_id = ? AND payment_status <> 'cancelled' AND start_time < ? AND end_time > ?
      LIMIT 1
    ");
    $insert = $conn->prepare("
      INSERT INTO bookings
      (customer_name, phone, room_id, duration, with_nintendo, is_student, start_time, end_time, user_id, total_price, amount_paid, payment_status, source, expires_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $setCode = $conn->prepare("UPDATE bookings SET order_code = ? WHERE id = ?");
    $markQ   = $conn->prepare("UPDATE booking_queue SET status = 'assigned', booking_id = ?, assigned_at = NOW() WHERE id = ?");

    // urutan ketat per kelas: yg di belakang gak boleh nyalip
    // antrian + Nintendo cuma dapat ruangan yg ada Nintendo
    $blocked = [];

    foreach ($queue as $q) {
      $ct = $q['room_class'];
      if (isset($blocked[$ct])) continue;

      $duration = intval($q['duration']);
      $end = $now + $duration * 3600;
      $withN   = (bool) $q['with_nintendo'];
      $student = (bool) $q['is_student'];

      $room = null;
      foreach ($rooms as $r) {
        if ($r['room_class'] !== $ct || $r['status'] !== 'available') continue;
        if ($withN && $r['nintendo_extra'] === null) continue;
        $rid = intval($r['id']);
        $conflict->bind_param("iii", $rid, $end, $now);
        $conflict->execute();
        if (!$conflict->get_result()->fetch_row()) { $room = $r; break; }
      }

      if (!$room) { $blocked[$ct] = true; continue; }

      $roomId  = intval($room['id']);
      // harga pelajar cuma kalo ruangannya masuk paket pelajar
      $student = $student && studentEligible($room, $withN, $now);
      $price   = bookingPrice($room, $duration, $now, $withN, $student);
      $nFlag   = $withN ? 1 : 0;
      $sFlag   = $student ? 1 : 0;
      $isWalk  = $q['source'] === 'walkin';
      $payStat = $isWalk ? 'paid' : 'unpaid';
      $paidAt  = $isWalk ? $now : null;
      // walk-in udah di tempat, online harus dateng dalam batas check-in
      $expires = $isWalk ? null : $now + BOOKING_CHECKIN_SEC;
      $userId  = $q['user_id'] !== null ? intval($q['user_id']) : null;
      $source  = $q['source'];

      $amountPaid = $isWalk ? $price : 0;
      $insert->bind_param("ssiiiiiiiiissi",
        $q['customer_name'], $q['phone'], $roomId, $duration, $nFlag, $sFlag, $now, $end,
        $userId, $price, $amountPaid, $payStat, $source, $expires
      );
      $insert->execute();
      $bookingId = $insert->insert_id;

      $code = "GZ-$bookingId";
      $setCode->bind_param("si", $code, $bookingId);
      $setCode->execute();

      if ($amountPaid > 0) recordPayment($conn, $bookingId, $amountPaid, $paidAt);

      $qid = intval($q['id']);
      $markQ->bind_param("ii", $bookingId, $qid);
      $markQ->execute();

      $assigned++;
    }

    $conflict->close();
    $insert->close();
    $setCode->close();
    $markQ->close();
    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("processQueue failed: " . $e->getMessage());
    return 0;
  }

  return $assigned;
}

// posisi di antrian kelas yg sama (1 = terdepan)
function queuePosition($conn, $entry) {
  $stmt = $conn->prepare("
    SELECT COUNT(*) AS n FROM booking_queue
    WHERE status = 'waiting' AND room_class = ?
      AND (created_at < ? OR (created_at = ? AND id < ?))
  ");
  $id = intval($entry['id']);
  $stmt->bind_param("sssi", $entry['room_class'], $entry['created_at'], $entry['created_at'], $id);
  $stmt->execute();
  $n = intval($stmt->get_result()->fetch_assoc()['n']);
  $stmt->close();
  return $n + 1;
}

// perkiraan kapan dapat ruangan (dari sesi yg jalan), reservasi gak dihitung jadi cuma kira-kira
function queueEstimate($conn, $entry, $now = null) {
  $now = $now ?? time();
  $class = $entry['room_class'];
  $stmt = $conn->prepare("
    SELECT r.id,
      (SELECT MAX(b.end_time) FROM bookings b
        WHERE b.room_id = r.id AND b.payment_status <> 'cancelled' AND b.start_time <= ? AND b.end_time > ?) AS busy_until
    FROM rooms r
    WHERE r.status = 'available' AND " . ROOM_CLASS_SQL . " = ?
      AND (? = 0 OR r.nintendo_extra IS NOT NULL)
  ");
  $n = $entry['with_nintendo'] ? 1 : 0;
  $stmt->bind_param("iisi", $now, $now, $class, $n);
  $stmt->execute();
  $freeAt = array_map(fn($r) => max($now, intval($r['busy_until'])), $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
  $stmt->close();
  if (!$freeAt) return null;

  // antrian di depan pakai ruangan duluan
  $stmt = $conn->prepare("
    SELECT duration FROM booking_queue
    WHERE status = 'waiting' AND room_class = ?
      AND (created_at < ? OR (created_at = ? AND id < ?))
    ORDER BY created_at, id
  ");
  $id = intval($entry['id']);
  $stmt->bind_param("sssi", $class, $entry['created_at'], $entry['created_at'], $id);
  $stmt->execute();
  foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $ahead) {
    sort($freeAt);
    $freeAt[0] += intval($ahead['duration']) * 3600;
  }
  $stmt->close();
  return min($freeAt);
}
