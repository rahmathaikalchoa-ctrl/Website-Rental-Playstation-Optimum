<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/queue_lib.php';
require_once __DIR__ . '/booking_lib.php';
require __DIR__ . '/icons.php';

// Nilai aman untuk argumen JS di atribut onclick. htmlspecialchars saja tidak cukup:
// browser men-decode &#039; kembali jadi ' sebelum JS dijalankan.
function jsArg($v) {
  return htmlspecialchars(json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES);
}

if (isset($_POST['login'])) {
  $inputUser = trim($_POST['username'] ?? '');
  if (!checkRateLimit('admin_login_attempts', 5, 300, $inputUser)) {
    $error = "Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.";
  } else {
    // Di-trim seperti login.php karena hash dibuat dari password yang sudah di-trim
    $inputPass = trim($_POST['password'] ?? '');

    $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ? AND role = 'admin'");
    $stmt->bind_param("s", $inputUser);
    $stmt->execute();
    $adminUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($adminUser && password_verify($inputPass, $adminUser['password'])) {
      clearRateLimit('admin_login_attempts', $inputUser);
      session_regenerate_id(true);
      $_SESSION['admin']          = true;
      $_SESSION['admin_id']       = intval($adminUser['id']);
      $_SESSION['admin_username'] = $inputUser;
      header("Location: KITSUNE-ADMIN.php");
      exit;
    } else {
      $error = "Username/password salah atau akun tidak memiliki akses admin";
    }
  }
}

if (isset($_GET['logout'])) {
  // Hanya keluarkan sesi admin; login user di browser yang sama tetap jalan
  unset($_SESSION['admin'], $_SESSION['admin_id'], $_SESSION['admin_username']);
  session_regenerate_id(true);
  header("Location: KITSUNE-ADMIN.php");
  exit;
}

$isAdmin = isAdminSession($conn);

$navItems = ['booking' => 'Booking', 'riwayat' => 'Riwayat Booking', 'room' => 'Room',
             'akun' => 'Akun', 'game' => 'Game', 'menu' => 'Menu'];
$page    = $_GET['page'] ?? 'booking';
if (!isset($navItems[$page])) $page = 'booking';
$perPage = 20;
$pageNum = max(1, intval($_GET['p'] ?? 1));
$offset  = ($pageNum - 1) * $perPage;
?>

<!DOCTYPE html>
<html>
<head>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Optimum Playzone</title>
  <link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body>

<?php if (!$isAdmin): ?>
  <div class="login-box">
    <h2>Optimum Playzone<span class="brand-sub">Login Admin</span></h2>
    <?php if (isset($error)): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <input name="username" placeholder="Username" required>
      <input type="password" name="password" placeholder="Password" required>
      <button name="login">Login</button>
    </form>
  </div>

<?php else: ?>

<nav class="sidebar">
  <h2>Optimum Playzone<span class="brand-sub">Panel Admin</span></h2>
  <?php foreach ($navItems as $key => $label): ?>
    <a href="?page=<?= $key ?>"<?= $page === $key ? ' class="active"' : '' ?>><?= $label ?></a>
  <?php endforeach; ?>
  <a href="?logout=1">Logout</a>
</nav>

<main>
  <!-- RIWAYAT BOOKING -->
