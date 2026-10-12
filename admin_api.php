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

// Token CSRF dari halaman admin (meta csrf-token), cegah aksi yang dipicu situs lain
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
  echo json_encode(["status" => "unauthorized", "message" => "Sesi halaman kedaluwarsa, halaman akan dimuat ulang"]);
  exit;
}

$action = $_POST['action'] ?? '';

// Simpan gambar upload ke assets/images/{subdir}. Return nama file, atau null bila
// tidak ada file / gagal. File dicek benar-benar gambar (bukan cuma ekstensi), maks 2 MB.
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

// Hapus file gambar upload (mis. INSERT gagal setelah upload, atau item dihapus)
function removeUploadedImage($subdir, $name) {
  if (!$name) return;
  $path = __DIR__ . '/assets/images/' . $subdir . '/' . basename($name);
  if (is_file($path)) unlink($path);
}

// Validasi nama & HP konsumen offline. Return pesan error atau null.
function offlineCustomerError($name, $phone) {
  if ($name === '') return "Nama konsumen wajib diisi";
  if (mb_strlen($name) > 100) return "Nama konsumen maksimal 100 karakter";
  if ($phone !== '' && !preg_match('/^[0-9]{9,14}$/', $phone)) return "Nomor HP tidak valid (hanya angka, 9-14 digit)";
  return null;
}

// Penjelasan kenapa walk-in masuk daftar tunggu padahal ada ruangan kosong:
// ruangan kosong sekarang tapi ada reservasi sebelum durasi yang diminta selesai.
// Antrian dengan tambahan Nintendo hanya melihat ruangan yang menyediakan Nintendo.
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

// ================= TANDAI LUNAS (bayar di kasir) =================
if ($action === 'mark_paid') {
  $id = intval($_POST['id'] ?? 0);
  $now = time();
  // Membayar di kasir saat sesi sudah dekat/berjalan berarti konsumen sudah datang:
  // sekalian dianggap check-in (expires_at dikosongkan)
  $checkinFrom = $now + BOOKING_CHECKIN_SEC;
  // Booking yang sudah lewat batas check-in dibatalkan dulu, jadi tidak bisa dilunasi
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
    // Waktu tiap pembayaran dicatat di booking_payments (recordPayment di bawah)
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

// ================= HARGA PELAJAR (kasir cek kartu pelajar) =================
// Pasang/lepas harga Paket Pelajar. Hanya sebelum ada pembayaran, supaya uang yang sudah
// diterima tidak perlu dikembalikan sebagian.
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
    // Potongan dari penukaran poin tetap berlaku
    $total = max(0, bookingPrice($b, intval($b['duration']), intval($b['start_time']), (bool) $b['with_nintendo'], (bool) $student)
                    - intval($b['discount']));
    $stmt = $conn->prepare("UPDATE bookings SET is_student = ?, total_price = ? WHERE id = ?");
    $stmt->bind_param("iii", $student, $total, $id);
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

// ================= TUKAR POIN: GRATIS 1 JAM =================
// Pasang/lepas potongan 1 jam (tarif per jam ruangan) dengan 10 poin milik akun pemesan.
// Hanya untuk booking yang belum lunas, supaya uang yang sudah diterima tidak berubah.
if ($action === 'redeem_points') {
  $id = intval($_POST['id'] ?? 0);
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
    if (!$b || $b['payment_status'] === 'cancelled') $error = "Booking tidak ditemukan atau sudah dibatalkan";
    elseif ($b['payment_status'] === 'paid') $error = "Booking sudah lunas, poin tidak bisa dipakai/dikembalikan lagi";
    elseif ($b['user_id'] === null) $error = "Poin hanya untuk booking dari akun website";
    if ($error) {
      $conn->rollback();
      echo json_encode(["status" => "error", "message" => $error]);
      exit;
    }
    $uid = intval($b['user_id']);
    // Kunci baris user supaya poin tidak terpakai dua kali oleh dua booking bersamaan
    $lock = $conn->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
    $lock->bind_param("i", $uid);
    $lock->execute();
    $lock->get_result();
    $lock->close();

    if (intval($b['discount']) > 0) {
      // Batal pakai poin: harga kembali normal, 10 poin dikembalikan
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
        echo json_encode(["status" => "error", "message" => "Poin belum cukup (butuh " . POINTS_PER_FREE_HOUR . " poin)"]);
        exit;
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
    error_log("redeem_points failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Gagal memproses poin"]);
    exit;
  }
  echo json_encode(["status" => "ok", "redeemed" => $redeemed]);
  exit;
}

