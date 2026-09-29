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

$action = $_POST['action'] ?? '';

// Validasi nama & HP konsumen offline. Return pesan error atau null.
function offlineCustomerError($name, $phone) {
  if ($name === '') return "Nama konsumen wajib diisi";
  if ($phone !== '' && !preg_match('/^[0-9]{9,14}$/', $phone)) return "Nomor HP tidak valid (hanya angka, 9-14 digit)";
  return null;
}

// Penjelasan kenapa walk-in masuk daftar tunggu padahal ada ruangan kosong:
// ruangan kosong sekarang tapi ada reservasi sebelum durasi yang diminta selesai.
function walkinHint($conn, $console, $duration, $now) {
  $stmt = $conn->prepare("
    SELECT r.title,
      (SELECT MIN(b.start_time) FROM bookings b
        WHERE b.room_id = r.id AND b.payment_status <> 'cancelled' AND b.start_time > ?) AS next_start
    FROM rooms r
    WHERE r.console_type = ? AND r.status = 'available'
      AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.room_id = r.id AND b.payment_status <> 'cancelled'
                      AND b.start_time <= ? AND b.end_time > ?)
  ");
  $stmt->bind_param("isii", $now, $console, $now, $now);
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
  $stmt = $conn->prepare("UPDATE bookings SET payment_status = 'paid', paid_at = ? WHERE id = ? AND payment_status IN ('unpaid', 'pending')");
  $stmt->bind_param("ii", $now, $id);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  echo json_encode($ok
    ? ["status" => "ok"]
    : ["status" => "error", "message" => "Booking sudah lunas atau sudah dibatalkan"]);
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
  $earliest = time() + BOOKING_CHECKIN_SEC;
  $stmt = $conn->prepare("
    UPDATE bookings SET expires_at = NULL
    WHERE id = ? AND expires_at IS NOT NULL AND payment_status <> 'cancelled' AND start_time <= ?
  ");
  $stmt->bind_param("ii", $id, $earliest);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  echo json_encode($ok
    ? ["status" => "ok"]
    : ["status" => "error", "message" => "Booking sudah check-in, dibatalkan, atau jam mulainya masih lebih dari 15 menit lagi"]);
  exit;
}

// ================= WALK-IN: MASUK ANTRIAN =================
if ($action === 'walkin_join') {
  $name     = trim($_POST['name'] ?? '');
  $phone    = trim($_POST['phone'] ?? '');
  $console  = $_POST['console_type'] ?? '';
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
  if (intval(date('G', $now)) < BOOKING_OPEN_HOUR) {
    echo json_encode(["status" => "error", "message" => "Belum jam buka. Layanan mulai jam 11:00"]);
    exit;
  }
  if ($now + $duration * 3600 > queueCloseLimit($now)) {
    echo json_encode(["status" => "error", "message" => "Durasi melewati jam tutup (tengah malam)"]);
    exit;
  }

  // Ruangan dipilih langsung: mulai sekarang di ruangan itu, tanpa lewat antrian
  if ($roomId > 0) {
    $stmt = $conn->prepare("
      SELECT r.console_type,
        (SELECT COUNT(*) FROM booking_queue q WHERE q.status = 'waiting' AND q.console_type = r.console_type) AS waiting
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
      echo json_encode(["status" => "error", "message" => "Masih ada {$info['waiting']} konsumen menunggu {$info['console_type']}. Pilih ruangan \"Otomatis\" agar sesuai urutan."]);
      exit;
    }
    $res = createBooking($conn, [
      'room_id'        => $roomId,
      'start'          => $now,
      'duration'       => $duration,
      'customer_name'  => $name,
      'phone'          => $phone,
      'user_id'        => null,
      'source'         => 'walkin',
      'payment_status' => 'paid',
      'expires_at'     => null,
    ]);
    echo json_encode($res['ok']
      ? ["status" => "ok", "assigned" => true, "room" => $res['booking']['room'], "total_price" => $res['booking']['total_price']]
      : ["status" => "error", "message" => $res['message']]);
    exit;
  }

  if (!in_array($console, QUEUE_CONSOLES, true)) {
    echo json_encode(["status" => "error", "message" => "Pilih konsol terlebih dahulu"]);
    exit;
  }

  $phoneVal = $phone === '' ? null : $phone;
  $stmt = $conn->prepare("INSERT INTO booking_queue (source, customer_name, phone, console_type, duration) VALUES ('walkin', ?, ?, ?, ?)");
  $stmt->bind_param("sssi", $name, $phoneVal, $console, $duration);
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
      "hint"     => walkinHint($conn, $console, $duration, $now),
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
  // Biaya tetap; durasi dicatat sesuai jam main aktual (dibulatkan ke atas)
  $stmt = $conn->prepare("
    UPDATE bookings
    SET end_time = ?, duration = GREATEST(1, CEIL((? - start_time) / 3600)), expires_at = NULL
    WHERE id = ? AND start_time <= ? AND end_time > ? AND payment_status <> 'cancelled'
  ");
  $stmt->bind_param("iiiii", $now, $now, $id, $now, $now);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  if (!$ok) {
    echo json_encode(["status" => "error", "message" => "Sesi tidak sedang berjalan"]);
    exit;
  }
  processQueue($conn);
  echo json_encode(["status" => "ok"]);
  exit;
}

// ================= ADD ROOM =================
if ($action === 'add_room') {
  $title       = trim($_POST['title'] ?? '');
  $console     = trim($_POST['console'] ?? '');
  $price       = intval($_POST['price'] ?? 0);
  $description = trim($_POST['description'] ?? '');

  if ($title === '' || !in_array($console, QUEUE_CONSOLES, true) || $price <= 0) {
    echo json_encode(["status" => "error", "message" => "Data room tidak lengkap atau tidak valid"]);
    exit;
  }

  $stmt = $conn->prepare("
    INSERT INTO rooms (title, console_type, price, description, status)
    VALUES (?, ?, ?, ?, 'available')
  ");
  $stmt->bind_param("ssis", $title, $console, $price, $description);
  $stmt->execute();
  $stmt->close();

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
if ($action === 'toggle_role') {
  $id = intval($_POST['id'] ?? 0);
  if ($id === intval($_SESSION['admin_id'])) {
    echo json_encode(["status" => "error", "message" => "Tidak bisa mengubah role akun sendiri"]);
    exit;
  }
  $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $target = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$target) {
    echo json_encode(["status" => "error", "message" => "Akun tidak ditemukan"]);
    exit;
  }
  if ($target['role'] === 'admin') {
    $adminCount = intval($conn->query("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")->fetch_assoc()['n']);
    if ($adminCount <= 1) {
      echo json_encode(["status" => "error", "message" => "Minimal harus ada satu admin"]);
      exit;
    }
  }
  $stmt = $conn->prepare("UPDATE users SET role = IF(role='user','admin','user') WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "ok"]);
  exit;
}

// ================= ADD GAME =================
if ($action === 'add_game') {
  $title   = trim($_POST['title'] ?? '');
  $genre   = trim($_POST['genre'] ?? '');
  $consoles = array_filter((array) ($_POST["consoles"] ?? []), function($c) {
    return in_array($c, QUEUE_CONSOLES, true);
  });

  if ($title === '' || $genre === '' || empty($consoles)) {
    echo json_encode(["status" => "error", "message" => "Lengkapi data game & pilih console"]);
    exit;
  }

  $imageName = null;
  if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
      $tmpName = uniqid('game_') . '.' . $ext;
      $dir     = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'games';
      if (!is_dir($dir)) mkdir($dir, 0755, true);
      $dest = $dir . DIRECTORY_SEPARATOR . $tmpName;
      if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) {
        $imageName = $tmpName;
      }
    }
  }

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
      "image_saved" => $imageName !== null,
    ]);
    exit;

  } catch (Exception $e) {
    $conn->rollback();
    error_log("Add game failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Gagal menyimpan game"]);
    exit;
  }
}

