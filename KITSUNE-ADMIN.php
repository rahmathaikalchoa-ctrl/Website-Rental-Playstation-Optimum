<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';

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
  <link rel="stylesheet" href="admin.css">
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
  <a href="?page=booking">Booking</a>
  <a href="?page=room">Room</a>
  <a href="?page=akun">Akun</a>
  <a href="?page=game">Game</a>
  <a href="?page=menu">Menu</a>
  <a href="?logout=1">Logout</a>
</nav>

<main>
  <!-- BOOKING -->
<?php if ($page === 'booking'): ?>
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
  <h2>Data Booking (<?= $totalRow ?> total)</h2>
  <table class="users-table">
    <tr><th>Nama</th><th>Room</th><th>Durasi</th><th>Aksi</th></tr>
    <?php while($b = $data->fetch_assoc()): ?>
    <tr>
      <td><?= htmlspecialchars($b['customer_name']) ?></td>
      <td><?= intval($b['room_id']) ?></td>
      <td><?= intval($b['duration']) ?> jam</td>
      <td><button onclick="hapusBooking(<?= intval($b['id']) ?>)">Hapus</button></td>
    </tr>
    <?php endwhile; ?>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($pageNum > 1): ?>
      <a href="?page=booking&p=<?= $pageNum - 1 ?>">&laquo; Prev</a>
    <?php endif; ?>
    <span>Hal <?= $pageNum ?> / <?= $totalPages ?></span>
    <?php if ($pageNum < $totalPages): ?>
      <a href="?page=booking&p=<?= $pageNum + 1 ?>">Next &raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

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
        <button onclick="toggleRole(<?= intval($u['id']) ?>, '<?= htmlspecialchars($u['username']) ?>')">
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
    <option value="__custom__" style="color:#00eaff;font-weight:700">➕ Tambah genre baru...</option>
  </select>
  <div id="customGenreWrap" class="custom-genre-wrap" style="display:none">
    <input type="text" id="customGenreInput" placeholder="Nama genre baru (mis. Horror, Puzzle...)" autocomplete="off">
    <button type="button" id="cancelCustomGenre" class="btn-cancel-genre">✕</button>
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
      <span class="file-upload-icon">🖼️</span>
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
      <button onclick="openEditGame(<?= intval($g['id']) ?>,'<?= htmlspecialchars($g['title'], ENT_QUOTES) ?>',<?= htmlspecialchars(json_encode($consolesArr), ENT_QUOTES) ?>)">Edit Console</button>
      <button class="btn-danger" onclick="deleteGame(<?= intval($g['id']) ?>, '<?= htmlspecialchars($g['title'], ENT_QUOTES) ?>')">Hapus</button>
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
      <span class="file-upload-icon">🖼️</span>
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
      <button class="btn-danger" onclick="deleteMenuItem(<?= intval($item['id']) ?>, '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')">Hapus</button>
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
        <span style="color:#00ff9d;font-weight:600">✓ Selesai</span>
      <?php else: ?>
        <span style="color:#ffe600;font-weight:600">⏳ Diproses</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if (!$isDone): ?>
        <button onclick="markOrderDone(<?= intval($o['id']) ?>)">✓ Sudah Sampai</button>
      <?php else: ?>
        <span style="color:#555e6b;font-size:13px">—</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endwhile; ?>
</table>

<?php endif; ?>
</main>
<script src="admin.js"></script>
<?php endif; ?>
</body>
</html>