// ================= HAPUS ULASAN =================
// Untuk ulasan yang tidak pantas (spam, kata kasar)
if ($action === 'delete_review') {
  $id = intval($_POST['id'] ?? 0);
  $stmt = $conn->prepare("DELETE FROM room_reviews WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "ok"]);
  exit;
}

// ================= RESERVASI JAM TERTENTU UNTUK KONSUMEN OFFLINE =================
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

// ================= TAMBAH JAM SESI (oleh admin) =================
if ($action === 'admin_extend') {
  $res = extendBookingCore($conn, $_POST['id'] ?? 0, $_POST['hours'] ?? 0, null);
  echo json_encode($res['ok']
    ? ["status" => "ok", "new_end" => $res['new_end'], "extra_cost" => $res['extra_cost']]
    : ["status" => "error", "message" => $res['message']]);
  exit;
}

// ================= BATALKAN BOOKING (soft cancel, riwayat tetap tersimpan) =================
if ($action === 'cancel_booking') {
  $id = intval($_POST['id'] ?? 0);
  // expires_at dikosongkan supaya tidak terbaca sebagai "batal karena tidak hadir".
  // Sesi yang sudah selesai tidak bisa dibatalkan agar riwayat pendapatan tetap benar.
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

// ================= KONSUMEN ONLINE SUDAH DATANG (CHECK-IN) =================
if ($action === 'checkin_booking') {
  $id = intval($_POST['id'] ?? 0);
  // Check-in paling cepat 15 menit sebelum jam mulai (bukan untuk reservasi hari lain)
  $now = time();
  $earliest = $now + BOOKING_CHECKIN_SEC;
  // Booking belum bayar yang lewat batas check-in dibatalkan dulu (tidak bisa dihidupkan lagi).
  // Booking lunas yang terlambat tetap boleh check-in selama jam mainnya belum habis.
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

// ================= WALK-IN: MASUK ANTRIAN =================
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

  // Ruangan dipilih langsung: mulai sekarang di ruangan itu, tanpa lewat antrian
  if ($roomId > 0) {
    // Bersihkan antrian basi dulu supaya tidak salah dihitung sebagai "masih menunggu"
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
    // Jaga keadilan urutan: kalau ada yang menunggu konsol ini, jangan disalip
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

// ================= BATALKAN ANTRIAN =================
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

// ================= SELESAIKAN SESI LEBIH AWAL =================
if ($action === 'finish_booking') {
  $id  = intval($_POST['id'] ?? 0);
  $now = time();
  // Biaya tetap; durasi dicatat sesuai jam main aktual (dibulatkan ke atas).
  // Hanya sesi yang konsumennya sudah check-in (expires_at NULL).
  $stmt = $conn->prepare("
    UPDATE bookings
    SET end_time = ?, duration = GREATEST(1, CEIL((? - start_time) / 3600))
    WHERE id = ? AND start_time <= ? AND end_time > ? AND payment_status <> 'cancelled' AND expires_at IS NULL
  ");
  $stmt->bind_param("iiiii", $now, $now, $id, $now, $now);
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

// ================= LEPAS RUANGAN: KONSUMEN LUNAS TIDAK DATANG =================
// Booking lunas tidak pernah dibatalkan otomatis. Bila batas check-in sudah lewat dan
// konsumen tidak datang, admin melepas ruangan: sesi diakhiri sekarang, pembayaran tetap
// tercatat, dan expires_at tetap terisi sebagai tanda tidak hadir.
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

// Baca & validasi isian form ruangan (tambah/edit). Return [data, pesan error].
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
  // Kelas "Public" hanya untuk kategori public (dan sebaliknya) supaya kelas antrian tetap rapi
  if (($d['category'] === 'public') !== ($d['tier'] === 'Public')) return [null, "Kategori Public harus memakai kelas Public, kategori Private memakai VIP/VVIP"];
  if (!in_array($d['console_type'], CONSOLE_TYPES, true)) return [null, "Jenis konsol tidak valid"];
  if ($d['console_model'] === '' || mb_strlen($d['console_model']) > 30) return [null, "Model konsol wajib diisi (maks 30 karakter)"];
  if ($d['price'] <= 0 || $d['weekend_extra'] < 0 || ($d['nintendo_extra'] !== null && $d['nintendo_extra'] <= 0)) return [null, "Harga tidak valid"];
  if ($d['max_players'] < 1 || $d['max_players'] > 8) return [null, "Maksimal pemain harus 1-8"];
  if (mb_strlen($d['description']) > 255) return [null, "Keterangan maksimal 255 karakter"];
  return [$d, null];
}

// ================= ADD ROOM =================
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

// ================= EDIT ROOM =================
// Harga baru hanya berlaku untuk booking baru (total_price booking lama sudah tersimpan)
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

  // Konsol ruangan diganti: buang game di daftar ruangan yang tidak bisa dimainkan di konsol baru
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

// ================= GAME PER RUANGAN =================
// Daftar kosong = ruangan memakai semua game untuk konsolnya (lihat rooms_api.php)
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

    // Hanya game yang bisa dimainkan di konsol ruangan ini yang disimpan
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

// ================= TOGGLE ROOM =================
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

// ================= TOGGLE ROLE =================
// ================= RESET PASSWORD AKUN =================
// Untuk konsumen yang lupa password lama (Lupa Password di website butuh password lama).
// Password sementara ditampilkan sekali ke admin; sesi lama akun itu otomatis keluar
// karena sidik password berubah (lihat passwordSig di db.php).
if ($action === 'reset_password') {
  $id = intval($_POST['id'] ?? 0);
  if ($id === intval($_SESSION['admin_id'])) {
    echo json_encode(["status" => "error", "message" => "Ganti password akun sendiri lewat menu Lupa Password"]);
    exit;
  }
  // Tanpa karakter yang mirip (0/O, 1/l/I) supaya mudah didiktekan ke konsumen
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
  // Baris admin dikunci supaya dua admin yang saling menurunkan role bersamaan
  // tidak membuat sistem tanpa admin sama sekali.
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

// ================= ADD GAME =================
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

// ================= DELETE GAME =================
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

    // Hapus juga file cover-nya supaya tidak menumpuk di folder upload
    if ($deleted) removeUploadedImage('games', $img);
    echo json_encode(["status" => "ok"]);
    exit;
}

// ================= UPDATE GAME CONSOLES =================
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

        // Konsol dicabut: game ini keluar dari daftar ruangan yang konsolnya tidak lagi didukung
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

// ================= MARK ORDER DONE =================
if ($action === 'mark_order_done') {
    $id = intval($_POST['id'] ?? 0);
    // Pesanan yang sudah dibatalkan tidak boleh berubah jadi "selesai"
    $stmt = $conn->prepare("UPDATE menu_orders SET status = 'selesai' WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    echo json_encode($ok ? ["status" => "ok"] : ["status" => "error", "message" => "Pesanan sudah selesai atau dibatalkan"]);
    exit;
}

// ================= BATALKAN PESANAN MENU =================
// Mis. stok habis atau konsumen salah pesan. Hanya pesanan yang belum diantar.
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

// ================= ADD MENU ITEM =================
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

// ================= TOGGLE MENU ITEM =================
if ($action === 'toggle_menu_item') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE menu_items SET is_available = IF(is_available=1,0,1) WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "ok"]);
    exit;
}

// ================= EDIT MENU ITEM =================
// Pesanan lama tetap memakai harga saat dipesan (menu_orders.unit_price)
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

    // Gambar hanya diganti bila admin memilih file baru
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

// ================= DELETE MENU ITEM =================
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
        // Item masih punya histori pesanan (FK RESTRICT) — nonaktifkan saja, jangan hapus.
        echo json_encode(["status" => "error", "message" => "Item ini masih punya histori pesanan, tidak bisa dihapus. Gunakan tombol nonaktifkan (stok habis) sebagai gantinya."]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Action tidak dikenal"]);
exit;