// ================= DELETE GAME =================
if ($action === 'delete_game') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM games WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "ok"]);
    exit;
}

// ================= UPDATE GAME CONSOLES =================
if ($action === 'update_game_consoles') {
    $id = intval($_POST['id'] ?? 0);
    $consoles = array_filter((array) ($_POST["consoles"] ?? []), fn($c) => in_array($c, QUEUE_CONSOLES, true));

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

        $conn->commit();
        echo json_encode(["status" => "ok"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => "Gagal update"]);
    }
    exit;
}

// ================= MARK ORDER DONE =================
if ($action === 'mark_order_done') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE menu_orders SET status = 'selesai' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "ok"]);
    exit;
}

// ================= ADD MENU ITEM =================
if ($action === 'add_menu_item') {
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $price       = intval($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($name === '' || !in_array($category, ['makanan', 'minuman'], true) || $price <= 0) {
        echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
        exit;
    }

    $imageName = null;
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['item_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $tmpName = uniqid('menu_') . '.' . $ext;
            $dir  = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'menu';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $dest = $dir . DIRECTORY_SEPARATOR . $tmpName;
            if (move_uploaded_file($_FILES['item_image']['tmp_name'], $dest)) {
                $imageName = $tmpName;
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO menu_items (name, category, price, description, image) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiss", $name, $category, $price, $description, $imageName);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["status" => "ok"]);
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

// ================= DELETE MENU ITEM =================
if ($action === 'delete_menu_item') {
    $id = intval($_POST['id'] ?? 0);
    try {
        $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["status" => "ok"]);
    } catch (\mysqli_sql_exception $e) {
        // Item masih punya histori pesanan (FK RESTRICT) — nonaktifkan saja, jangan hapus.
        echo json_encode(["status" => "error", "message" => "Item ini masih punya histori pesanan, tidak bisa dihapus. Gunakan tombol nonaktifkan (stok habis) sebagai gantinya."]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Action tidak dikenal"]);
exit;
