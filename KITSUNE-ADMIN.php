<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/queue_lib.php';
require __DIR__ . '/icons.php';

// Nilai aman untuk argumen JS di atribut onclick. htmlspecialchars saja tidak cukup:
// browser men-decode &#039; kembali jadi ' sebelum JS dijalankan.
function jsArg($v) {
  return htmlspecialchars(json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES);
}

if (isset($_POST['login'])) {
  if (!checkRateLimit('admin_login_attempts', 5, 300)) {
    $error = "Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.";
  } else {
    $inputUser = trim($_POST['username'] ?? '');
    $inputPass = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ? AND role = 'admin'");
    $stmt->bind_param("s", $inputUser);
    $stmt->execute();
    $adminUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($adminUser && password_verify($inputPass, $adminUser['password'])) {
      session_regenerate_id(true);
      $_SESSION['admin']          = true;
      $_SESSION['admin_username'] = $inputUser;
      header("Location: KITSUNE-ADMIN.php");
      exit;
    } else {
      $error = "Username/password salah atau akun tidak memiliki akses admin";
    }
  }
}

if (isset($_GET['logout'])) {
  session_unset();
  session_destroy();
  header("Location: KITSUNE-ADMIN.php");
  exit;
}

$page    = $_GET['page'] ?? 'booking';
$perPage = 20;
$pageNum = max(1, intval($_GET['p'] ?? 1));
$offset  = ($pageNum - 1) * $perPage;
?>

<!DOCTYPE html>
<html>
<head>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin GameZone</title>
  <link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body>

