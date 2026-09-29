<?php
// Antrian FIFO gabungan (online + walk-in) untuk "main sekarang", per tipe konsol.
// Reservasi terjadwal (apikr.php) tetap prioritas di jamnya: antrian hanya
// mendapat ruangan yang kosong mulai sekarang sampai durasi selesai.

require_once __DIR__ . '/booking_lib.php';

const QUEUE_CONSOLES = ['PS3', 'PS4', 'PS5'];

function queueCloseLimit($now) {
  return strtotime(date('Y-m-d', $now)) + 24 * 3600;
}

// Assign antrian terdepan ke ruangan kosong. Return jumlah antrian yang di-assign.
function processQueue($conn, $now = null) {
  $now = $now ?? time();
  $dayStart   = strtotime(date('Y-m-d', $now));
  $closeLimit = $dayStart + 24 * 3600;
  $assigned = 0;

  try {
    $conn->begin_transaction();

    // Kunci semua room (urut id) supaya serial dengan apikr.php & extend_booking.php
    $rooms = $conn->query("SELECT id, console_type, price, status FROM rooms ORDER BY id FOR UPDATE")
                  ->fetch_all(MYSQLI_ASSOC);

    // Konsumen yang tidak lapor ke kasir sampai batas check-in: ruangan bebas lagi
    releaseNoShows($conn, $now);

    // Antrian dari hari sebelumnya atau yang durasinya sudah tidak muat sebelum tutup
    $stmt = $conn->prepare("
      UPDATE booking_queue SET status = 'cancelled'
      WHERE status = 'waiting'
        AND (created_at < FROM_UNIXTIME(?) OR ? + duration * 3600 > ?)
    ");
    $stmt->bind_param("iii", $dayStart, $now, $closeLimit);
    $stmt->execute();
    $stmt->close();

    if (intval(date('G', $now)) < BOOKING_OPEN_HOUR) {
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
      (customer_name, phone, room_id, duration, start_time, end_time, user_id, total_price, payment_status, source, paid_at, expires_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $setCode = $conn->prepare("UPDATE bookings SET order_code = ? WHERE id = ?");
    $markQ   = $conn->prepare("UPDATE booking_queue SET status = 'assigned', booking_id = ?, assigned_at = NOW() WHERE id = ?");

    // FIFO ketat per konsol: kalau antrian terdepan belum dapat ruangan,
    // antrian di belakangnya (konsol sama) tidak boleh menyalip.
    $blocked = [];

    foreach ($queue as $q) {
      $ct = $q['console_type'];
      if (isset($blocked[$ct])) continue;

      $duration = intval($q['duration']);
      $end = $now + $duration * 3600;

      $room = null;
      foreach ($rooms as $r) {
        if ($r['console_type'] !== $ct || $r['status'] !== 'available') continue;
        $rid = intval($r['id']);
        $conflict->bind_param("iii", $rid, $end, $now);
        $conflict->execute();
        if (!$conflict->get_result()->fetch_row()) { $room = $r; break; }
      }

      if (!$room) { $blocked[$ct] = true; continue; }

      $roomId  = intval($room['id']);
      $price   = intval($room['price']) * $duration;
      $isWalk  = $q['source'] === 'walkin';
      $payStat = $isWalk ? 'paid' : 'unpaid';
      $paidAt  = $isWalk ? $now : null;
      // Walk-in sudah di tempat; online harus datang dalam batas check-in
      $expires = $isWalk ? null : $now + BOOKING_CHECKIN_SEC;
      $userId  = $q['user_id'] !== null ? intval($q['user_id']) : null;
      $source  = $q['source'];

      $insert->bind_param("ssiiiiiissii",
        $q['customer_name'], $q['phone'], $roomId, $duration, $now, $end,
        $userId, $price, $payStat, $source, $paidAt, $expires
      );
      $insert->execute();
      $bookingId = $insert->insert_id;

      $code = "GZ-$bookingId";
      $setCode->bind_param("si", $code, $bookingId);
      $setCode->execute();

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

// Posisi dalam antrian konsol yang sama (1 = terdepan)
function queuePosition($conn, $entry) {
  $stmt = $conn->prepare("
    SELECT COUNT(*) AS n FROM booking_queue
    WHERE status = 'waiting' AND console_type = ?
      AND (created_at < ? OR (created_at = ? AND id < ?))
  ");
  $id = intval($entry['id']);
  $stmt->bind_param("sssi", $entry['console_type'], $entry['created_at'], $entry['created_at'], $id);
  $stmt->execute();
  $n = intval($stmt->get_result()->fetch_assoc()['n']);
  $stmt->close();
  return $n + 1;
}
