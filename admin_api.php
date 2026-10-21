<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';
require_once __DIR__ . '/queue_lib.php';
require_once __DIR__ . '/booking_lib.php';

if (!isAdminSession($conn)) {
  echo json_encode(["status" => "unauthorized", "message" => "Sesi admin habis, silakan login ulang"]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}

// cek token CSRF, cegah aksi dari situs lain
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
  echo json_encode(["status" => "unauthorized", "message" => "Sesi halaman kedaluwarsa, halaman akan dimuat ulang"]);
  exit;
}

$action = $_POST['action'] ?? '';

// simpan gambar upload ke assets/images/{subdir}, return nama file / null
// dicek beneran gambar, maks 2 MB
function saveUploadedImage($field, $subdir, $prefix) {
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
  $f = $_FILES[$field];
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return null;
  if ($f['size'] > 2 * 1024 * 1024 || @getimagesize($f['tmp_name']) === false) return null;

  $dir = __DIR__ . '/assets/images/' . $subdir;
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $name = uniqid($prefix) . '.' . $ext;
  return move_uploaded_file($f['tmp_name'], "$dir/$name") ? $name : null;
}

function uploadAttempted($field) {
  return isset($_FILES[$field]) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE;
}

// hapus gambar upload (insert gagal / item dihapus)
function removeUploadedImage($subdir, $name) {
  if (!$name) return;
  $path = __DIR__ . '/assets/images/' . $subdir . '/' . basename($name);
  if (is_file($path)) unlink($path);
}

// validasi nama & HP konsumen offline, return pesan error / null
function offlineCustomerError($name, $phone) {
  if ($name === '') return "Nama konsumen wajib diisi";
  if (mb_strlen($name) > 100) return "Nama konsumen maksimal 100 karakter";
  if ($phone !== '' && !preg_match('/^[0-9]{9,14}$/', $phone)) return "Nomor HP tidak valid (hanya angka, 9-14 digit)";
  return null;
}

// alasan walk-in masuk antrian padahal ada ruangan kosong:
// kosong sekarang tapi ada reservasi sebelum durasinya selesai
// antrian + Nintendo cuma liat ruangan yg ada Nintendo
function walkinHint($conn, $class, $duration, $now, $withNintendo = false) {
  $stmt = $conn->prepare("
    SELECT r.title,
      (SELECT MIN(b.start_time) FROM bookings b
        WHERE b.room_id = r.id AND b.payment_status <> 'cancelled' AND b.start_time > ?) AS next_start
    FROM rooms r
    WHERE " . ROOM_CLASS_SQL . " = ? AND r.status = 'available'
      " . ($withNintendo ? "AND r.nintendo_extra IS NOT NULL" : "") . "
      AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.room_id = r.id AND b.payment_status <> 'cancelled'
                      AND b.start_time <= ? AND b.end_time > ?)
  ");
  $stmt->bind_param("isii", $now, $class, $now, $now);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  foreach ($rows as $r) {
    if (!$r['next_start']) continue;
    $maxHours = intdiv(intval($r['next_start']) - $now, 3600);
    if ($maxHours < $duration) {
      $at = date('H:i', intval($r['next_start']));
      return $maxHours >= 1
        ? "{$r['title']} kosong, tapi ada reservasi jam $at. Maksimal $maxHours jam, kurangi durasi atau tunggu."
        : "{$r['title']} kosong, tapi ada reservasi jam $at.";
    }
  }
  return null;
}