<?php if (!isset($_SESSION['admin'])): ?>
  <div class="login-box">
    <h2>Admin Login</h2>
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
  <h2>Admin</h2>
  <?php
    $navItems = ['booking' => 'Booking', 'riwayat' => 'Riwayat Booking', 'room' => 'Room',
                 'akun' => 'Akun', 'game' => 'Game', 'menu' => 'Menu'];
  ?>
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

    $stmt = $conn->prepare("SELECT * FROM bookings ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $perPage, $offset);
    $stmt->execute();
    $data = $stmt->get_result();
    $stmt->close();
  ?>
  <h2>Riwayat Booking (<?= $totalRow ?> total)</h2>
  <table class="users-table">
    <tr><th>Nama</th><th>Sumber</th><th>Room</th><th>Durasi</th><th>Total</th><th>Status Bayar</th><th>Aksi</th></tr>
    <?php while($b = $data->fetch_assoc()): ?>
    <tr>
      <td><?= htmlspecialchars($b['customer_name']) ?></td>
      <td><?= $b['source'] === 'walkin' ? 'Walk-in' : 'Online' ?></td>
      <td><?= intval($b['room_id']) ?></td>
      <td><?= intval($b['duration']) ?> jam</td>
      <td>Rp<?= number_format(intval($b['total_price']), 0, ',', '.') ?></td>
      <td><?= htmlspecialchars($b['payment_status']) ?></td>
      <td><button onclick="hapusBooking(<?= intval($b['id']) ?>)">Hapus</button></td>
    </tr>
    <?php endwhile; ?>
  </table>

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

  <!-- BOOKING (layanan operator: konsumen datang langsung + daftar tunggu) -->
<?php elseif ($page === 'booking'): ?>
  <?php
    processQueue($conn);
    $now = time();
    $dayEnd = strtotime(date('Y-m-d', $now)) + 24 * 3600;

    $curStmt  = $conn->prepare("SELECT id, customer_name, source, start_time, end_time FROM bookings WHERE room_id = ? AND start_time <= ? AND end_time > ? LIMIT 1");
    $nextStmt = $conn->prepare("SELECT start_time, customer_name FROM bookings WHERE room_id = ? AND start_time > ? AND start_time < ? ORDER BY start_time LIMIT 1");

    $rooms = [];
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
  ?>
  <div class="page-head">
    <div>
      <h2>Booking Ruangan</h2>
      <p class="page-sub">Input konsumen yang datang langsung. Jika ruangan penuh, konsumen masuk daftar tunggu sesuai urutan kedatangan.</p>
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
    <form id="walkinForm" class="panel-card" data-prices="<?= htmlspecialchars(json_encode($minPrice), ENT_QUOTES) ?>">
      <h3 class="panel-title"><?= icon('user') ?> Data Konsumen</h3>

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
          <?php foreach (['PS3', 'PS4', 'PS5'] as $i => $c): ?>
            <label class="console-opt c-<?= strtolower($c) ?>">
              <input type="radio" name="console_type" value="<?= $c ?>" <?= $i === 2 ? 'checked' : '' ?> required>
              <span><?= $c ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

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
        <strong id="walkinEstimate">-</strong>
      </div>

      <button type="submit" class="btn-primary"><?= icon('check') ?> Booking</button>
    </form>

    <div>
      <h3 class="section-title"><?= icon('gamepad') ?> Status Ruangan</h3>
      <div class="room-grid">
        <?php foreach ($rooms as $r): ?>
        <?php
          $cur = $r['cur'];
          $progress = 0;
          if ($cur) {
            $span = max(1, intval($cur['end_time']) - intval($cur['start_time']));
            $progress = min(100, round(($now - intval($cur['start_time'])) / $span * 100));
          }
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
              <span class="src-chip"><?= $cur['source'] === 'walkin' ? 'Walk-in' : 'Online' ?></span>
            </p>
            <div class="progress"><div style="width:<?= $progress ?>%"></div></div>
            <p class="room-meta">Selesai <?= date('H:i', intval($cur['end_time'])) ?></p>
          <?php elseif ($r['state'] === 'kosong'): ?>
            <p class="room-meta">Siap dipakai</p>
          <?php else: ?>
            <p class="room-meta">Sedang perawatan</p>
          <?php endif; ?>

          <p class="room-next">
            <?= icon('clock') ?>
            <?= $r['next'] ? 'Reservasi ' . date('H:i', intval($r['next']['start_time'])) . ' — ' . htmlspecialchars($r['next']['customer_name']) : 'Tidak ada reservasi' ?>
          </p>

          <?php if ($cur): ?>
            <button class="btn-outline" onclick="finishBooking(<?= intval($cur['id']) ?>)">Selesaikan Sesi</button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
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
      <?php $tooLate = $now + intval($q['duration']) * 3600 > $dayEnd; ?>
      <div class="queue-item<?= $i === 0 ? ' is-next' : '' ?>">
        <span class="queue-no"><?= $i + 1 ?></span>
        <div class="queue-info">
          <strong><?= htmlspecialchars($q['customer_name']) ?></strong>
          <span>
            <?= intval($q['duration']) ?> jam · masuk <?= date('H:i', strtotime($q['created_at'])) ?>
            · <?= $q['source'] === 'walkin' ? 'Walk-in' : 'Online' ?>
            <?= $q['phone'] ? ' · ' . htmlspecialchars($q['phone']) : '' ?>
          </span>
          <?php if ($tooLate): ?><em class="queue-warn">Durasi melewati jam tutup</em><?php endif; ?>
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
  <?php $rooms = mysqli_query($conn, "SELECT * FROM rooms"); ?>
  <h2>Manajemen Room</h2>

  <form id="addRoomForm">
    <input type="text" name="title" placeholder="Nama Room" required>

    <select name="console" required>
      <option value="">Pilih Console</option>
      <option value="PS3">PS3</option>
      <option value="PS4">PS4</option>
      <option value="PS5">PS5</option>
    </select>

    <input type="number" name="price" placeholder="Harga" min="1" required>
    <input type="text" name="description" placeholder="Detail Console / Ruangan">
    <button type="submit">Tambah Room</button>
  </form>

  <table class="users-table">
    <tr>
      <th>Room</th>
      <th>Console</th>
      <th>Harga</th>
      <th>Status</th>
      <th>Aksi</th>
    </tr>
    <?php while($r = mysqli_fetch_assoc($rooms)): ?>
    <tr>
      <td><?= htmlspecialchars($r['title']) ?></td>
      <td><?= htmlspecialchars($r['console_type']) ?></td>
      <td>Rp <?= number_format($r['price'], 0, ',', '.') ?></td>
      <td><?= htmlspecialchars($r['status']) ?></td>
      <td><button onclick="toggleRoom(<?= intval($r['id']) ?>)">Toggle</button></td>
    </tr>
    <?php endwhile; ?>
  </table>

  <!-- AKUN -->
<?php elseif ($page === 'akun'): ?>
  <?php $users = mysqli_query($conn, "SELECT id, username, role, created_at, last_activity FROM users ORDER BY role DESC, created_at ASC"); ?>
  <h2>Data Akun User</h2>
  <table class="users-table">
    <tr><th>ID</th><th>Username</th><th>Role</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr>
    <?php while($u = mysqli_fetch_assoc($users)): ?>
    <?php
      $online  = !empty($u['last_activity']) && (time() - strtotime($u['last_activity'])) <= 300;
      $isAdmin = $u['role'] === 'admin';
    ?>
    <tr>
      <td><?= intval($u['id']) ?></td>
      <td><?= htmlspecialchars($u['username']) ?></td>
      <td>
        <span class="role-badge <?= $isAdmin ? 'role-admin' : 'role-user' ?>">
          <?= $isAdmin ? 'Admin' : 'User' ?>
        </span>
      </td>
      <td>
        <?php if ($online): ?>
          <span class="status online">● Online</span>
        <?php else: ?>
          <span class="status offline">● Offline</span>
        <?php endif; ?>
      </td>
      <td><?= htmlspecialchars($u['created_at']) ?></td>
      <td>
        <button onclick="toggleRole(<?= intval($u['id']) ?>, <?= jsArg($u['username']) ?>)">
          <?= $isAdmin ? 'Jadikan User' : 'Jadikan Admin' ?>
        </button>
      </td>
    </tr>
    <?php endwhile; ?>
  </table>

<!-- GAMES -->
<?php elseif ($page === 'game'): ?>
<h2>Manajemen Game</h2>
<?php if (isset($_GET['success']) && $_GET['success'] === 'game_added'): ?>
  <script>alert("Game berhasil ditambahkan");</script>
<?php endif; ?>
<form class="admin-form" id="addGameForm" enctype="multipart/form-data">
  <input name="title" placeholder="Nama Game" required>
  <select id="genreSelect" name="genre" required>
    <option value="">Pilih Genre</option>
    <?php
    $existingGenres = mysqli_query($conn, "SELECT DISTINCT genre FROM games ORDER BY genre");
    $defaultGenres  = ['Action','Sports','Racing','Fighting','Adventure'];
    $shownGenres    = $defaultGenres;
    while ($g = mysqli_fetch_assoc($existingGenres)) {
      if (!in_array($g['genre'], $shownGenres)) $shownGenres[] = $g['genre'];
    }
    foreach ($shownGenres as $genre):
    ?>
    <option value="<?= htmlspecialchars($genre) ?>"><?= htmlspecialchars($genre) ?></option>
    <?php endforeach; ?>
    <option value="__custom__" style="color:#00eaff;font-weight:700">+ Tambah genre baru...</option>
  </select>
  <div id="customGenreWrap" class="custom-genre-wrap" style="display:none">
    <input type="text" id="customGenreInput" placeholder="Nama genre baru (mis. Horror, Puzzle...)" autocomplete="off">
    <button type="button" id="cancelCustomGenre" class="btn-cancel-genre" aria-label="Batal"><?= icon('x') ?></button>
  </div>

  <div class="checkbox-group">
    <label><input type="checkbox" name="consoles[]" value="PS3"> PS3</label>
    <label><input type="checkbox" name="consoles[]" value="PS4"> PS4</label>
    <label><input type="checkbox" name="consoles[]" value="PS5"> PS5</label>
  </div>

  <label class="file-upload-zone">
    <input type="file" name="cover_image" accept="image/*">
    <img class="file-upload-preview" style="display:none" alt="preview">
    <div class="file-upload-inner">
      <span class="file-upload-icon"><?= icon('image') ?></span>
      <span class="file-upload-label">Klik atau drag & drop foto cover game</span>
      <span class="file-upload-name">Belum ada file dipilih</span>
    </div>
  </label>
  <button type="submit">Simpan</button>
</form>

<?php
$games = mysqli_query($conn, "
  SELECT g.id, g.title, g.genre,
  GROUP_CONCAT(gc.console_type) AS consoles
  FROM games g
  LEFT JOIN game_consoles gc ON g.id = gc.game_id
  GROUP BY g.id
");
?>

<table class="users-table">
  <tr>
    <th>Nama Game</th>
    <th>Genre</th>
    <th>Console</th>
    <th>Aksi</th>
  </tr>
  <?php while ($g = mysqli_fetch_assoc($games)): ?>
  <?php $consolesArr = $g['consoles'] ? explode(',', $g['consoles']) : []; ?>
  <tr>
    <td><?= htmlspecialchars($g['title']) ?></td>
    <td><?= htmlspecialchars($g['genre']) ?></td>
    <td><?= htmlspecialchars($g['consoles'] ?? '-') ?></td>
    <td style="display:flex;gap:8px;flex-wrap:wrap">
      <button onclick="openEditGame(<?= intval($g['id']) ?>, <?= jsArg($g['title']) ?>, <?= jsArg($consolesArr) ?>)">Edit Console</button>
      <button class="btn-danger" onclick="deleteGame(<?= intval($g['id']) ?>, <?= jsArg($g['title']) ?>)">Hapus</button>
    </td>
  </tr>
  <?php endwhile; ?>
</table>

<!-- MODAL EDIT CONSOLE GAME -->
<div id="editGameModal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.7);align-items:center;justify-content:center">
  <div style="background:#181b22;border:1px solid rgba(0,234,255,0.2);border-radius:14px;padding:28px;min-width:320px;box-shadow:0 0 40px rgba(0,234,255,0.1)">
    <h3 style="color:#00eaff;margin-bottom:6px">Edit Console</h3>
    <p id="editGameTitle" style="color:#9fb4c2;font-size:13px;margin-bottom:18px"></p>
    <input type="hidden" id="editGameId">
    <div style="display:flex;gap:10px;margin-bottom:20px">
      <label id="editLabelPS3" style="padding:8px 18px;border-radius:20px;cursor:pointer;font-weight:700;font-size:13px;border:1px solid rgba(255,107,107,0.5);color:#ff6b6b;user-select:none">
        <input type="checkbox" id="editPS3" value="PS3" style="display:none"> PS3
      </label>
      <label id="editLabelPS4" style="padding:8px 18px;border-radius:20px;cursor:pointer;font-weight:700;font-size:13px;border:1px solid rgba(77,166,255,0.5);color:#4da6ff;user-select:none">
        <input type="checkbox" id="editPS4" value="PS4" style="display:none"> PS4
      </label>
      <label id="editLabelPS5" style="padding:8px 18px;border-radius:20px;cursor:pointer;font-weight:700;font-size:13px;border:1px solid rgba(178,107,255,0.5);color:#b26bff;user-select:none">
        <input type="checkbox" id="editPS5" value="PS5" style="display:none"> PS5
      </label>
    </div>
    <div style="display:flex;gap:10px">
      <button id="saveEditGame" style="flex:1;height:40px;border-radius:8px;background:linear-gradient(135deg,#00eaff,#00ff9d);color:#000;font-weight:700">Simpan</button>
      <button onclick="closeEditGame()" style="height:40px;padding:0 16px;border-radius:8px;background:rgba(255,255,255,0.06);color:#9fb4c2;border:1px solid rgba(255,255,255,0.1)">Batal</button>
    </div>
  </div>
</div>

<!-- MENU -->
<?php elseif ($page === 'menu'): ?>
<h2>Manajemen Menu Makanan & Minuman</h2>

<form id="addMenuForm" enctype="multipart/form-data">
  <input name="name" placeholder="Nama Item (mis. Indomie Goreng)" required>

  <select name="category" required>
    <option value="">Pilih Kategori</option>
    <option value="makanan">Makanan</option>
    <option value="minuman">Minuman</option>
  </select>

  <input type="number" name="price" placeholder="Harga (Rp)" min="1" required>
  <input type="text" name="description" placeholder="Deskripsi singkat">
  <label class="file-upload-zone">
    <input type="file" name="item_image" accept="image/*">
    <img class="file-upload-preview" style="display:none" alt="preview">
    <div class="file-upload-inner">
      <span class="file-upload-icon"><?= icon('image') ?></span>
      <span class="file-upload-label">Klik atau drag & drop foto item menu</span>
      <span class="file-upload-name">Belum ada file dipilih</span>
    </div>
  </label>
  <button type="submit">Tambah Item</button>
</form>

<?php
$menuItems = mysqli_query($conn, "SELECT * FROM menu_items ORDER BY category, name");
?>
<table class="users-table" style="margin-top:18px">
  <tr>
    <th>Nama</th>
    <th>Kategori</th>
    <th>Harga</th>
    <th>Status</th>
    <th>Aksi</th>
  </tr>
  <?php while ($item = mysqli_fetch_assoc($menuItems)): ?>
  <tr>
    <td><?= htmlspecialchars($item['name']) ?></td>
    <td style="text-transform:capitalize"><?= htmlspecialchars($item['category']) ?></td>
    <td>Rp <?= number_format($item['price'], 0, ',', '.') ?></td>
    <td>
      <?php if ($item['is_available']): ?>
        <span style="color:#00ff9d;font-weight:600">● Tersedia</span>
      <?php else: ?>
        <span style="color:#ff6b6b;font-weight:600">● Stok Habis</span>
      <?php endif; ?>
    </td>
    <td style="display:flex;gap:8px">
      <button onclick="toggleMenuItem(<?= intval($item['id']) ?>)">
        <?= $item['is_available'] ? 'Tutup Stok' : 'Buka Stok' ?>
      </button>
      <button class="btn-danger" onclick="deleteMenuItem(<?= intval($item['id']) ?>, <?= jsArg($item['name']) ?>)">Hapus</button>
    </td>
  </tr>
  <?php endwhile; ?>
</table>

<!-- PESANAN MASUK -->
<?php
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
<h2 style="margin-top:36px;border-top:1px solid rgba(0,234,255,0.15);padding-top:24px">
  Pesanan Masuk
  <?php if ($pendingCount > 0): ?>
    <span style="background:#ff6b6b;color:#fff;font-size:13px;padding:2px 10px;border-radius:20px;margin-left:8px;vertical-align:middle"><?= $pendingCount ?> baru</span>
  <?php endif; ?>
</h2>

<table class="users-table" style="margin-top:14px">
  <tr>
    <th>User</th>
    <th>Item</th>
    <th>Qty</th>
    <th>Total</th>
    <th>Catatan</th>
    <th>Waktu</th>
    <th>Status</th>
    <th>Aksi</th>
  </tr>
  <?php while ($o = mysqli_fetch_assoc($orders)): ?>
  <?php $isDone = $o['status'] === 'selesai'; ?>
  <tr style="<?= $isDone ? 'opacity:0.5' : '' ?>">
    <td><?= htmlspecialchars($o['username']) ?></td>
    <td><?= htmlspecialchars($o['item_name']) ?></td>
    <td><?= intval($o['quantity']) ?>x</td>
    <td>Rp <?= number_format($o['price'] * $o['quantity'], 0, ',', '.') ?></td>
    <td><?= $o['note'] ? htmlspecialchars($o['note']) : '<span style="color:#555">-</span>' ?></td>
    <td style="font-size:13px"><?= date('d M H:i', strtotime($o['created_at'])) ?></td>
    <td>
      <?php if ($isDone): ?>
        <span style="color:#00ff9d;font-weight:600"><?= icon('check') ?> Selesai</span>
      <?php else: ?>
        <span style="color:#ffe600;font-weight:600"><?= icon('clock') ?> Diproses</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if (!$isDone): ?>
        <button onclick="markOrderDone(<?= intval($o['id']) ?>)"><?= icon('check') ?> Sudah Sampai</button>
      <?php else: ?>
        <span style="color:#555e6b;font-size:13px">—</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endwhile; ?>
</table>

<?php endif; ?>
</main>
<script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>"></script>
<?php endif; ?>
</body>
</html>