<?php if ($page === 'riwayat'): ?>
  <?php
    $totalRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM bookings"))['n'];
    $totalPages = max(1, (int) ceil($totalRow / $perPage));
    $pageNum = min($pageNum, $totalPages);
    $offset  = ($pageNum - 1) * $perPage;

    $stmt = $conn->prepare("
      SELECT b.*, r.title AS room_title
      FROM bookings b
      LEFT JOIN rooms r ON r.id = b.room_id
      ORDER BY b.created_at DESC LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("ii", $perPage, $offset);
    $stmt->execute();
    $data = $stmt->get_result();
    $stmt->close();

    $payLabel = [
      'unpaid'    => ['Belum bayar', 'pay-unpaid'],
      'pending'   => ['Menunggu', 'pay-unpaid'],
      'paid'      => ['Lunas', 'pay-paid'],
      'cancelled' => ['Dibatalkan', 'pay-cancelled'],
    ];
  ?>
  <div class="page-head">
    <div>
      <h2>Riwayat Booking</h2>
      <p class="page-sub"><?= $totalRow ?> booking tercatat. Booking yang dibatalkan tetap disimpan untuk arsip.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table class="users-table">
      <tr><th>Konsumen</th><th>Ruangan</th><th>Jadwal</th><th>Total</th><th>Status</th><th>Aksi</th></tr>
      <?php if ($data->num_rows === 0): ?>
        <tr><td colspan="6" class="empty-row">Belum ada data booking.</td></tr>
      <?php endif; ?>
      <?php while($b = $data->fetch_assoc()): ?>
      <?php
        $isCancelled = $b['payment_status'] === 'cancelled';
        [$pl, $pc] = $payLabel[$b['payment_status']] ?? [$b['payment_status'], 'pay-unpaid'];
        // expires_at masih terisi pada booking batal = batal otomatis karena tidak hadir
        if ($isCancelled && $b['expires_at'] !== null) $pl = 'Batal · tidak hadir';
      ?>
      <tr class="<?= $isCancelled ? 'row-cancelled' : '' ?>">
        <td class="cell-main"><?= htmlspecialchars($b['customer_name']) ?>
          <span class="cell-sub"><?= htmlspecialchars($b['order_code'] ?? '-') ?> · <?= $b['source'] === 'walkin' ? 'Offline' : 'Online' ?></span>
        </td>
        <td><?= htmlspecialchars($b['room_title'] ?? '-') ?></td>
        <?php $st = intval($b['start_time']); ?>
        <td><?= date(date('Y', $st) === date('Y') ? 'd M' : 'd M Y', $st) ?>
          <span class="cell-sub"><?= date('H:i', intval($b['start_time'])) ?> – <?= date('H:i', intval($b['end_time'])) ?> · <?= intval($b['duration']) ?> jam</span>
        </td>
        <td>Rp<?= number_format(intval($b['total_price']), 0, ',', '.') ?></td>
        <td><span class="pay-badge <?= $pc ?>"><?= $pl ?></span></td>
        <td>
          <div class="row-actions">
            <?php if (!$isCancelled && $b['payment_status'] !== 'paid'): ?>
              <button class="btn-paid-sm" onclick="markPaid(<?= intval($b['id']) ?>, <?= jsArg($b['customer_name']) ?>, <?= intval($b['total_price']) ?>)">Lunas</button>
            <?php endif; ?>
            <?php if (!$isCancelled && intval($b['end_time']) > time()): ?>
              <button class="btn-icon" title="Batalkan booking" aria-label="Batalkan booking" onclick="cancelBooking(<?= intval($b['id']) ?>, <?= jsArg($b['customer_name']) ?>)"><?= icon('x') ?></button>
            <?php elseif ($isCancelled || $b['payment_status'] === 'paid'): ?>
              <span class="muted-dash">—</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($pageNum > 1): ?>
      <a href="?page=riwayat&p=<?= $pageNum - 1 ?>">&laquo; Prev</a>
    <?php endif; ?>
    <span>Hal <?= $pageNum ?> / <?= $totalPages ?></span>
    <?php if ($pageNum < $totalPages): ?>
      <a href="?page=riwayat&p=<?= $pageNum + 1 ?>">Next &raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- BOOKING (layanan operator: konsumen offline + daftar tunggu) -->
<?php elseif ($page === 'booking'): ?>
  <?php
    processQueue($conn);
    $now = time();
    $dayEnd = strtotime(date('Y-m-d', $now)) + 24 * 3600;

    $curStmt  = $conn->prepare("
      SELECT id, customer_name, source, start_time, end_time, expires_at, payment_status, total_price FROM bookings
      WHERE room_id = ? AND payment_status <> 'cancelled' AND start_time <= ? AND end_time > ? LIMIT 1
    ");
    $nextStmt = $conn->prepare("
      SELECT id, start_time, customer_name, expires_at FROM bookings
      WHERE room_id = ? AND payment_status <> 'cancelled' AND start_time > ? AND start_time < ?
      ORDER BY start_time LIMIT 1
    ");

    $rooms = [];
    $roomOptions = [];
    $minPrice = [];
    $stat = ['kosong' => 0, 'dipakai' => 0, 'service' => 0];
    $roomRes = mysqli_query($conn, "SELECT id, title, console_type, price, status FROM rooms ORDER BY console_type, id");
    while ($r = mysqli_fetch_assoc($roomRes)) {
      $rid = intval($r['id']);
      $curStmt->bind_param("iii", $rid, $now, $now);
      $curStmt->execute();
      $r['cur'] = $curStmt->get_result()->fetch_assoc();
      $nextStmt->bind_param("iii", $rid, $now, $dayEnd);
      $nextStmt->execute();
      $r['next'] = $nextStmt->get_result()->fetch_assoc();

      if ($r['status'] !== 'available')  { $r['state'] = 'service'; }
      elseif ($r['cur'])                 { $r['state'] = 'dipakai'; }
      else                               { $r['state'] = 'kosong'; }
      $stat[$r['state']]++;

      $ct = $r['console_type'];
      if ($r['status'] === 'available') {
        $minPrice[$ct] = min($minPrice[$ct] ?? PHP_INT_MAX, intval($r['price']));
        $roomOptions[] = ['id' => $rid, 'title' => $r['title'], 'console' => $ct,
                          'price' => intval($r['price']), 'free' => $r['state'] === 'kosong'];
      }
      $rooms[] = $r;
    }
    $curStmt->close();
    $nextStmt->close();

    $queueBy = ['PS3' => [], 'PS4' => [], 'PS5' => []];
    $qRes = mysqli_query($conn, "SELECT * FROM booking_queue WHERE status = 'waiting' ORDER BY created_at, id");
    while ($q = mysqli_fetch_assoc($qRes)) $queueBy[$q['console_type']][] = $q;
    $totalWaiting = array_sum(array_map('count', $queueBy));

    $stateLabel = ['kosong' => 'Kosong', 'dipakai' => 'Dipakai', 'service' => 'In Service'];
    $srcLabel   = fn($s) => $s === 'walkin' ? 'Offline' : 'Online';

    // Pilihan tanggal reservasi: hari ini s/d lusa, tampil dengan tanggal asli
    $bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $dateOptions = [];
    foreach (['Hari ini', 'Besok', 'Lusa'] as $i => $lbl) {
      $ts = strtotime("+$i day", $now);
      $dateOptions[date('Y-m-d', $ts)] = $lbl . ' · ' . date('j', $ts) . ' ' . $bulan[intval(date('n', $ts)) - 1];
    }
  ?>
  <div class="page-head">
    <div>
      <h2>Booking Ruangan</h2>
      <p class="page-sub">Layani konsumen yang datang langsung atau menelepon. Jika ruangan penuh, konsumen masuk daftar tunggu sesuai urutan kedatangan.</p>
    </div>
    <span class="clock-chip"><?= icon('clock') ?> <?= date('H:i') ?></span>
  </div>

  <div class="stat-row">
    <div class="stat-card stat-kosong"><span class="stat-num"><?= $stat['kosong'] ?></span><span class="stat-label">Ruangan kosong</span></div>
    <div class="stat-card stat-dipakai"><span class="stat-num"><?= $stat['dipakai'] ?></span><span class="stat-label">Sedang dipakai</span></div>
    <div class="stat-card stat-tunggu"><span class="stat-num"><?= $totalWaiting ?></span><span class="stat-label">Daftar tunggu</span></div>
    <div class="stat-card stat-service"><span class="stat-num"><?= $stat['service'] ?></span><span class="stat-label">In service</span></div>
  </div>

  <div class="booking-grid">
    <div class="panel-card" id="offlinePanel"
         data-rooms="<?= htmlspecialchars(json_encode($roomOptions), ENT_QUOTES) ?>"
         data-prices="<?= htmlspecialchars(json_encode($minPrice), ENT_QUOTES) ?>">
      <h3 class="panel-title"><?= icon('user') ?> Data Konsumen</h3>

      <div class="form-tabs" role="tablist">
        <button type="button" class="form-tab active" data-tab="walkinForm">Main Sekarang</button>
        <button type="button" class="form-tab" data-tab="reserveForm">Reservasi Jam Tertentu</button>
      </div>

      <!-- MAIN SEKARANG -->
      <form id="walkinForm" class="tab-form">
        <label class="field">
          <span>Nama</span>
          <input name="name" placeholder="Nama konsumen" maxlength="100" autocomplete="off" required>
        </label>
        <label class="field">
          <span>No. HP <em>(opsional)</em></span>
          <input name="phone" placeholder="08xxxxxxxxxx" inputmode="numeric" pattern="[0-9]{9,14}" maxlength="14" autocomplete="off">
        </label>

        <div class="field">
          <span>Konsol</span>
          <div class="console-pick">
            <?php foreach (QUEUE_CONSOLES as $i => $c): ?>
              <label class="console-opt c-<?= strtolower($c) ?>">
                <input type="radio" name="console_type" value="<?= $c ?>" <?= $i === 2 ? 'checked' : '' ?> required>
                <span><?= $c ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <label class="field">
          <span>Ruangan</span>
          <select name="room_id">
            <option value="0">Otomatis (sesuai urutan)</option>
            <?php foreach ($roomOptions as $o): ?>
              <option value="<?= $o['id'] ?>" data-console="<?= $o['console'] ?>" <?= $o['free'] ? '' : 'disabled' ?>>
                <?= htmlspecialchars($o['title']) ?> — Rp<?= number_format($o['price'], 0, ',', '.') ?>/jam<?= $o['free'] ? '' : ' (dipakai)' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span>Durasi</span>
          <select name="duration" required>
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?> jam</option>
            <?php endfor; ?>
          </select>
        </label>

        <div class="estimate">
          <span>Estimasi biaya</span>
          <strong data-estimate>-</strong>
        </div>
        <p class="form-note">Mulai sekarang dan langsung ditandai lunas.</p>

        <button type="submit" class="btn-primary"><?= icon('check') ?> Booking Sekarang</button>
      </form>

      <!-- RESERVASI JAM TERTENTU -->
      <form id="reserveForm" class="tab-form" hidden>
        <label class="field">
          <span>Nama</span>
          <input name="name" placeholder="Nama konsumen" maxlength="100" autocomplete="off" required>
        </label>
        <label class="field">
          <span>No. HP <em>(opsional)</em></span>
          <input name="phone" placeholder="08xxxxxxxxxx" inputmode="numeric" pattern="[0-9]{9,14}" maxlength="14" autocomplete="off">
        </label>
        <label class="field">
          <span>Ruangan</span>
          <select name="room_id" required>
            <?php foreach ($roomOptions as $o): ?>
              <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['title']) ?> (<?= $o['console'] ?>) — Rp<?= number_format($o['price'], 0, ',', '.') ?>/jam</option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="field-row">
          <label class="field">
            <span>Tanggal</span>
            <select name="date" required>
              <?php foreach ($dateOptions as $val => $lbl): ?>
                <option value="<?= $val ?>"><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span>Jam mulai</span>
            <select name="time" required></select>
          </label>
        </div>
        <label class="field">
          <span>Durasi</span>
          <select name="duration" required></select>
        </label>
        <p class="form-note" data-booked></p>

        <div class="estimate">
          <span>Estimasi biaya</span>
          <strong data-estimate>-</strong>
        </div>
        <p class="form-note">Bayar di kasir saat datang. Batal otomatis jika tidak lapor sampai 15 menit setelah jam mulai.</p>

        <button type="submit" class="btn-primary"><?= icon('clock') ?> Simpan Reservasi</button>
      </form>
    </div>

    <div>
      <h3 class="section-title"><?= icon('gamepad') ?> Status Ruangan</h3>
      <div class="room-grid">
        <?php foreach ($rooms as $r): ?>
        <?php
          $cur  = $r['cur'];
          $next = $r['next'];
          $progress = 0;
          if ($cur) {
            $span = max(1, intval($cur['end_time']) - intval($cur['start_time']));
            $progress = min(100, round(($now - intval($cur['start_time'])) / $span * 100));
          }
          $waitingArrival = $cur && $cur['expires_at'] !== null;
          // Konsumen reservasi berikutnya boleh check-in lebih awal (15 menit sebelum mulai)
          $nextEarly = !$cur && $next && $next['expires_at'] !== null
                       && intval($next['start_time']) - $now <= BOOKING_CHECKIN_SEC;
        ?>
        <div class="room-tile state-<?= $r['state'] ?>">
          <div class="room-tile-head">
            <span class="console-chip c-<?= strtolower(htmlspecialchars($r['console_type'])) ?>"><?= htmlspecialchars($r['console_type']) ?></span>
            <span class="state-pill"><?= $stateLabel[$r['state']] ?></span>
          </div>
          <h4><?= htmlspecialchars($r['title']) ?></h4>

          <?php if ($cur): ?>
            <p class="room-who">
              <?= htmlspecialchars($cur['customer_name']) ?>
              <span class="src-chip"><?= $srcLabel($cur['source']) ?></span>
              <span class="pay-badge <?= $cur['payment_status'] === 'paid' ? 'pay-paid' : 'pay-unpaid' ?>">
                <?= $cur['payment_status'] === 'paid' ? 'Lunas' : 'Belum bayar' ?>
              </span>
            </p>
            <?php if ($waitingArrival): ?>
              <p class="checkin-wait">Menunggu kedatangan s/d <?= date('H:i', intval($cur['expires_at'])) ?></p>
            <?php else: ?>
              <div class="progress"><div style="width:<?= $progress ?>%"></div></div>
            <?php endif; ?>
            <p class="room-meta">Selesai <?= date('H:i', intval($cur['end_time'])) ?> · Rp<?= number_format(intval($cur['total_price']), 0, ',', '.') ?></p>
          <?php elseif ($r['state'] === 'kosong'): ?>
            <p class="room-meta">Siap dipakai</p>
          <?php else: ?>
            <p class="room-meta">Sedang perawatan</p>
          <?php endif; ?>

          <p class="room-next">
            <?= icon('clock') ?>
            <?= $next ? 'Reservasi ' . date('H:i', intval($next['start_time'])) . ' — ' . htmlspecialchars($next['customer_name']) : 'Tidak ada reservasi' ?>
          </p>

          <?php if ($nextEarly): ?>
            <button class="btn-checkin" onclick="checkinBooking(<?= intval($next['id']) ?>, <?= jsArg($next['customer_name']) ?>)"><?= icon('check') ?> Hadir (reservasi <?= date('H:i', intval($next['start_time'])) ?>)</button>
          <?php endif; ?>

          <?php if ($cur): ?>
            <div class="tile-actions">
              <?php if ($waitingArrival): ?>
                <button class="btn-checkin" onclick="checkinBooking(<?= intval($cur['id']) ?>, <?= jsArg($cur['customer_name']) ?>)"><?= icon('check') ?> Hadir</button>
              <?php endif; ?>
              <?php if ($cur['payment_status'] !== 'paid'): ?>
                <button class="btn-paid" onclick="markPaid(<?= intval($cur['id']) ?>, <?= jsArg($cur['customer_name']) ?>, <?= intval($cur['total_price']) ?>)">Lunas</button>
              <?php endif; ?>
              <?php if (!$waitingArrival): ?>
                <div class="extend-inline">
                  <select aria-label="Tambah jam" id="extendSel<?= intval($cur['id']) ?>">
                    <?php for ($i = 1; $i <= BOOKING_EXTEND_MAX; $i++): ?>
                      <option value="<?= $i ?>">+<?= $i ?> jam</option>
                    <?php endfor; ?>
                  </select>
                  <button class="btn-ghost-sm" onclick="adminExtend(<?= intval($cur['id']) ?>)">Tambah</button>
                </div>
                <button class="btn-outline" onclick="finishBooking(<?= intval($cur['id']) ?>)">Selesaikan Sesi</button>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$rooms): ?>
          <p class="queue-empty">Belum ada ruangan. Tambahkan di menu Room.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <h3 class="section-title" style="margin-top:32px"><?= icon('clipboard') ?> Daftar Tunggu</h3>
  <div class="queue-grid">
    <?php foreach ($queueBy as $ct => $list): ?>
    <div class="queue-col">
      <div class="queue-col-head">
        <span class="console-chip c-<?= strtolower($ct) ?>"><?= $ct ?></span>
        <span class="queue-count"><?= count($list) ?> menunggu</span>
      </div>
      <?php if (!$list): ?>
        <p class="queue-empty">Tidak ada yang menunggu</p>
      <?php endif; ?>
      <?php foreach ($list as $i => $q): ?>
      <div class="queue-item<?= $i === 0 ? ' is-next' : '' ?>">
        <span class="queue-no"><?= $i + 1 ?></span>
        <div class="queue-info">
          <strong><?= htmlspecialchars($q['customer_name']) ?></strong>
          <span>
            <?= intval($q['duration']) ?> jam · masuk <?= date('H:i', strtotime($q['created_at'])) ?>
            · <?= $srcLabel($q['source']) ?>
            <?= $q['phone'] ? ' · ' . htmlspecialchars($q['phone']) : '' ?>
          </span>
        </div>
        <button class="btn-icon" title="Hapus dari daftar tunggu" aria-label="Hapus dari daftar tunggu"
          onclick="cancelQueue(<?= intval($q['id']) ?>, <?= jsArg($q['customer_name']) ?>)"><?= icon('x') ?></button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ROOMS -->
<?php elseif ($page === 'room'): ?>
  <?php $rooms = mysqli_query($conn, "SELECT * FROM rooms ORDER BY console_type, id"); ?>
  <div class="page-head">
    <div>
      <h2>Manajemen Room</h2>
      <p class="page-sub">Tambah ruangan baru atau nonaktifkan ruangan yang sedang perawatan.</p>
    </div>
  </div>

  <form id="addRoomForm" class="panel-card form-inline">
    <label class="field">
      <span>Nama room</span>
      <input type="text" name="title" placeholder="mis. VIP - Room 4" required>
    </label>
    <label class="field">
      <span>Konsol</span>
      <select name="console" required>
        <option value="">Pilih</option>
        <?php foreach (QUEUE_CONSOLES as $c): ?>
          <option value="<?= $c ?>"><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>Harga / jam</span>
      <input type="number" name="price" placeholder="25000" min="1" required>
    </label>
    <label class="field">
      <span>Keterangan</span>
      <input type="text" name="description" placeholder="mis. Ruangan 4 orang">
    </label>
    <button type="submit" class="btn-primary"><?= icon('check') ?> Tambah</button>
  </form>

  <div class="table-wrap">
    <table class="users-table">
      <tr><th>Room</th><th>Konsol</th><th>Harga / jam</th><th>Status</th><th>Aksi</th></tr>
      <?php if (mysqli_num_rows($rooms) === 0): ?>
        <tr><td colspan="5" class="empty-row">Belum ada ruangan.</td></tr>
      <?php endif; ?>
      <?php while($r = mysqli_fetch_assoc($rooms)): ?>
      <?php $active = $r['status'] === 'available'; ?>
      <tr>
        <td class="cell-main wrap-cell"><?= htmlspecialchars($r['title']) ?>
          <?php if ($r['description']): ?><span class="cell-sub"><?= htmlspecialchars($r['description']) ?></span><?php endif; ?>
        </td>
        <td><span class="console-chip c-<?= strtolower(htmlspecialchars($r['console_type'])) ?>"><?= htmlspecialchars($r['console_type']) ?></span></td>
        <td>Rp<?= number_format($r['price'], 0, ',', '.') ?></td>
        <td><span class="pay-badge <?= $active ? 'pay-paid' : 'pay-unpaid' ?>"><?= $active ? 'Aktif' : 'Perawatan' ?></span></td>
        <td>
          <div class="row-actions">
            <button class="<?= $active ? 'btn-warn-sm' : 'btn-ghost-sm' ?>" onclick="toggleRoom(<?= intval($r['id']) ?>)"><?= $active ? 'Set Perawatan' : 'Aktifkan' ?></button>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>

  <!-- AKUN -->
<?php elseif ($page === 'akun'): ?>
  <?php $users = mysqli_query($conn, "SELECT id, username, role, created_at, last_activity FROM users ORDER BY role DESC, created_at ASC"); ?>
  <div class="page-head">
    <div>
      <h2>Data Akun</h2>
      <p class="page-sub">Status online dihitung dari aktivitas 5 menit terakhir.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table class="users-table">
      <tr><th>Username</th><th>Role</th><th>Status</th><th>Terdaftar</th><th>Aksi</th></tr>
      <?php if (mysqli_num_rows($users) === 0): ?>
        <tr><td colspan="5" class="empty-row">Belum ada akun.</td></tr>
      <?php endif; ?>
      <?php while($u = mysqli_fetch_assoc($users)): ?>
      <?php
        $online  = !empty($u['last_activity']) && (time() - strtotime($u['last_activity'])) <= 300;
        $rowIsAdmin = $u['role'] === 'admin';
        $isSelf  = intval($u['id']) === intval($_SESSION['admin_id']);
      ?>
      <tr>
        <td class="cell-main"><?= htmlspecialchars($u['username']) ?><?= $isSelf ? ' <span class="cell-sub">(akun kamu)</span>' : '' ?></td>
        <td><span class="role-badge <?= $rowIsAdmin ? 'role-admin' : 'role-user' ?>"><?= $rowIsAdmin ? 'Admin' : 'User' ?></span></td>
        <td><span class="status <?= $online ? 'online' : 'offline' ?>">● <?= $online ? 'Online' : 'Offline' ?></span></td>
        <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
        <td>
          <div class="row-actions">
            <?php if ($isSelf): ?>
              <span class="muted-dash">—</span>
            <?php else: ?>
              <button class="btn-ghost-sm" onclick="toggleRole(<?= intval($u['id']) ?>, <?= jsArg($u['username']) ?>)">
                <?= $rowIsAdmin ? 'Jadikan User' : 'Jadikan Admin' ?>
              </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>

<!-- GAMES -->
<?php elseif ($page === 'game'): ?>
<?php
$games = mysqli_query($conn, "
  SELECT g.id, g.title, g.genre,
  GROUP_CONCAT(gc.console_type ORDER BY gc.console_type) AS consoles
  FROM games g
  LEFT JOIN game_consoles gc ON g.id = gc.game_id
  GROUP BY g.id
  ORDER BY g.title
");
?>
<div class="page-head">
  <div>
    <h2>Manajemen Game</h2>
    <p class="page-sub">Game yang tampil di website beserta konsol yang mendukung.</p>
  </div>
</div>

<div class="split">
  <form id="addGameForm" class="panel-card" enctype="multipart/form-data">
    <h3 class="panel-title"><?= icon('gamepad') ?> Tambah Game</h3>
    <label class="field">
      <span>Nama game</span>
      <input name="title" placeholder="mis. EA FC 26" required>
    </label>
    <label class="field">
      <span>Genre</span>
      <select id="genreSelect" name="genre" required>
        <option value="">Pilih genre</option>
        <?php
        $existingGenres = mysqli_query($conn, "SELECT DISTINCT genre FROM games ORDER BY genre");
        $shownGenres    = ['Action','Sports','Racing','Fighting','Adventure'];
        while ($g = mysqli_fetch_assoc($existingGenres)) {
          if (!in_array($g['genre'], $shownGenres)) $shownGenres[] = $g['genre'];
        }
        foreach ($shownGenres as $genre):
        ?>
        <option value="<?= htmlspecialchars($genre) ?>"><?= htmlspecialchars($genre) ?></option>
        <?php endforeach; ?>
        <option value="__custom__">+ Tambah genre baru...</option>
      </select>
    </label>
    <div id="customGenreWrap" class="custom-genre-wrap" style="display:none">
      <input type="text" id="customGenreInput" placeholder="Nama genre baru (mis. Horror)" autocomplete="off">
      <button type="button" id="cancelCustomGenre" class="btn-cancel-genre" aria-label="Batal"><?= icon('x') ?></button>
    </div>

    <div class="field">
      <span>Konsol</span>
      <div class="checkbox-group">
        <?php foreach (QUEUE_CONSOLES as $c): ?>
          <label><input type="checkbox" name="consoles[]" value="<?= $c ?>"> <?= $c ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <label class="file-upload-zone">
      <input type="file" name="cover_image" accept="image/*">
      <img class="file-upload-preview" style="display:none" alt="preview">
      <div class="file-upload-inner">
        <span class="file-upload-icon"><?= icon('image') ?></span>
        <span class="file-upload-label">Klik atau drag & drop foto cover</span>
        <span class="file-upload-name">Belum ada file dipilih</span>
      </div>
    </label>
    <button type="submit" class="btn-primary"><?= icon('check') ?> Simpan Game</button>
  </form>

  <div class="table-wrap">
    <table class="users-table">
      <tr><th>Nama Game</th><th>Genre</th><th>Konsol</th><th>Aksi</th></tr>
      <?php if (mysqli_num_rows($games) === 0): ?>
        <tr><td colspan="4" class="empty-row">Belum ada game.</td></tr>
      <?php endif; ?>
      <?php while ($g = mysqli_fetch_assoc($games)): ?>
      <?php $consolesArr = $g['consoles'] ? explode(',', $g['consoles']) : []; ?>
      <tr>
        <td class="cell-main wrap-cell"><?= htmlspecialchars($g['title']) ?></td>
        <td><?= htmlspecialchars($g['genre']) ?></td>
        <td>
          <div class="chip-row">
            <?php foreach ($consolesArr as $c): ?>
              <span class="console-chip c-<?= strtolower(htmlspecialchars($c)) ?>"><?= htmlspecialchars($c) ?></span>
            <?php endforeach; ?>
            <?php if (!$consolesArr): ?><span class="muted-dash">—</span><?php endif; ?>
          </div>
        </td>
        <td>
          <div class="row-actions">
            <button class="btn-ghost-sm" onclick="openEditGame(<?= intval($g['id']) ?>, <?= jsArg($g['title']) ?>, <?= jsArg($consolesArr) ?>)">Edit Konsol</button>
            <button class="btn-danger-sm" onclick="deleteGame(<?= intval($g['id']) ?>, <?= jsArg($g['title']) ?>)">Hapus</button>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>
</div>

<!-- MODAL EDIT CONSOLE GAME -->
<div id="editGameModal" class="modal-backdrop" style="display:none">
  <div class="modal-card">
    <h3 class="panel-title">Edit Konsol</h3>
    <p id="editGameTitle" class="page-sub"></p>
    <input type="hidden" id="editGameId">
    <div class="modal-chips">
      <?php foreach (['PS3' => 'rgba(255,107,107,0.5)', 'PS4' => 'rgba(77,166,255,0.5)', 'PS5' => 'rgba(178,107,255,0.5)'] as $c => $border): ?>
        <label id="editLabel<?= $c ?>" class="modal-chip" style="border-color:<?= $border ?>">
          <input type="checkbox" id="edit<?= $c ?>" value="<?= $c ?>" hidden> <?= $c ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="modal-actions">
      <button id="saveEditGame" class="btn-primary">Simpan</button>
      <button onclick="closeEditGame()" class="btn-ghost-sm">Batal</button>
    </div>
  </div>
</div>

<!-- MENU -->
<?php elseif ($page === 'menu'): ?>
<?php
$menuItems = mysqli_query($conn, "SELECT * FROM menu_items ORDER BY category, name");
$pendingCount = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS n FROM menu_orders WHERE status = 'pending'"
))['n'];
$orders = mysqli_query($conn, "
    SELECT mo.id, mo.quantity, mo.note, mo.status, mo.created_at,
           mi.name AS item_name, mi.price,
           u.username
    FROM menu_orders mo
    JOIN menu_items mi ON mi.id = mo.item_id
    JOIN users u       ON u.id  = mo.user_id
    ORDER BY mo.status ASC, mo.created_at DESC
    LIMIT 50
");
?>
<div class="page-head">
  <div>
    <h2>Menu Makanan &amp; Minuman</h2>
    <p class="page-sub">Kelola item menu dan pantau pesanan dari pengunjung.</p>
  </div>
</div>

<h3 class="section-title">
  <?= icon('clipboard') ?> Pesanan Masuk
  <?php if ($pendingCount > 0): ?><span class="count-pill"><?= $pendingCount ?> baru</span><?php endif; ?>
</h3>
<div class="table-wrap" style="margin-bottom:32px">
  <table class="users-table">
    <tr><th>Pemesan</th><th>Item</th><th>Qty</th><th>Total</th><th>Catatan</th><th>Waktu</th><th>Status</th><th>Aksi</th></tr>
    <?php if (mysqli_num_rows($orders) === 0): ?>
      <tr><td colspan="8" class="empty-row">Belum ada pesanan.</td></tr>
    <?php endif; ?>
    <?php while ($o = mysqli_fetch_assoc($orders)): ?>
    <?php $isDone = $o['status'] === 'selesai'; ?>
    <tr class="<?= $isDone ? 'row-done' : '' ?>">
      <td class="cell-main"><?= htmlspecialchars($o['username']) ?></td>
      <td><?= htmlspecialchars($o['item_name']) ?></td>
      <td><?= intval($o['quantity']) ?>x</td>
      <td>Rp<?= number_format($o['price'] * $o['quantity'], 0, ',', '.') ?></td>
      <td class="wrap-cell"><?= $o['note'] ? htmlspecialchars($o['note']) : '<span class="muted-dash">—</span>' ?></td>
      <td><?= date('d M H:i', strtotime($o['created_at'])) ?></td>
      <td>
        <span class="pay-badge <?= $isDone ? 'pay-paid' : 'pay-unpaid' ?>"><?= $isDone ? 'Selesai' : 'Diproses' ?></span>
      </td>
      <td>
        <div class="row-actions">
          <?php if (!$isDone): ?>
            <button class="btn-paid-sm" onclick="markOrderDone(<?= intval($o['id']) ?>)"><?= icon('check') ?> Sudah Sampai</button>
          <?php else: ?>
            <span class="muted-dash">—</span>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
  </table>
</div>

<div class="split">
  <form id="addMenuForm" class="panel-card" enctype="multipart/form-data">
    <h3 class="panel-title"><?= icon('clipboard') ?> Tambah Item</h3>
    <label class="field">
      <span>Nama item</span>
      <input name="name" placeholder="mis. Indomie Goreng" required>
    </label>
    <div class="field-row">
      <label class="field">
        <span>Kategori</span>
        <select name="category" required>
          <option value="">Pilih</option>
          <option value="makanan">Makanan</option>
          <option value="minuman">Minuman</option>
        </select>
      </label>
      <label class="field">
        <span>Harga</span>
        <input type="number" name="price" placeholder="12000" min="1" required>
      </label>
    </div>
    <label class="field">
      <span>Deskripsi <em>(opsional)</em></span>
      <input type="text" name="description" placeholder="Deskripsi singkat">
    </label>
    <label class="file-upload-zone">
      <input type="file" name="item_image" accept="image/*">
      <img class="file-upload-preview" style="display:none" alt="preview">
      <div class="file-upload-inner">
        <span class="file-upload-icon"><?= icon('image') ?></span>
        <span class="file-upload-label">Klik atau drag & drop foto item</span>
        <span class="file-upload-name">Belum ada file dipilih</span>
      </div>
    </label>
    <button type="submit" class="btn-primary"><?= icon('check') ?> Tambah Item</button>
  </form>

  <div class="table-wrap">
    <table class="users-table">
      <tr><th>Nama</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Aksi</th></tr>
      <?php if (mysqli_num_rows($menuItems) === 0): ?>
        <tr><td colspan="5" class="empty-row">Belum ada item menu.</td></tr>
      <?php endif; ?>
      <?php while ($item = mysqli_fetch_assoc($menuItems)): ?>
      <tr>
        <td class="cell-main wrap-cell"><?= htmlspecialchars($item['name']) ?></td>
        <td style="text-transform:capitalize"><?= htmlspecialchars($item['category']) ?></td>
        <td>Rp<?= number_format($item['price'], 0, ',', '.') ?></td>
        <td><span class="pay-badge <?= $item['is_available'] ? 'pay-paid' : 'pay-cancelled' ?>"><?= $item['is_available'] ? 'Tersedia' : 'Habis' ?></span></td>
        <td>
          <div class="row-actions">
            <button class="<?= $item['is_available'] ? 'btn-warn-sm' : 'btn-ghost-sm' ?>" onclick="toggleMenuItem(<?= intval($item['id']) ?>)">
              <?= $item['is_available'] ? 'Tandai Habis' : 'Tandai Tersedia' ?>
            </button>
            <button class="btn-danger-sm" onclick="deleteMenuItem(<?= intval($item['id']) ?>, <?= jsArg($item['name']) ?>)">Hapus</button>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>
</div>

<?php endif; ?>
</main>
<script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>"></script>
<?php endif; ?>
</body>
</html>