// ===== TANDAI LUNAS (bayar di kasir) =====
if ($action === 'mark_paid') {
  $id = intval($_POST['id'] ?? 0);
  $now = time();
  // bayar pas sesi udah dekat/jalan = udah dateng, sekalian check-in
  $checkinFrom = $now + BOOKING_CHECKIN_SEC;
  // batalin dulu yg lewat batas check-in, jadi gak bisa dilunasi
  releaseNoShows($conn, $now);
  try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("SELECT total_price, amount_paid FROM bookings WHERE id = ? AND payment_status = 'unpaid' FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$b) {
      $conn->rollback();
      echo json_encode(["status" => "error", "message" => "Booking sudah lunas atau sudah dibatalkan"]);
      exit;
    }
    // waktu tiap bayar dicatat di booking_payments
    $stmt = $conn->prepare("
      UPDATE bookings
      SET payment_status = 'paid', amount_paid = total_price,
          expires_at = IF(start_time <= ?, NULL, expires_at)
      WHERE id = ?
    ");
    $stmt->bind_param("ii", $checkinFrom, $id);
    $stmt->execute();
    $stmt->close();
    recordPayment($conn, $id, intval($b['total_price']) - intval($b['amount_paid']), $now);
    awardPoints($conn, $id);
    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("mark_paid failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Gagal menyimpan pembayaran"]);
    exit;
  }
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== HARGA PELAJAR (kasir cek kartu) =====
// cuma sebelum ada pembayaran biar gak ada refund sebagian
if ($action === 'toggle_student') {
  $id = intval($_POST['id'] ?? 0);
  try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("
      SELECT b.duration, b.start_time, b.with_nintendo, b.is_student, b.amount_paid, b.discount,
             r.price, r.weekend_extra, r.nintendo_extra
      FROM bookings b JOIN rooms r ON r.id = b.room_id
      WHERE b.id = ? AND b.payment_status <> 'cancelled' FOR UPDATE
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $error = null;
    if (!$b) {
      $error = "Booking tidak ditemukan atau sudah dibatalkan";
    } elseif (intval($b['amount_paid']) > 0) {
      $error = "Booking ini sudah ada pembayaran. Harga pelajar dipilih sebelum konsumen membayar.";
    } elseif (!$b['is_student'] && !studentEligible($b, (bool) $b['with_nintendo'], intval($b['start_time']))) {
      $error = "Harga pelajar hanya untuk hari Senin-Jumat dan ruangan yang ada di Paket Pelajar";
    }
    if ($error) {
      $conn->rollback();
      echo json_encode(["status" => "error", "message" => $error]);
      exit;
    }
    $student = $b['is_student'] ? 0 : 1;
    $gross   = bookingPrice($b, intval($b['duration']), intval($b['start_time']), (bool) $b['with_nintendo'], (bool) $student);
    // potongan poin tetap jalan, nilainya ikut harga 1 jam yg baru (pelajar / biasa)
    $discount = intval($b['discount']) > 0 ? min(freeHourValue($b, (bool) $b['with_nintendo'], (bool) $student), $gross) : 0;
    $total = $gross - $discount;
    $stmt = $conn->prepare("UPDATE bookings SET is_student = ?, total_price = ?, discount = ? WHERE id = ?");
    $stmt->bind_param("iiii", $student, $total, $discount, $id);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("toggle_student failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Gagal mengubah harga"]);
    exit;
  }
  echo json_encode(["status" => "ok", "is_student" => (bool) $student, "total_price" => $total]);
  exit;
}

// ===== SIMPAN HARGA PAKET & PELAJAR =====
// packages: [{rate, prices: [1..5 jam]}], student: [{rate, p1, p3}] (JSON), diganti semua
// booking lama gak berubah, totalnya udah tersimpan
if ($action === 'save_prices') {
  $packages = json_decode($_POST['packages'] ?? '[]', true);
  $student  = json_decode($_POST['student'] ?? '[]', true);
  $money = fn($v) => is_numeric($v) && intval($v) == $v && $v > 0 && $v <= 10000000;
  $err = null;
  if (!is_array($packages) || !is_array($student)) $err = "Data harga tidak valid";
  $seen = [];
  foreach ($packages ?: [] as $p) {
    if ($err) break;
    $rate = $p['rate'] ?? null;
    $prices = $p['prices'] ?? null;
    if (!$money($rate) || !is_array($prices) || count($prices) !== PACKAGE_HOURS || count(array_filter($prices, $money)) !== PACKAGE_HOURS) {
      $err = "Paket Main Seru: tarif dan harga 1-" . PACKAGE_HOURS . " jam wajib diisi angka lebih dari 0";
    } elseif (isset($seen[intval($rate)])) {
      $err = "Tarif Rp" . number_format($rate, 0, ',', '.') . " muncul dua kali di Paket Main Seru";
    } else {
      $seen[intval($rate)] = true;
    }
  }
  $seenS = [];
  foreach ($student ?: [] as $p) {
    if ($err) break;
    if (!$money($p['rate'] ?? null) || !$money($p['p1'] ?? null) || !$money($p['p3'] ?? null)) {
      $err = "Paket Pelajar: tarif, harga 1 jam, dan 3 jam wajib diisi angka lebih dari 0";
    } elseif (isset($seenS[intval($p['rate'])])) {
      $err = "Tarif Rp" . number_format($p['rate'], 0, ',', '.') . " muncul dua kali di Paket Pelajar";
    } else {
      $seenS[intval($p['rate'])] = true;
    }
  }
  if ($err) {
    echo json_encode(["status" => "error", "message" => $err]);
    exit;
  }

  $conn->begin_transaction();
  try {
    $conn->query("DELETE FROM price_packages");
    $conn->query("DELETE FROM student_prices");
    $ins = $conn->prepare("INSERT INTO price_packages (rate, hours, price) VALUES (?, ?, ?)");
    foreach ($packages as $p) {
      $rate = intval($p['rate']);
      foreach (array_values($p['prices']) as $i => $price) {
        $h = $i + 1;
        $price = intval($price);
        $ins->bind_param("iii", $rate, $h, $price);
        $ins->execute();
      }
    }
    $ins->close();
    $ins = $conn->prepare("INSERT INTO student_prices (rate, price_1h, price_3h) VALUES (?, ?, ?)");
    foreach ($student as $p) {
      $rate = intval($p['rate']); $p1 = intval($p['p1']); $p3 = intval($p['p3']);
      $ins->bind_param("iii", $rate, $p1, $p3);
      $ins->execute();
    }
    $ins->close();
    $conn->commit();
  } catch (\Throwable $e) {
    $conn->rollback();
    error_log("save_prices failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Gagal menyimpan harga"]);
    exit;
  }
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== TUKAR POIN: GRATIS 1 JAM =====
if ($action === 'redeem_points') {
  $res = toggleRedeemPoints($conn, intval($_POST['id'] ?? 0));
  echo json_encode($res['ok']
    ? ["status" => "ok", "redeemed" => $res['redeemed']]
    : ["status" => "error", "message" => $res['message']]);
  exit;
}
// ===== HAPUS ULASAN =====
// buat ulasan spam / kasar
if ($action === 'delete_review') {
  $id = intval($_POST['id'] ?? 0);
  $stmt = $conn->prepare("DELETE FROM room_reviews WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== RESERVASI JAM TERTENTU (KONSUMEN OFFLINE) =====
if ($action === 'admin_reserve') {
  $name  = trim($_POST['name'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  if ($err = offlineCustomerError($name, $phone)) {
    echo json_encode(["status" => "error", "message" => $err]);
    exit;
  }
  $slot = scheduledStart(trim($_POST['date'] ?? ''), trim($_POST['time'] ?? ''));
  if (!$slot['ok']) {
    echo json_encode(["status" => "error", "message" => $slot['message']]);
    exit;
  }
  $res = createBooking($conn, [
    'room_id'        => intval($_POST['room_id'] ?? 0),
    'start'          => $slot['start'],
    'duration'       => intval($_POST['duration'] ?? 0),
    'customer_name'  => $name,
    'phone'          => $phone,
    'user_id'        => null,
    'with_nintendo'  => !empty($_POST['nintendo']),
    'is_student'     => !empty($_POST['student']),
    'source'         => 'walkin',
    'payment_status' => 'unpaid',
    'expires_at'     => $slot['start'] + BOOKING_CHECKIN_SEC,
  ]);
  echo json_encode($res['ok']
    ? ["status" => "ok"] + $res['booking']
    : ["status" => "error", "message" => $res['message']]);
  exit;
}

// ===== TAMBAH JAM SESI (KASIR) =====
if ($action === 'admin_extend') {
  $res = extendBookingCore($conn, $_POST['id'] ?? 0, $_POST['hours'] ?? 0, null);
  if (!$res['ok']) {
    // gak bisa diperpanjang: kasih tau kasir kalo ada ruangan sekelas kosong
    $alt = null;
    $stmt = $conn->prepare("
      SELECT b.id, b.start_time, b.end_time, b.duration, b.with_nintendo, b.is_student,
             r.id AS rid, r.tier, r.console_model, r.price, r.weekend_extra, r.nintendo_extra, r.max_players
      FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = ?
    ");
    $bid = intval($_POST['id'] ?? 0);
    $stmt->bind_param("i", $bid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
      $end = intval($row['end_time']);
      $alt = findEquivalentRoom($conn, ['id' => $row['rid']] + $row, (bool) $row['with_nintendo'],
                                $end, $end + intval($_POST['hours'] ?? 0) * 3600, [intval($row['rid'])]);
    }
    echo json_encode(["status" => "error", "message" => $res['message'], "alt_room" => $alt['title'] ?? null]);
    exit;
  }
  echo json_encode(["status" => "ok", "new_end" => $res['new_end'], "extra_cost" => $res['extra_cost'], "relocated" => $res['relocated']]);
  exit;
}

// ===== LANJUT DI RUANGAN SETARA (KASIR) =====
if ($action === 'continue_other_room') {
  $res = continueInOtherRoom($conn, intval($_POST['id'] ?? 0), intval($_POST['hours'] ?? 0), null);
  echo json_encode($res['ok'] ? ["status" => "ok"] + $res : ["status" => "error", "message" => $res['message']]);
  exit;
}

// ===== BATALKAN BOOKING (riwayat tetap ada) =====
if ($action === 'cancel_booking') {
  $id = intval($_POST['id'] ?? 0);
  // expires_at dikosongin biar gak kebaca batal karena gak dateng
  // sesi yg udah selesai gak bisa dibatalin biar pendapatan tetap bener
  $stmt = $conn->prepare("
    UPDATE bookings SET payment_status = 'cancelled', expires_at = NULL
    WHERE id = ? AND payment_status <> 'cancelled' AND end_time > UNIX_TIMESTAMP()
  ");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  if (!$ok) {
    echo json_encode(["status" => "error", "message" => "Booking sudah dibatalkan atau sesinya sudah selesai"]);
    exit;
  }
  processQueue($conn);
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== CHECK-IN KONSUMEN ONLINE =====
if ($action === 'checkin_booking') {
  $id = intval($_POST['id'] ?? 0);
  // check-in paling cepat 15 menit sebelum mulai
  $now = time();
  $earliest = $now + BOOKING_CHECKIN_SEC;
  // yg belum bayar & lewat batas dibatalin dulu
  // yg lunas tapi telat tetap boleh check-in selama jamnya belum habis
  releaseNoShows($conn, $now);
  $stmt = $conn->prepare("
    UPDATE bookings SET expires_at = NULL
    WHERE id = ? AND expires_at IS NOT NULL AND payment_status <> 'cancelled'
      AND start_time <= ? AND end_time > ?
  ");
  $stmt->bind_param("iii", $id, $earliest, $now);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  echo json_encode($ok
    ? ["status" => "ok"]
    : ["status" => "error", "message" => "Booking sudah check-in, dibatalkan karena tidak hadir, sudah lewat jam mainnya, atau jam mulainya masih lebih dari 15 menit lagi"]);
  exit;
}

// ===== WALK-IN: MASUK ANTRIAN =====
if ($action === 'walkin_join') {
  $name     = trim($_POST['name'] ?? '');
  $phone    = trim($_POST['phone'] ?? '');
  $class    = $_POST['room_class'] ?? '';
  $duration = intval($_POST['duration'] ?? 0);
  $roomId   = intval($_POST['room_id'] ?? 0);

  if ($err = offlineCustomerError($name, $phone)) {
    echo json_encode(["status" => "error", "message" => $err]);
    exit;
  }
  if ($duration < 1 || $duration > BOOKING_MAX_DURATION) {
    echo json_encode(["status" => "error", "message" => "Durasi harus 1-" . BOOKING_MAX_DURATION . " jam"]);
    exit;
  }

  $now = time();
  if ($now < openTs($now)) {
    echo json_encode(["status" => "error", "message" => "Belum jam buka. Layanan mulai jam " . secToHHMM(BOOKING_OPEN_SEC)]);
    exit;
  }
  if ($now + $duration * 3600 > closeTs($now)) {
    echo json_encode(["status" => "error", "message" => "Durasi melewati jam tutup (" . secToHHMM(BOOKING_CLOSE_SEC) . ")"]);
    exit;
  }

  // ruangan dipilih langsung: mulai sekarang tanpa antri
  if ($roomId > 0) {
    // bersihin antrian basi dulu
    processQueue($conn);
    $stmt = $conn->prepare("
      SELECT " . ROOM_CLASS_SQL . " AS room_class,
        (SELECT COUNT(*) FROM booking_queue q WHERE q.status = 'waiting' AND q.room_class = " . ROOM_CLASS_SQL . ") AS waiting
      FROM rooms r WHERE r.id = ?
    ");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$info) {
      echo json_encode(["status" => "error", "message" => "Ruangan tidak ditemukan"]);
      exit;
    }
    // jaga urutan: kalo ada yg nunggu konsol ini jangan disalip
    if (intval($info['waiting']) > 0) {
      echo json_encode(["status" => "error", "message" => "Masih ada {$info['waiting']} konsumen menunggu {$info['room_class']}. Pilih ruangan \"Otomatis\" agar sesuai urutan."]);
      exit;
    }
    $res = createBooking($conn, [
      'room_id'        => $roomId,
      'start'          => $now,
      'duration'       => $duration,
      'customer_name'  => $name,
      'phone'          => $phone,
      'user_id'        => null,
      'with_nintendo'  => !empty($_POST['nintendo']),
      'is_student'     => !empty($_POST['student']),
      'source'         => 'walkin',
      'payment_status' => 'paid',
      'expires_at'     => null,
    ]);
    echo json_encode($res['ok']
      ? ["status" => "ok", "assigned" => true, "room" => $res['booking']['room'], "total_price" => $res['booking']['total_price']]
      : ["status" => "error", "message" => $res['message']]);
    exit;
  }

  $withN   = !empty($_POST['nintendo']) ? 1 : 0;
  $student = !empty($_POST['student']) ? 1 : 0;
  if ($err = queueOptionError($conn, $class, $withN, $student)) {
    echo json_encode(["status" => "error", "message" => $err]);
    exit;
  }

  $phoneVal = $phone === '' ? null : $phone;
  $stmt = $conn->prepare("INSERT INTO booking_queue (source, customer_name, phone, room_class, duration, with_nintendo, is_student) VALUES ('walkin', ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("sssiii", $name, $phoneVal, $class, $duration, $withN, $student);
  $stmt->execute();
  $queueId = $stmt->insert_id;
  $stmt->close();

  processQueue($conn);

  $stmt = $conn->prepare("
    SELECT q.*, r.title AS room_title
    FROM booking_queue q
    LEFT JOIN bookings b ON b.id = q.booking_id
    LEFT JOIN rooms r    ON r.id = b.room_id
    WHERE q.id = ?
  ");
  $stmt->bind_param("i", $queueId);
  $stmt->execute();
  $entry = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$entry) {
    echo json_encode(["status" => "error", "message" => "Gagal memproses antrian"]);
  } elseif ($entry['status'] === 'assigned') {
    echo json_encode(["status" => "ok", "assigned" => true, "room" => $entry['room_title']]);
  } else {
    echo json_encode([
      "status"   => "ok",
      "assigned" => false,
      "position" => queuePosition($conn, $entry),
      "hint"     => walkinHint($conn, $class, $duration, $now, (bool) $withN),
    ]);
  }
  exit;
}

// ===== BATALKAN ANTRIAN =====
if ($action === 'queue_cancel') {
  $id = intval($_POST['id'] ?? 0);
  $stmt = $conn->prepare("UPDATE booking_queue SET status = 'cancelled' WHERE id = ? AND status = 'waiting'");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  processQueue($conn);
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== SELESAI LEBIH AWAL =====
if ($action === 'finish_booking') {
  $id  = intval($_POST['id'] ?? 0);
  $now = time();
  // biaya tetap, durasi tetap jam yg dibayar (poin ikut durasi, jadi gak boleh berubah)
  // cuma end_time yg dipotong biar ruangan langsung kosong. cuma sesi yg udah check-in
  $stmt = $conn->prepare("
    UPDATE bookings
    SET end_time = ?
    WHERE id = ? AND start_time <= ? AND end_time > ? AND payment_status <> 'cancelled' AND expires_at IS NULL
  ");
  $stmt->bind_param("iiii", $now, $id, $now, $now);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  if (!$ok) {
    echo json_encode(["status" => "error", "message" => "Sesi tidak sedang berjalan atau konsumen belum ditandai hadir"]);
    exit;
  }
  processQueue($conn);
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== LEPAS RUANGAN: LUNAS TAPI GAK DATENG =====
// booking lunas gak pernah batal otomatis, kasir yg lepas
// sesi diakhiri sekarang, bayaran tetap tercatat, expires_at tetap sebagai tanda gak dateng
if ($action === 'release_noshow') {
  $id  = intval($_POST['id'] ?? 0);
  $now = time();
  $stmt = $conn->prepare("
    UPDATE bookings
    SET end_time = ?, duration = GREATEST(1, CEIL((? - start_time) / 3600))
    WHERE id = ? AND payment_status = 'paid' AND expires_at IS NOT NULL AND expires_at < ?
      AND start_time <= ? AND end_time > ?
  ");
  $stmt->bind_param("iiiiii", $now, $now, $id, $now, $now, $now);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  if (!$ok) {
    echo json_encode(["status" => "error", "message" => "Booking sudah check-in, sudah selesai, atau batas kedatangannya belum lewat"]);
    exit;
  }
  processQueue($conn);
  echo json_encode(["status" => "ok"]);
  exit;
}

// baca & validasi form ruangan (tambah/edit), return [data, error]
const ROOM_CATEGORIES = ['private', 'private_pro', 'public'];
const ROOM_TIERS      = ['VVIP', 'VIP', 'Public'];
function roomInput() {
  $nExtra = trim($_POST['nintendo_extra'] ?? '');
  $d = [
    'title'          => trim($_POST['title'] ?? ''),
    'category'       => $_POST['category'] ?? '',
    'tier'           => $_POST['tier'] ?? '',
    'console_type'   => $_POST['console'] ?? '',
    'console_model'  => trim($_POST['console_model'] ?? ''),
    'price'          => intval($_POST['price'] ?? 0),
    'weekend_extra'  => intval($_POST['weekend_extra'] ?? 0),
    'nintendo_extra' => $nExtra === '' ? null : intval($nExtra),
    'max_players'    => intval($_POST['max_players'] ?? 2),
    'has_netflix'    => !empty($_POST['has_netflix']) ? 1 : 0,
    'description'    => trim($_POST['description'] ?? ''),
  ];
  if ($d['title'] === '' || mb_strlen($d['title']) > 100) return [null, "Nama ruangan wajib diisi (maks 100 karakter)"];
  if (!in_array($d['category'], ROOM_CATEGORIES, true) || !in_array($d['tier'], ROOM_TIERS, true)) return [null, "Kategori atau kelas ruangan tidak valid"];
  // kelas Public cuma buat kategori public (dan sebaliknya) biar antrian rapi
  if (($d['category'] === 'public') !== ($d['tier'] === 'Public')) return [null, "Kategori Public harus memakai kelas Public, kategori Private memakai VIP/VVIP"];
  if (!in_array($d['console_type'], CONSOLE_TYPES, true)) return [null, "Jenis konsol tidak valid"];
  if ($d['console_model'] === '' || mb_strlen($d['console_model']) > 30) return [null, "Model konsol wajib diisi (maks 30 karakter)"];
  if ($d['price'] <= 0 || $d['weekend_extra'] < 0 || ($d['nintendo_extra'] !== null && $d['nintendo_extra'] <= 0)) return [null, "Harga tidak valid"];
  if ($d['max_players'] < 1 || $d['max_players'] > 8) return [null, "Maksimal pemain harus 1-8"];
  if (mb_strlen($d['description']) > 255) return [null, "Keterangan maksimal 255 karakter"];
  return [$d, null];
}

// ===== TAMBAH RUANGAN =====
if ($action === 'add_room') {
  [$d, $err] = roomInput();
  if ($err) {
    echo json_encode(["status" => "error", "message" => $err]);
    exit;
  }
  $stmt = $conn->prepare("
    INSERT INTO rooms (title, category, tier, console_type, console_model, price, weekend_extra, nintendo_extra, max_players, has_netflix, description, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
  ");
  $stmt->bind_param("sssssiiiiis", $d['title'], $d['category'], $d['tier'], $d['console_type'], $d['console_model'],
    $d['price'], $d['weekend_extra'], $d['nintendo_extra'], $d['max_players'], $d['has_netflix'], $d['description']);
  $stmt->execute();
  $stmt->close();

  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== EDIT RUANGAN =====
// harga baru cuma buat booking baru
if ($action === 'update_room') {
  $id = intval($_POST['id'] ?? 0);
  [$d, $err] = roomInput();
  if ($id <= 0 || $err) {
    echo json_encode(["status" => "error", "message" => $err ?? "Ruangan tidak valid"]);
    exit;
  }
  $stmt = $conn->prepare("
    UPDATE rooms SET title = ?, category = ?, tier = ?, console_type = ?, console_model = ?, price = ?,
      weekend_extra = ?, nintendo_extra = ?, max_players = ?, has_netflix = ?, description = ?
    WHERE id = ?
  ");
  $stmt->bind_param("sssssiiiiisi", $d['title'], $d['category'], $d['tier'], $d['console_type'], $d['console_model'],
    $d['price'], $d['weekend_extra'], $d['nintendo_extra'], $d['max_players'], $d['has_netflix'], $d['description'], $id);
  $stmt->execute();
  $stmt->close();

  // konsol diganti: buang game yg gak bisa dimainin di konsol baru
  $stmt = $conn->prepare("
    DELETE rg FROM room_games rg
    WHERE rg.room_id = ?
      AND NOT EXISTS (SELECT 1 FROM game_consoles gc WHERE gc.game_id = rg.game_id AND gc.console_type = ?)
  ");
  $stmt->bind_param("is", $id, $d['console_type']);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== GAME PER RUANGAN =====
// daftar kosong = pakai semua game konsolnya (liat rooms_api.php)
if ($action === 'update_room_games') {
  $id = intval($_POST['id'] ?? 0);
  $gameIds = array_values(array_unique(array_map('intval', (array) ($_POST['game_ids'] ?? []))));

  $stmt = $conn->prepare("SELECT console_type FROM rooms WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $room = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$room) {
    echo json_encode(["status" => "error", "message" => "Ruangan tidak ditemukan"]);
    exit;
  }

  $conn->begin_transaction();
  try {
    $del = $conn->prepare("DELETE FROM room_games WHERE room_id = ?");
    $del->bind_param("i", $id);
    $del->execute();
    $del->close();

    // cuma simpan game yg cocok sama konsol ruangan
    $ins = $conn->prepare("
      INSERT INTO room_games (room_id, game_id)
      SELECT ?, gc.game_id FROM game_consoles gc WHERE gc.game_id = ? AND gc.console_type = ?
    ");
    foreach ($gameIds as $gid) {
      $ins->bind_param("iis", $id, $gid, $room['console_type']);
      $ins->execute();
    }
    $ins->close();
    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    error_log("Update room games failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Gagal menyimpan daftar game"]);
    exit;
  }
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== TOGGLE RUANGAN =====
if ($action === 'toggle_room') {
  $id = intval($_POST['id'] ?? 0);
  $stmt = $conn->prepare("
    UPDATE rooms SET status = IF(status='available','unavailable','available') WHERE id = ?
  ");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  processQueue($conn);
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== PINDAHIN RESERVASI KE RUANGAN SETARA =====
// 1 booking (dari Jadwal) atau semua reservasi di 1 ruangan (pas set perawatan)
// konsumen dapat info pindah di profil
if ($action === 'relocate_booking') {
  $res = relocateBooking($conn, intval($_POST['id'] ?? 0));
  echo json_encode($res['ok'] ? ["status" => "ok", "room" => $res['room']] : ["status" => "error", "message" => $res['message']]);
  exit;
}

if ($action === 'relocate_room_bookings') {
  $roomId = intval($_POST['id'] ?? 0);
  $stmt = $conn->prepare("
    SELECT id, customer_name, start_time FROM bookings
    WHERE room_id = ? AND payment_status <> 'cancelled' AND expires_at IS NOT NULL AND end_time > UNIX_TIMESTAMP()
    ORDER BY start_time
  ");
  $stmt->bind_param("i", $roomId);
  $stmt->execute();
  $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  $moved = [];
  $failed = [];
  foreach ($list as $b) {
    $res = relocateBooking($conn, $b['id']);
    $label = $b['customer_name'] . ' (' . date('d/m H:i', intval($b['start_time'])) . ')';
    if ($res['ok']) $moved[] = "$label -> {$res['room']}";
    else $failed[] = $label;
  }
  echo json_encode(["status" => "ok", "moved" => $moved, "failed" => $failed]);
  exit;
}

// ===== RESET PASSWORD AKUN =====
// buat konsumen yg lupa password lama, password sementara cuma muncul sekali
// sesi lama akun itu otomatis keluar (passwordSig di db.php)
if ($action === 'reset_password') {
  $id = intval($_POST['id'] ?? 0);
  if ($id === intval($_SESSION['admin_id'])) {
    echo json_encode(["status" => "error", "message" => "Ganti password akun sendiri lewat menu Lupa Password"]);
    exit;
  }
  // tanpa huruf mirip (0/O, 1/l/I) biar gampang didikte
  $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $temp = '';
  for ($i = 0; $i < 8; $i++) $temp .= $chars[random_int(0, strlen($chars) - 1)];
  $hash = password_hash($temp, PASSWORD_DEFAULT);

  $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
  $stmt->bind_param("si", $hash, $id);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  if (!$ok) {
    echo json_encode(["status" => "error", "message" => "Akun tidak ditemukan"]);
    exit;
  }
  echo json_encode(["status" => "ok", "temp_password" => $temp]);
  exit;
}

if ($action === 'toggle_role') {
  $id = intval($_POST['id'] ?? 0);
  if ($id === intval($_SESSION['admin_id'])) {
    echo json_encode(["status" => "error", "message" => "Tidak bisa mengubah role akun sendiri"]);
    exit;
  }
  // kunci baris admin biar 2 admin gak saling nurunin role sampai gak ada admin
  $conn->begin_transaction();
  $adminCount = count($conn->query("SELECT id FROM users WHERE role = 'admin' FOR UPDATE")->fetch_all());
  $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? FOR UPDATE");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $target = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$target) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Akun tidak ditemukan"]);
    exit;
  }
  if ($target['role'] === 'admin' && $adminCount <= 1) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Minimal harus ada satu admin"]);
    exit;
  }
  $stmt = $conn->prepare("UPDATE users SET role = IF(role='user','admin','user') WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  $conn->commit();
  echo json_encode(["status" => "ok"]);
  exit;
}

// ===== TAMBAH GAME =====
if ($action === 'add_game') {
  $title   = trim($_POST['title'] ?? '');
  $genre   = trim($_POST['genre'] ?? '');
  $consoles = array_filter((array) ($_POST["consoles"] ?? []), function($c) {
    return in_array($c, CONSOLE_TYPES, true);
  });

  if ($title === '' || $genre === '' || empty($consoles)) {
    echo json_encode(["status" => "error", "message" => "Lengkapi data game & pilih console"]);
    exit;
  }
  if (mb_strlen($title) > 100 || mb_strlen($genre) > 50) {
    echo json_encode(["status" => "error", "message" => "Judul maksimal 100 karakter, genre maksimal 50 karakter"]);
    exit;
  }

  $imageName = saveUploadedImage('cover_image', 'games', 'game_');

  $conn->begin_transaction();

  try {
    $stmt = $conn->prepare("INSERT INTO games (title, genre, image, is_active) VALUES (?, ?, ?, 1)");
    $stmt->bind_param("sss", $title, $genre, $imageName);
    $stmt->execute();
    $gameId = $stmt->insert_id;
    $stmt->close();

    $stmt2 = $conn->prepare("INSERT INTO game_consoles (game_id, console_type) VALUES (?, ?)");
    foreach ($consoles as $c) {
      $stmt2->bind_param("is", $gameId, $c);
      $stmt2->execute();
    }
    $stmt2->close();

    $conn->commit();
    echo json_encode([
      "status"      => "ok",
      "image_saved" => !uploadAttempted('cover_image') || $imageName !== null,
    ]);
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    removeUploadedImage('games', $imageName);
    error_log("Add game failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Gagal menyimpan game"]);
    exit;
  }
}

// ===== HAPUS GAME =====
if ($action === 'delete_game') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT image FROM games WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $img = $stmt->get_result()->fetch_assoc()['image'] ?? null;
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM games WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    // hapus file cover juga biar gak numpuk
    if ($deleted) removeUploadedImage('games', $img);
    echo json_encode(["status" => "ok"]);
    exit;
}

// ===== UPDATE KONSOL GAME =====
if ($action === 'update_game_consoles') {
    $id = intval($_POST['id'] ?? 0);
    $consoles = array_filter((array) ($_POST["consoles"] ?? []), fn($c) => in_array($c, CONSOLE_TYPES, true));

    if ($id <= 0 || empty($consoles)) {
        echo json_encode(["status" => "error", "message" => "Data tidak valid"]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM game_consoles WHERE game_id = ?");
        $del->bind_param("i", $id);
        $del->execute();
        $del->close();

        $ins = $conn->prepare("INSERT INTO game_consoles (game_id, console_type) VALUES (?, ?)");
        foreach ($consoles as $c) {
            $ins->bind_param("is", $id, $c);
            $ins->execute();
        }
        $ins->close();

        // konsol dicabut: keluarin game dari ruangan yg konsolnya gak didukung
        $clean = $conn->prepare("
          DELETE rg FROM room_games rg JOIN rooms r ON r.id = rg.room_id
          WHERE rg.game_id = ?
            AND NOT EXISTS (SELECT 1 FROM game_consoles gc WHERE gc.game_id = rg.game_id AND gc.console_type = r.console_type)
        ");
        $clean->bind_param("i", $id);
        $clean->execute();
        $clean->close();

        $conn->commit();
        echo json_encode(["status" => "ok"]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Update game consoles failed: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "Gagal update"]);
    }
    exit;
}

// ===== PESANAN SELESAI =====
if ($action === 'mark_order_done') {
    $id = intval($_POST['id'] ?? 0);
    // pesanan batal gak boleh jadi selesai
    $stmt = $conn->prepare("UPDATE menu_orders SET status = 'selesai' WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    echo json_encode($ok ? ["status" => "ok"] : ["status" => "error", "message" => "Pesanan sudah selesai atau dibatalkan"]);
    exit;
}

// ===== BATALKAN PESANAN MENU =====
// cth stok habis / salah pesan, cuma yg belum diantar
if ($action === 'cancel_menu_order') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE menu_orders SET status = 'batal' WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    echo json_encode($ok ? ["status" => "ok"] : ["status" => "error", "message" => "Pesanan sudah selesai atau sudah dibatalkan"]);
    exit;
}

// ===== TAMBAH MENU =====
if ($action === 'add_menu_item') {
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $price       = intval($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 255
        || !in_array($category, ['makanan', 'snack', 'minuman'], true) || $price <= 0) {
        echo json_encode(["status" => "error", "message" => "Data tidak lengkap atau tidak valid (nama maks 100, deskripsi maks 255 karakter)"]);
        exit;
    }

    $imageName = saveUploadedImage('item_image', 'menu', 'menu_');

    try {
        $stmt = $conn->prepare("INSERT INTO menu_items (name, category, price, description, image) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiss", $name, $category, $price, $description, $imageName);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        removeUploadedImage('menu', $imageName);
        error_log("Add menu item failed: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "Gagal menyimpan item menu"]);
        exit;
    }

    echo json_encode([
        "status"      => "ok",
        "image_saved" => !uploadAttempted('item_image') || $imageName !== null,
    ]);
    exit;
}

// ===== TOGGLE MENU =====
if ($action === 'toggle_menu_item') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE menu_items SET is_available = IF(is_available=1,0,1) WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "ok"]);
    exit;
}

// ===== EDIT MENU =====
// pesanan lama tetap pakai harga pas dipesan (unit_price)
if ($action === 'update_menu_item') {
    $id          = intval($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $price       = intval($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($id <= 0 || $name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 255 || !in_array($category, ['makanan', 'snack', 'minuman'], true) || $price <= 0) {
        echo json_encode(["status" => "error", "message" => "Data menu tidak lengkap atau tidak valid"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT image FROM menu_items WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old) {
        echo json_encode(["status" => "error", "message" => "Item menu tidak ditemukan"]);
        exit;
    }

    // gambar cuma diganti kalo ada file baru
    $newImage = saveUploadedImage('item_image', 'menu', 'menu_');
    $image = $newImage ?? $old['image'];

    try {
        $stmt = $conn->prepare("UPDATE menu_items SET name = ?, category = ?, price = ?, description = ?, image = ? WHERE id = ?");
        $stmt->bind_param("ssissi", $name, $category, $price, $description, $image, $id);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        removeUploadedImage('menu', $newImage);
        error_log("Update menu item failed: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "Gagal menyimpan perubahan menu"]);
        exit;
    }

    if ($newImage) removeUploadedImage('menu', $old['image']);
    echo json_encode([
        "status"      => "ok",
        "image_saved" => !uploadAttempted('item_image') || $newImage !== null,
    ]);
    exit;
}

// ===== HAPUS MENU =====
if ($action === 'delete_menu_item') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT image FROM menu_items WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $img = $stmt->get_result()->fetch_assoc()['image'] ?? null;
    $stmt->close();
    try {
        $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        if ($deleted) removeUploadedImage('menu', $img);
        echo json_encode(["status" => "ok"]);
    } catch (\mysqli_sql_exception $e) {
        // masih ada histori pesanan (FK RESTRICT), nonaktifin aja
        echo json_encode(["status" => "error", "message" => "Item ini masih punya histori pesanan, tidak bisa dihapus. Gunakan tombol nonaktifkan (stok habis) sebagai gantinya."]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Action tidak dikenal"]);
exit;
