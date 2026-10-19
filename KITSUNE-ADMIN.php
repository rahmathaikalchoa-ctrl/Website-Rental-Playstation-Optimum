<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/queue_lib.php';
require_once __DIR__ . '/booking_lib.php';
require_once __DIR__ . '/riwayat_filter.php';
require __DIR__ . '/icons.php';

// argumen JS aman buat onclick, htmlspecialchars aja gak cukup (&#039; balik jadi ')
function jsArg($v) {
  return htmlspecialchars(json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES);
}

if (isset($_POST['login'])) {
  $inputUser = trim($_POST['username'] ?? '');
  if (!checkRateLimit('admin_login_attempts', 5, 300, $inputUser)) {
    $error = "Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.";
  } else {
    // di-trim kayak login.php, hash dibuat dari password yg udah di-trim
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
      $_SESSION['admin_pw_sig'] = passwordSig($adminUser['password']);
      header("Location: KITSUNE-ADMIN.php");
      exit;
    } else {
      $error = "Username/password salah atau akun tidak memiliki akses admin";
    }
  }
}

// token CSRF buat semua aksi admin
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

// logout pakai POST + token biar gak bisa dipicu dari situs lain
if (isset($_POST['logout']) && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
  // cuma sesi admin yg keluar, login user tetap
  unset($_SESSION['admin'], $_SESSION['admin_id'], $_SESSION['admin_username']);
  session_regenerate_id(true);
  header("Location: KITSUNE-ADMIN.php");
  exit;
}

$isAdmin = isAdminSession($conn);

$navItems = ['dashboard' => 'Dashboard', 'booking' => 'Booking', 'jadwal' => 'Jadwal', 'riwayat' => 'Riwayat Booking',
             'room' => 'Ruangan', 'harga' => 'Harga & Paket', 'akun' => 'Akun', 'game' => 'Game', 'menu' => 'Menu'];
$page    = $_GET['page'] ?? 'dashboard';
if (!isset($navItems[$page])) $page = 'dashboard';
$perPage = 20;
$pageNum = max(1, intval($_GET['p'] ?? 1));
$offset  = ($pageNum - 1) * $perPage;
?>

<!DOCTYPE html>
<html>
<head>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf']) ?>">
  <title>Admin Optimum Playzone</title>
  <link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body data-open-sec="<?= BOOKING_OPEN_SEC ?>" data-close-sec="<?= BOOKING_CLOSE_SEC ?>" data-pricing="<?= htmlspecialchars(pricingJson()) ?>">

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
  <?php
    // jumlah pesanan menu yg belum diantar
    $pendingOrders = intval(mysqli_fetch_assoc(mysqli_query($conn,
      "SELECT COUNT(*) AS n FROM menu_orders WHERE status = 'pending'"))['n']);
  ?>
  <?php foreach ($navItems as $key => $label): ?>
    <a href="?page=<?= $key ?>" data-nav="<?= $key ?>"<?= $page === $key ? ' class="active"' : '' ?>><?= htmlspecialchars($label) ?>
      <?php if ($key === 'menu' && $pendingOrders > 0): ?><span class="nav-badge" title="Pesanan menu belum diantar"><?= $pendingOrders ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <form method="post" class="logout-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <button type="submit" name="logout" value="1">Logout</button>
  </form>
</nav>

<main>
  <!-- DASHBOARD -->
<?php if ($page === 'dashboard'): ?>
  <?php
    releaseNoShows($conn);
    $now        = time();
    $todayStart = strtotime('today', $now);
    $monthStart = strtotime(date('Y-m-01', $now));
    $monthEnd   = strtotime('+1 month', $monthStart);
    $bulanNama  = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $bulanIni   = $bulanNama[intval(date('n', $now)) - 1] . ' ' . date('Y', $now);

    // pendapatan = uang masuk kasir (booking_payments) per waktu bayar
    // booking batal gak dihitung
    $revenueSince = function ($from) use ($conn) {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(p.amount), 0) AS s FROM booking_payments p JOIN bookings b ON b.id = p.booking_id WHERE b.payment_status <> 'cancelled' AND p.paid_at >= ?");
      $stmt->bind_param("i", $from);
      $stmt->execute();
      $s = intval($stmt->get_result()->fetch_assoc()['s']);
      $stmt->close();
      return $s;
    };
    $revToday = $revenueSince($todayStart);
    $revMonth = $revenueSince($monthStart);

    $tomorrow = $todayStart + 86400;
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM bookings WHERE payment_status <> 'cancelled' AND start_time >= ? AND start_time < ?");
    $stmt->bind_param("ii", $todayStart, $tomorrow);
    $stmt->execute();
    $bookToday = intval($stmt->get_result()->fetch_assoc()['n']);
    $stmt->close();

    $monthStartSql = date('Y-m-d 00:00:00', $monthStart);
    $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity * unit_price), 0) AS s FROM menu_orders WHERE status = 'selesai' AND created_at >= ?");
    $stmt->bind_param("s", $monthStartSql);
    $stmt->execute();
    $menuMonth = intval($stmt->get_result()->fetch_assoc()['s']);
    $stmt->close();

    // pendapatan 14 hari, hari kosong tetap 0
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
      $d = strtotime("-$i day", $todayStart);
      $days[date('Y-m-d', $d)] = ['ts' => $d, 'sum' => 0, 'count' => 0];
    }
    $chartFrom = reset($days)['ts'];
    $stmt = $conn->prepare("
      SELECT DATE(FROM_UNIXTIME(p.paid_at)) AS d, SUM(p.amount) AS s, COUNT(*) AS n
      FROM booking_payments p JOIN bookings b ON b.id = p.booking_id
      WHERE b.payment_status <> 'cancelled' AND p.paid_at >= ?
      GROUP BY d
    ");
    $stmt->bind_param("i", $chartFrom);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      if (isset($days[$row['d']])) {
        $days[$row['d']]['sum'] = intval($row['s']);
        $days[$row['d']]['count'] = intval($row['n']);
      }
    }
    $stmt->close();
    $maxDay = max(1, max(array_column($days, 'sum')));

    // ringkasan booking bulan ini (per jadwal main)
    $stmt = $conn->prepare("SELECT payment_status, source, COUNT(*) AS n FROM bookings WHERE start_time >= ? AND start_time < ? GROUP BY payment_status, source");
    $stmt->bind_param("ii", $monthStart, $monthEnd);
    $stmt->execute();
    $byStatus = ['paid' => 0, 'unpaid' => 0, 'cancelled' => 0];
    $bySource = ['online' => 0, 'walkin' => 0];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $byStatus[$row['payment_status']] = ($byStatus[$row['payment_status']] ?? 0) + intval($row['n']);
      if ($row['payment_status'] !== 'cancelled') $bySource[$row['source']] += intval($row['n']);
    }
    $stmt->close();
    $totalMonth = max(1, array_sum($byStatus));
    $totalSource = max(1, array_sum($bySource));

    $stmt = $conn->prepare("
      SELECT r.title, r.console_type, r.console_model, COUNT(b.id) AS n, COALESCE(SUM(b.amount_paid), 0) AS s
      FROM bookings b JOIN rooms r ON r.id = b.room_id
      WHERE b.payment_status <> 'cancelled' AND b.start_time >= ? AND b.start_time < ?
      GROUP BY r.id ORDER BY n DESC, s DESC LIMIT 5
    ");
    $stmt->bind_param("ii", $monthStart, $monthEnd);
    $stmt->execute();
    $topRooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $maxRoom = max(1, max(array_column($topRooms, 'n') ?: [0]));

    $rp = fn($n) => 'Rp' . number_format($n, 0, ',', '.');
    $hariPendek = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];
  ?>
  <div class="page-head">
    <div>
      <h2>Dashboard</h2>
      <p class="page-sub">Ringkasan pendapatan dan booking. Pendapatan dihitung dari pembayaran yang sudah diterima kasir.</p>
    </div>
    <span class="clock-chip"><?= icon('clock') ?> <?= date('d', $now) ?> <?= $bulanNama[intval(date('n', $now)) - 1] ?></span>
  </div>

  <div class="stat-row">
    <div class="stat-card stat-kosong"><span class="stat-num"><?= $rp($revToday) ?></span><span class="stat-label">Pendapatan hari ini</span></div>
    <div class="stat-card stat-tunggu"><span class="stat-num"><?= $rp($revMonth) ?></span><span class="stat-label">Pendapatan <?= $bulanIni ?></span></div>
    <div class="stat-card stat-service"><span class="stat-num"><?= $bookToday ?></span><span class="stat-label">Booking jadwal hari ini</span></div>
    <div class="stat-card stat-dipakai"><span class="stat-num"><?= $rp($menuMonth) ?></span><span class="stat-label">Penjualan menu <?= $bulanIni ?></span></div>
  </div>

  <div class="dash-grid">
    <section class="panel-card dash-chart-card">
      <h3 class="panel-title"><?= icon('clipboard') ?> Pendapatan 14 hari terakhir</h3>
      <div class="bar-chart" role="img" aria-label="Grafik batang pendapatan harian 14 hari terakhir">
        <?php foreach ($days as $date => $d): ?>
          <?php
            $h = $d['sum'] > 0 ? max(3, round($d['sum'] / $maxDay * 100)) : 0;
            $label = $hariPendek[intval(date('w', $d['ts']))] . ' ' . date('j', $d['ts']);
            $tip = date('d', $d['ts']) . ' ' . $bulanNama[intval(date('n', $d['ts'])) - 1] . ' · ' . $rp($d['sum']) . ' · ' . $d['count'] . ' pembayaran';
          ?>
          <div class="bar-col<?= $date === date('Y-m-d', $now) ? ' is-today' : '' ?>" data-tip="<?= htmlspecialchars($tip) ?>" tabindex="0">
            <div class="bar-track"><div class="bar" style="height:<?= $h ?>%"></div></div>
            <span class="bar-label"><?= $label ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="chart-scale">Tertinggi: <?= $rp($maxDay > 1 ? $maxDay : 0) ?> per hari</p>
      <details class="chart-table">
        <summary>Lihat tabel data</summary>
        <div class="table-wrap">
          <table class="users-table">
            <tr><th>Tanggal</th><th>Pembayaran</th><th>Pendapatan</th></tr>
            <?php foreach (array_reverse($days, true) as $d): ?>
              <tr><td><?= date('d', $d['ts']) . ' ' . $bulanNama[intval(date('n', $d['ts'])) - 1] ?></td><td><?= $d['count'] ?></td><td><?= $rp($d['sum']) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      </details>
    </section>

    <section class="panel-card">
      <h3 class="panel-title"><?= icon('check') ?> Booking <?= $bulanIni ?></h3>
      <?php
        $statusRows = [
          ['Lunas', $byStatus['paid'], 'st-paid'],
          ['Belum bayar', $byStatus['unpaid'], 'st-unpaid'],
          ['Dibatalkan', $byStatus['cancelled'], 'st-cancel'],
        ];
      ?>
      <ul class="hbar-list">
        <?php foreach ($statusRows as [$lbl, $n, $cls]): ?>
          <li class="<?= $cls ?>">
            <div class="hbar-head"><span><?= $lbl ?></span><strong><?= $n ?></strong></div>
            <div class="hbar-track"><div class="hbar" style="width:<?= round($n / $totalMonth * 100) ?>%"></div></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <h4 class="dash-sub">Sumber booking (tidak termasuk batal)</h4>
      <div class="split-bar" role="img" aria-label="Online <?= $bySource['online'] ?>, Offline <?= $bySource['walkin'] ?>">
        <div class="split-online" style="width:<?= round($bySource['online'] / $totalSource * 100) ?>%"></div>
        <div class="split-offline" style="width:<?= round($bySource['walkin'] / $totalSource * 100) ?>%"></div>
      </div>
      <div class="split-legend">
        <span><i class="dot dot-online"></i> Online <strong><?= $bySource['online'] ?></strong></span>
        <span><i class="dot dot-offline"></i> Offline <strong><?= $bySource['walkin'] ?></strong></span>
      </div>
    </section>

    <section class="panel-card">
      <h3 class="panel-title"><?= icon('gamepad') ?> Ruangan terlaris <?= $bulanIni ?></h3>
      <?php if (!$topRooms): ?>
        <p class="queue-empty">Belum ada booking bulan ini.</p>
      <?php else: ?>
        <ol class="rank-list">
          <?php foreach ($topRooms as $i => $r): ?>
            <li>
              <span class="rank-no"><?= $i + 1 ?></span>
              <div class="rank-info">
                <div class="hbar-head">
                  <span><?= htmlspecialchars($r['title']) ?> <span class="console-chip c-<?= strtolower(htmlspecialchars($r['console_type'])) ?>"><?= htmlspecialchars($r['console_model']) ?></span></span>
                  <strong><?= intval($r['n']) ?> booking</strong>
                </div>
                <div class="hbar-track"><div class="hbar" style="width:<?= round(intval($r['n']) / $maxRoom * 100) ?>%"></div></div>
                <span class="cell-sub">Diterima <?= $rp(intval($r['s'])) ?></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </section>
  </div>
  <div id="chartTip" class="chart-tip" hidden></div>

  <!-- JADWAL: semua reservasi per ruangan dalam satu hari -->
<?php elseif ($page === 'jadwal'): ?>
  <?php
    releaseNoShows($conn);
    $now = time();
    $bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $dayTabs = [];
    foreach (['Hari ini', 'Besok', 'Lusa'] as $i => $lbl) {
      $ts = strtotime("+$i day", $now);
      $dayTabs[date('Y-m-d', $ts)] = $lbl . ' · ' . date('j', $ts) . ' ' . $bulan[intval(date('n', $ts)) - 1];
    }
    $day = $_GET['d'] ?? date('Y-m-d', $now);
    if (!isset($dayTabs[$day])) $day = date('Y-m-d', $now);
    $dayStart = strtotime("$day 00:00:00");
    $openTs   = $dayStart + BOOKING_OPEN_SEC;
    $closeTs  = $dayStart + BOOKING_CLOSE_SEC;
    $slotSec  = 1800;
    $slots    = intdiv(BOOKING_CLOSE_SEC - BOOKING_OPEN_SEC, $slotSec);

    $schedRooms = mysqli_query($conn, "SELECT id, title, tier, console_model, status, price, nintendo_extra FROM rooms ORDER BY id")
      ->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare("
      SELECT id, room_id, user_id, customer_name, phone, order_code, source, start_time, end_time, duration,
             total_price, amount_paid, payment_status, expires_at, with_nintendo, is_student, discount,
             lock_room, (SELECT title FROM rooms ro WHERE ro.id = bookings.relocated_from) AS relocated_from
      FROM bookings
      WHERE payment_status <> 'cancelled' AND start_time < ? AND end_time > ?
      ORDER BY start_time
    ");
    $stmt->bind_param("ii", $closeTs, $openTs);
    $stmt->execute();
    $byRoom = [];
    $sum = ['total' => 0, 'wait' => 0, 'unpaid' => 0];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $b) {
      $byRoom[intval($b['room_id'])][] = $b;
      $sum['total']++;
      if ($b['expires_at'] !== null) $sum['wait']++;
      if ($b['payment_status'] !== 'paid') $sum['unpaid']++;
    }
    $stmt->close();
    $roomById = array_column($schedRooms, null, 'id');
    $fmtRp = fn($n) => 'Rp' . number_format($n, 0, ',', '.');
  ?>
  <div class="page-head">
    <div>
      <h2>Jadwal Ruangan</h2>
      <p class="page-sub">Semua reservasi per ruangan. Klik booking untuk Hadir / Lunas / Batal, klik slot kosong untuk membuat reservasi.</p>
    </div>
    <span class="clock-chip"><?= icon('clock') ?> <?= date('H:i', $now) ?></span>
  </div>

  <div class="form-tabs sched-days" role="tablist">
    <?php foreach ($dayTabs as $val => $lbl): ?>
      <a class="form-tab<?= $val === $day ? ' active' : '' ?>" href="?page=jadwal&amp;d=<?= $val ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <div class="stat-row">
    <div class="stat-card stat-kosong"><span class="stat-num"><?= $sum['total'] ?></span><span class="stat-label">Booking hari itu</span></div>
    <div class="stat-card stat-tunggu"><span class="stat-num"><?= $sum['wait'] ?></span><span class="stat-label">Menunggu datang</span></div>
    <div class="stat-card stat-dipakai"><span class="stat-num"><?= $sum['unpaid'] ?></span><span class="stat-label">Belum lunas</span></div>
  </div>

  <div class="sched-legend">
    <span><i class="sched-dot sched-wait"></i> Menunggu datang</span>
    <span><i class="sched-dot sched-unpaid"></i> Hadir, belum lunas</span>
    <span><i class="sched-dot sched-paid"></i> Lunas</span>
  </div>

  <div class="sched-wrap">
    <div class="sched-grid" style="--slots: <?= $slots ?>">
      <div class="sched-row sched-head">
        <div class="sched-label">Ruangan</div>
        <?php for ($i = 0; $i < $slots; $i++): ?>
          <div class="sched-time" style="grid-column: <?= $i + 2 ?>"><?= $i % 2 === 0 ? date('H:i', $openTs + $i * $slotSec) : '' ?></div>
        <?php endfor; ?>
      </div>

      <?php foreach ($schedRooms as $r): ?>
      <?php $rid = intval($r['id']); $inService = $r['status'] !== 'available'; ?>
      <div class="sched-row<?= $inService ? ' sched-off' : '' ?>">
        <div class="sched-label"><?= htmlspecialchars($r['title']) ?>
          <span class="cell-sub"><?= htmlspecialchars($r['tier'] . ' · ' . $r['console_model']) ?><?= $inService ? ' · Perawatan' : '' ?></span>
        </div>
        <?php for ($i = 0; $i < $slots; $i++): ?>
          <?php $slotTs = $openTs + $i * $slotSec; ?>
          <?php if (!$inService && $slotTs + 3600 <= $closeTs && $slotTs >= $now): ?>
            <a class="sched-slot" style="grid-column: <?= $i + 2 ?>" title="Reservasi <?= htmlspecialchars($r['title']) ?> jam <?= date('H:i', $slotTs) ?>"
               href="?page=booking&amp;tab=reserveForm&amp;room=<?= $rid ?>&amp;date=<?= $day ?>&amp;time=<?= date('H:i', $slotTs) ?>"></a>
          <?php else: ?>
            <span class="sched-slot is-off" style="grid-column: <?= $i + 2 ?>"></span>
          <?php endif; ?>
        <?php endfor; ?>

        <?php foreach ($byRoom[$rid] ?? [] as $b): ?>
          <?php
            $bs = max(intval($b['start_time']), $openTs);
            $be = min(intval($b['end_time']), $closeTs);
            $col  = intdiv($bs - $openTs, $slotSec) + 2;
            $span = max(1, (int) ceil(($be - $openTs) / $slotSec) + 2 - $col);
            $waiting = $b['expires_at'] !== null;
            $paid    = $b['payment_status'] === 'paid';
            $state   = $waiting ? 'sched-wait' : ($paid ? 'sched-paid' : 'sched-unpaid');
            $due     = intval($b['total_price']) - intval($b['amount_paid']);
            // Lunas ikut ditulis di bar karena warna nunggu datang lebih penting
            $tags    = array_filter([$b['source'] === 'walkin' ? 'Offline' : 'Online', $waiting && $paid ? 'Lunas' : '',
                                     $b['with_nintendo'] ? '+Nintendo' : '', $b['is_student'] ? 'Pelajar' : '',
                                     $b['relocated_from'] ? 'Dipindah dari ' . $b['relocated_from'] : '',
                                     $b['lock_room'] ? 'Ruangan dikunci' : '']);
            $detail = [
              'id' => intval($b['id']), 'name' => $b['customer_name'], 'phone' => $b['phone'] ?? '',
              'code' => $b['order_code'] ?? '-', 'room' => $r['title'],
              'time' => date('d/m H:i', intval($b['start_time'])) . ' – ' . date('H:i', intval($b['end_time'])) . ' (' . intval($b['duration']) . ' jam)',
              'tags' => implode(' · ', $tags),
              'total' => $fmtRp($b['total_price']) . (intval($b['discount']) > 0 ? ' (potongan poin ' . $fmtRp($b['discount']) . ')' : ''),
              'paid' => $fmtRp($b['amount_paid']), 'due' => $due,
              'status' => $waiting ? 'Menunggu datang' : ($paid ? 'Lunas' : (intval($b['amount_paid']) > 0 ? 'Kurang ' . $fmtRp($due) : 'Belum bayar')),
              'canCheckin' => $waiting && intval($b['start_time']) - $now <= BOOKING_CHECKIN_SEC && intval($b['end_time']) > $now,
              'canPay'     => !$paid,
              'canStudent' => intval($b['amount_paid']) === 0
                              && ($b['is_student'] || studentEligible($roomById[$rid], (bool) $b['with_nintendo'], intval($b['start_time']))),
              'isStudent'  => (bool) $b['is_student'],
              'canCancel'  => intval($b['end_time']) > $now,
              'canMove'    => $waiting && intval($b['end_time']) > $now,
              'hasDiscount' => intval($b['discount']) > 0,
              'canRedeem'  => $b['user_id'] !== null && !$paid
                              && (intval($b['discount']) > 0 || pointsBalance($conn, $b['user_id']) >= POINTS_PER_FREE_HOUR),
              'amountPaid' => intval($b['amount_paid']),
            ];
          ?>
          <button type="button" class="sched-bar <?= $state ?>" style="grid-column: <?= $col ?> / span <?= $span ?>"
                  onclick="openScheduleBooking(<?= jsArg($detail) ?>)">
            <strong><?= htmlspecialchars($b['customer_name']) ?></strong>
            <span><?= date('H:i', intval($b['start_time'])) ?>–<?= date('H:i', intval($b['end_time'])) ?> · <?= htmlspecialchars(implode(' · ', $tags)) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($day === date('Y-m-d', $now) && $now > $openTs && $now < $closeTs): ?>
        <div class="sched-now" style="--pos: <?= round(($now - $openTs) / ($closeTs - $openTs), 4) ?>" title="Sekarang <?= date('H:i', $now) ?>"></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- MODAL DETAIL BOOKING DI JADWAL -->
  <div id="scheduleModal" class="modal-backdrop" style="display:none">
    <div class="modal-card">
      <h3 class="panel-title" data-sched="name"></h3>
      <p class="page-sub" data-sched="sub"></p>
      <dl class="sched-detail">
        <dt>Ruangan</dt><dd data-sched="room"></dd>
        <dt>Jadwal</dt><dd data-sched="time"></dd>
        <dt>No. HP</dt><dd data-sched="phone"></dd>
        <dt>Total</dt><dd data-sched="total"></dd>
        <dt>Dibayar</dt><dd data-sched="paid"></dd>
        <dt>Status</dt><dd data-sched="status"></dd>
      </dl>
      <div class="modal-actions sched-actions" data-sched="actions"></div>
    </div>
  </div>

  <!-- RIWAYAT BOOKING -->
<?php elseif ($page === 'riwayat'): ?>
  <?php
    // batalin dulu yg lewat batas check-in biar status bener
    releaseNoShows($conn);
    $f = riwayatFilter($_GET);

    $stmt = $conn->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN b.payment_status <> 'cancelled' THEN b.amount_paid ELSE 0 END), 0) AS paid FROM bookings b {$f['where']}");
    if ($f['types'] !== '') $stmt->bind_param($f['types'], ...$f['params']);
    $stmt->execute();
    $sum = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $totalRow = intval($sum['n']);
    $totalPages = max(1, (int) ceil($totalRow / $perPage));
    $pageNum = min($pageNum, $totalPages);
    $offset  = ($pageNum - 1) * $perPage;

    $stmt = $conn->prepare("
      SELECT b.*, r.title AS room_title
      FROM bookings b
      LEFT JOIN rooms r ON r.id = b.room_id
      {$f['where']}
      ORDER BY b.created_at DESC LIMIT ? OFFSET ?
    ");
    // mode cetak: semua baris sesuai filter, tetap dibatasi
    $printMode = isset($_GET['print']);
    $types = $f['types'] . 'ii';
    $params = array_merge($f['params'], $printMode ? [2000, 0] : [$perPage, $offset]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result();
    $stmt->close();

    $fv = $f['values'];
    $qs = $f['query'] !== '' ? '&' . $f['query'] : '';

    $payLabel = [
      'unpaid'    => ['Belum bayar', 'pay-unpaid'],
      'paid'      => ['Lunas', 'pay-paid'],
      'cancelled' => ['Dibatalkan', 'pay-cancelled'],
    ];
  ?>
  <div class="page-head">
    <div>
      <h2>Riwayat Booking</h2>
      <p class="page-sub"><?= $totalRow ?> booking<?= $f['where'] ? ' sesuai filter' : ' tercatat' ?> · total diterima Rp<?= number_format(intval($sum['paid']), 0, ',', '.') ?>. Booking yang dibatalkan tetap disimpan untuk arsip.</p>
    </div>
    <div class="head-actions no-print">
      <a class="btn-ghost-sm btn-link" href="admin_export.php?<?= htmlspecialchars($f['query']) ?>"><?= icon('clipboard') ?> Export CSV</a>
      <a class="btn-ghost-sm btn-link" href="?page=riwayat&amp;print=1<?= htmlspecialchars($qs) ?>">Cetak</a>
    </div>
  </div>

  <?php if ($printMode): ?>
  <p class="page-sub no-print">Mode cetak: menampilkan <?= min($totalRow, 2000) ?> booking sesuai filter. <a href="?page=riwayat<?= htmlspecialchars($qs) ?>">Kembali ke Riwayat</a></p>
  <script>window.addEventListener("load", () => window.print());</script>
  <?php endif; ?>
  <form method="get" class="filter-bar no-print">
    <input type="hidden" name="page" value="riwayat">
    <label class="field">
      <span>Jadwal dari</span>
      <input type="date" name="from" value="<?= htmlspecialchars($fv['from']) ?>">
    </label>
    <label class="field">
      <span>Sampai</span>
      <input type="date" name="to" value="<?= htmlspecialchars($fv['to']) ?>">
    </label>
    <label class="field">
      <span>Status</span>
      <select name="status">
        <option value="">Semua</option>
        <?php foreach ($payLabel as $k => [$lbl]): ?>
          <option value="<?= $k ?>" <?= $fv['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field field-grow">
      <span>Nama / kode booking</span>
      <input type="search" name="q" value="<?= htmlspecialchars($fv['q']) ?>" placeholder="mis. Budi atau GZ-51">
    </label>
    <div class="filter-actions">
      <button type="submit" class="btn-primary">Terapkan</button>
      <?php if ($f['where']): ?><a class="btn-ghost-sm btn-link" href="?page=riwayat">Reset</a><?php endif; ?>
    </div>
  </form>
  <div class="table-wrap">
    <table class="users-table">
      <tr><th>Konsumen</th><th>Ruangan</th><th>Jadwal</th><th>Total</th><th>Status</th><th class="col-actions">Aksi</th></tr>
      <?php if ($data->num_rows === 0): ?>
        <tr><td colspan="6" class="empty-row">Belum ada data booking.</td></tr>
      <?php endif; ?>
      <?php while($b = $data->fetch_assoc()): ?>
      <?php
        $isCancelled = $b['payment_status'] === 'cancelled';
        [$pl, $pc] = $payLabel[$b['payment_status']] ?? [$b['payment_status'], 'pay-unpaid'];
        // batal + expires_at keisi = batal otomatis gak dateng
        if ($isCancelled && $b['expires_at'] !== null) $pl = 'Batal · tidak hadir';
      ?>
      <tr class="<?= $isCancelled ? 'row-cancelled' : '' ?>">
        <td class="cell-main"><?= htmlspecialchars($b['customer_name']) ?>
          <span class="cell-sub"><?= htmlspecialchars($b['order_code'] ?? '-') ?> · <?= $b['source'] === 'walkin' ? 'Offline' : 'Online' ?></span>
        </td>
        <td><?= htmlspecialchars($b['room_title'] ?? '-') ?>
          <?php if ($b['with_nintendo'] || $b['is_student'] || $b['discount'] > 0): ?>
            <span class="cell-sub"><?= implode(' · ', array_filter([$b['with_nintendo'] ? '+Nintendo' : '', $b['is_student'] ? 'Harga pelajar' : '',
              $b['discount'] > 0 ? 'Poin -Rp' . number_format(intval($b['discount']), 0, ',', '.') : ''])) ?></span>
          <?php endif; ?>
        </td>
        <?php $st = intval($b['start_time']); ?>
        <td><?= date(date('Y', $st) === date('Y') ? 'd M' : 'd M Y', $st) ?>
          <span class="cell-sub"><?= date('H:i', intval($b['start_time'])) ?> – <?= date('H:i', intval($b['end_time'])) ?> · <?= intval($b['duration']) ?> jam</span>
        </td>
        <td>Rp<?= number_format(intval($b['total_price']), 0, ',', '.') ?></td>
        <td><span class="pay-badge <?= $pc ?>"><?= $pl ?></span></td>
        <td class="col-actions">
          <div class="row-actions">
            <?php if (!$isCancelled && $b['payment_status'] !== 'paid'): ?>
              <button class="btn-paid-sm" onclick="markPaid(<?= intval($b['id']) ?>, <?= jsArg($b['customer_name']) ?>, <?= intval($b['total_price']) - intval($b['amount_paid']) ?>)">Lunas</button>
            <?php endif; ?>
            <?php if (!$isCancelled && intval($b['end_time']) > time()): ?>
              <button class="btn-icon" title="Batalkan booking" aria-label="Batalkan booking" onclick="cancelBooking(<?= intval($b['id']) ?>, <?= jsArg($b['customer_name']) ?>, <?= intval($b['amount_paid']) ?>)"><?= icon('x') ?></button>
            <?php elseif ($isCancelled || $b['payment_status'] === 'paid'): ?>
              <span class="muted-dash">—</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>

  <?php if ($totalPages > 1 && !$printMode): ?>
  <div class="pagination">
    <?php if ($pageNum > 1): ?>
      <a href="?page=riwayat&p=<?= $pageNum - 1 ?><?= htmlspecialchars($qs) ?>">&laquo; Prev</a>
    <?php endif; ?>
    <span>Hal <?= $pageNum ?> / <?= $totalPages ?></span>
    <?php if ($pageNum < $totalPages): ?>
      <a href="?page=riwayat&p=<?= $pageNum + 1 ?><?= htmlspecialchars($qs) ?>">Next &raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- BOOKING (kasir: konsumen offline + daftar tunggu) -->
<?php elseif ($page === 'booking'): ?>
  <?php
    processQueue($conn);
    $now = time();
    $dayEnd = strtotime(date('Y-m-d', $now)) + 24 * 3600;

    $curStmt  = $conn->prepare("
      SELECT id, user_id, customer_name, source, start_time, end_time, expires_at, payment_status, total_price, amount_paid,
             with_nintendo, is_student, discount
      FROM bookings
      WHERE room_id = ? AND payment_status <> 'cancelled' AND start_time <= ? AND end_time > ? LIMIT 1
    ");
    $nextStmt = $conn->prepare("
      SELECT id, start_time, customer_name, expires_at FROM bookings
      WHERE room_id = ? AND payment_status <> 'cancelled' AND start_time > ? AND start_time < ?
      ORDER BY start_time LIMIT 1
    ");

    $rooms = [];
    $roomOptions = [];
    $stat = ['kosong' => 0, 'dipakai' => 0, 'menunggu' => 0, 'service' => 0];
    $roomRes = mysqli_query($conn, "
      SELECT id, title, tier, console_type, console_model, price, weekend_extra, nintendo_extra, status,
             " . ROOM_CLASS_SQL . " AS room_class
      FROM rooms ORDER BY id
    ");
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
      // reservasi yg belum dateng gak dihitung dipakai
      if ($r['state'] === 'dipakai' && $r['cur']['expires_at'] !== null) $stat['menunggu']++;
      else $stat[$r['state']]++;

      if ($r['status'] === 'available') {
        $roomOptions[] = ['id' => $rid, 'title' => $r['title'], 'class' => $r['room_class'],
                          'price' => intval($r['price']), 'weekendExtra' => intval($r['weekend_extra']),
                          'nintendoExtra' => $r['nintendo_extra'] !== null ? intval($r['nintendo_extra']) : 0,
                          'free' => $r['state'] === 'kosong'];
      }
      $rooms[] = $r;
    }
    $curStmt->close();
    $nextStmt->close();

    // kelas ruangan aktif urut tarif
    $classes = roomClasses($conn);
    $queueBy = array_fill_keys(array_column($classes, 'room_class'), []);
    $qRes = mysqli_query($conn, "SELECT * FROM booking_queue WHERE status = 'waiting' ORDER BY created_at, id");
    while ($q = mysqli_fetch_assoc($qRes)) $queueBy[$q['room_class']][] = $q;
    $totalWaiting = array_sum(array_map('count', $queueBy));

    $stateLabel = ['kosong' => 'Kosong', 'dipakai' => 'Dipakai', 'service' => 'In Service'];
    $srcLabel   = fn($s) => $s === 'walkin' ? 'Offline' : 'Online';

    // tanggal reservasi: hari ini s/d lusa
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
    <div class="stat-card stat-dipakai"><span class="stat-num"><?= $stat['dipakai'] ?></span><span class="stat-label">Sedang dipakai<?= $stat['menunggu'] ? ' · ' . $stat['menunggu'] . ' menunggu datang' : '' ?></span></div>
    <div class="stat-card stat-tunggu"><span class="stat-num"><?= $totalWaiting ?></span><span class="stat-label">Daftar tunggu</span></div>
    <div class="stat-card stat-service"><span class="stat-num"><?= $stat['service'] ?></span><span class="stat-label">In service</span></div>
  </div>

  <div class="booking-grid">
    <div class="panel-card" id="offlinePanel"
         data-rooms="<?= htmlspecialchars(json_encode($roomOptions), ENT_QUOTES) ?>"
         data-classes="<?= htmlspecialchars(json_encode(array_combine(array_column($classes, 'room_class'), array_map(fn($c) => [
           'price' => intval($c['price']), 'weekendExtra' => intval($c['weekend_extra']),
           'nintendoExtra' => $c['nintendo_extra'] !== null ? intval($c['nintendo_extra']) : 0,
         ], $classes))), ENT_QUOTES) ?>">
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

        <label class="field">
          <span>Kelas ruangan</span>
          <select name="room_class" required>
            <?php foreach ($classes as $c): ?>
              <option value="<?= htmlspecialchars($c['room_class']) ?>"><?= htmlspecialchars($c['room_class']) ?> · Rp<?= number_format($c['price'], 0, ',', '.') ?>/jam</option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span>Ruangan</span>
          <select name="room_id">
            <option value="0">Otomatis (sesuai urutan)</option>
            <?php foreach ($roomOptions as $o): ?>
              <option value="<?= $o['id'] ?>" data-class="<?= htmlspecialchars($o['class']) ?>" <?= $o['free'] ? '' : 'disabled' ?>>
                <?= htmlspecialchars($o['title']) ?><?= $o['free'] ? '' : ' (dipakai)' ?>
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

        <div class="check-row">
          <label class="check-opt" data-nintendo hidden><input type="checkbox" name="nintendo" value="1"> Tambah Nintendo Switch</label>
          <label class="check-opt"><input type="checkbox" name="student" value="1"> Harga pelajar <em>(Senin-Jumat, cek kartu)</em></label>
        </div>

        <div class="estimate">
          <span>Estimasi biaya</span>
          <strong data-estimate>-</strong>
        </div>
        <p class="form-note">Mulai sekarang dan langsung ditandai lunas. Bila ruangan penuh, konsumen masuk daftar tunggu dengan pilihan Nintendo/pelajar yang sama.</p>

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
              <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['title']) ?></option>
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

        <div class="check-row">
          <label class="check-opt" data-nintendo hidden><input type="checkbox" name="nintendo" value="1"> Tambah Nintendo Switch</label>
          <label class="check-opt"><input type="checkbox" name="student" value="1"> Harga pelajar <em>(Senin-Jumat, cek kartu)</em></label>
        </div>

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
          // sisa yg lunas tapi lewat batas check-in (yg belum bayar udah batal otomatis)
          $noShow = $waitingArrival && intval($cur['expires_at']) < $now;
          // reservasi berikutnya boleh check-in 15 menit sebelum mulai
          $nextEarly = !$cur && $next && $next['expires_at'] !== null
                       && intval($next['start_time']) - $now <= BOOKING_CHECKIN_SEC;
        ?>
        <div class="room-tile state-<?= $r['state'] ?>">
          <div class="room-tile-head">
            <span class="console-chip c-<?= strtolower(htmlspecialchars($r['console_type'])) ?>"><?= htmlspecialchars($r['console_model']) ?></span>
            <span class="state-pill"><?= $stateLabel[$r['state']] ?></span>
          </div>
          <h4><?= htmlspecialchars($r['title']) ?> <span class="tier-tag"><?= htmlspecialchars($r['tier']) ?></span></h4>

          <?php if ($cur): ?>
            <p class="room-who">
              <?= htmlspecialchars($cur['customer_name']) ?>
              <span class="src-chip"><?= $srcLabel($cur['source']) ?></span>
              <?php $due = intval($cur['total_price']) - intval($cur['amount_paid']); ?>
              <span class="pay-badge <?= $cur['payment_status'] === 'paid' ? 'pay-paid' : 'pay-unpaid' ?>">
                <?php if ($cur['payment_status'] === 'paid'): ?>Lunas
                <?php elseif (intval($cur['amount_paid']) > 0): ?>Kurang Rp<?= number_format($due, 0, ',', '.') ?>
                <?php else: ?>Belum bayar<?php endif; ?>
              </span>
            </p>
            <?php if ($noShow): ?>
              <p class="checkin-wait">Tidak hadir (batas <?= date('H:i', intval($cur['expires_at'])) ?> lewat). Sudah dibayar, ruangan bisa dilepas.</p>
            <?php elseif ($waitingArrival): ?>
              <p class="checkin-wait">Menunggu kedatangan s/d <?= date('H:i', intval($cur['expires_at'])) ?></p>
            <?php else: ?>
              <div class="progress"><div style="width:<?= $progress ?>%"></div></div>
            <?php endif; ?>
            <p class="room-meta">Selesai <?= date('H:i', intval($cur['end_time'])) ?> · Rp<?= number_format(intval($cur['total_price']), 0, ',', '.') ?><?= $cur['with_nintendo'] ? ' · +Nintendo' : '' ?><?= $cur['is_student'] ? ' · Pelajar' : '' ?></p>
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
              <?php if ($noShow): ?>
                <button class="btn-outline" onclick="releaseNoShow(<?= intval($cur['id']) ?>, <?= jsArg($cur['customer_name']) ?>)">Lepas Ruangan</button>
              <?php endif; ?>
              <?php
                // tukar 10 poin = gratis 1 jam (akun website, belum lunas)
                $canRedeem = $cur['user_id'] !== null && $cur['payment_status'] !== 'paid'
                  && (intval($cur['discount']) > 0 || pointsBalance($conn, $cur['user_id']) >= POINTS_PER_FREE_HOUR);
              ?>
              <?php if ($canRedeem): ?>
                <button class="btn-ghost-sm" onclick="redeemPoints(<?= intval($cur['id']) ?>, <?= intval($cur['discount']) > 0 ? 'true' : 'false' ?>)"><?= intval($cur['discount']) > 0 ? 'Batal Pakai Poin' : 'Pakai ' . POINTS_PER_FREE_HOUR . ' Poin' ?></button>
              <?php endif; ?>
              <?php if (intval($cur['amount_paid']) === 0 && ($cur['is_student'] || studentEligible($r, (bool) $cur['with_nintendo'], intval($cur['start_time'])))): ?>
                <button class="btn-ghost-sm" onclick="toggleStudent(<?= intval($cur['id']) ?>, <?= $cur['is_student'] ? 'true' : 'false' ?>)"><?= $cur['is_student'] ? 'Batal Harga Pelajar' : 'Harga Pelajar' ?></button>
              <?php endif; ?>
              <?php if ($cur['payment_status'] !== 'paid'): ?>
                <button class="btn-paid" onclick="markPaid(<?= intval($cur['id']) ?>, <?= jsArg($cur['customer_name']) ?>, <?= intval($cur['total_price']) - intval($cur['amount_paid']) ?>)">Lunas</button>
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
                <button class="btn-outline" onclick="finishBooking(<?= intval($cur['id']) ?>, <?= $cur['payment_status'] === 'paid' ? 0 : $due ?>)">Selesaikan Sesi</button>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$rooms): ?>
          <p class="queue-empty">Belum ada ruangan. Tambahkan di menu Ruangan.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <h3 class="section-title" style="margin-top:32px"><?= icon('clipboard') ?> Daftar Tunggu</h3>
  <div class="queue-grid">
    <?php foreach ($queueBy as $ct => $list): ?>
    <div class="queue-col">
      <div class="queue-col-head">
        <span class="class-chip"><?= htmlspecialchars($ct) ?></span>
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
            <?= $q['with_nintendo'] ? ' · +Nintendo' : '' ?><?= $q['is_student'] ? ' · Pelajar' : '' ?>
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

  <!-- RUANGAN -->
<?php elseif ($page === 'room'): ?>
  <?php
    // upcoming = booking aktif ke depan, buat peringatan set perawatan
    $rooms = mysqli_query($conn, "
      SELECT r.*,
        (SELECT COUNT(*) FROM bookings b
          WHERE b.room_id = r.id AND b.payment_status <> 'cancelled' AND b.end_time > UNIX_TIMESTAMP()) AS upcoming,
        (SELECT GROUP_CONCAT(rg.game_id) FROM room_games rg WHERE rg.room_id = r.id) AS game_ids
      FROM rooms r ORDER BY r.id
    ");
    // semua game + konsol buat modal Atur Game
    $allGames = mysqli_query($conn, "
      SELECT g.id, g.title, GROUP_CONCAT(gc.console_type) AS consoles
      FROM games g LEFT JOIN game_consoles gc ON gc.game_id = g.id
      GROUP BY g.id ORDER BY g.title
    ")->fetch_all(MYSQLI_ASSOC);
    $allGames = array_map(fn($g) => ['id' => intval($g['id']), 'title' => $g['title'],
      'consoles' => $g['consoles'] ? explode(',', $g['consoles']) : []], $allGames);
    $catLabel = ['private' => 'Private', 'private_pro' => 'Private Pro', 'public' => 'Public'];
    $rupiah = fn($n) => 'Rp' . number_format($n, 0, ',', '.');
  ?>
  <div class="page-head">
    <div>
      <h2>Manajemen Ruangan</h2>
      <p class="page-sub">Harga paket 1-5 jam dan harga pelajar mengikuti pricelist, otomatis dipakai bila tarif per jam ruangan sama dengan tarif paket.</p>
    </div>
    <button type="button" class="btn-primary" onclick="openEditRoom(null)"><?= icon('check') ?> Tambah Ruangan</button>
  </div>

  <div class="table-wrap">
    <table class="users-table">
      <tr><th>Ruangan</th><th>Kategori</th><th>Konsol</th><th>Harga / jam</th><th>Game</th><th>Status</th><th class="col-actions">Aksi</th></tr>
      <?php if (mysqli_num_rows($rooms) === 0): ?>
        <tr><td colspan="7" class="empty-row">Belum ada ruangan.</td></tr>
      <?php endif; ?>
      <?php while($r = mysqli_fetch_assoc($rooms)): ?>
      <?php $active = $r['status'] === 'available'; ?>
      <tr>
        <td class="cell-main wrap-cell"><?= htmlspecialchars($r['title']) ?> <span class="tier-tag"><?= htmlspecialchars($r['tier']) ?></span>
          <span class="cell-sub">Maks <?= intval($r['max_players']) ?> pemain<?= $r['has_netflix'] ? ' · Netflix' : '' ?></span>
        </td>
        <td><?= $catLabel[$r['category']] ?? htmlspecialchars($r['category']) ?></td>
        <td><span class="console-chip c-<?= strtolower(htmlspecialchars($r['console_type'])) ?>"><?= htmlspecialchars($r['console_model']) ?></span></td>
        <td><?= $rupiah($r['price']) ?>
          <span class="cell-sub">Weekend +<?= $rupiah($r['weekend_extra']) ?><?= $r['nintendo_extra'] !== null ? '<br>Nintendo +' . $rupiah($r['nintendo_extra']) : '' ?></span>
        </td>
        <?php $roomGameIds = $r['game_ids'] ? array_map('intval', explode(',', $r['game_ids'])) : []; ?>
        <td class="col-games">
          <?= $roomGameIds ? count($roomGameIds) . ' game' : '<span class="muted-dash">Semua ' . htmlspecialchars($r['console_type'] === 'SWITCH' ? 'Switch' : $r['console_type']) . '</span>' ?>
          <button class="link-btn col-actions" onclick="openRoomGames(<?= jsArg(['id' => intval($r['id']), 'title' => $r['title'], 'console_type' => $r['console_type'], 'game_ids' => $roomGameIds]) ?>)">Atur</button>
        </td>
        <td><span class="pay-badge <?= $active ? 'pay-paid' : 'pay-unpaid' ?>"><?= $active ? 'Aktif' : 'Perawatan' ?></span></td>
        <td class="col-actions">
          <div class="row-actions">
            <button class="btn-ghost-sm" onclick="openEditRoom(<?= jsArg([
              'id' => intval($r['id']), 'title' => $r['title'], 'category' => $r['category'], 'tier' => $r['tier'],
              'console_type' => $r['console_type'], 'console_model' => $r['console_model'], 'price' => intval($r['price']),
              'weekend_extra' => intval($r['weekend_extra']), 'nintendo_extra' => $r['nintendo_extra'] !== null ? intval($r['nintendo_extra']) : '',
              'max_players' => intval($r['max_players']), 'has_netflix' => (bool) $r['has_netflix'], 'description' => $r['description'],
            ]) ?>)">Edit</button>
            <button class="<?= $active ? 'btn-warn-sm' : 'btn-ghost-sm' ?>" onclick="toggleRoom(<?= intval($r['id']) ?>, <?= jsArg($r['title']) ?>, <?= $active ? 'true' : 'false' ?>, <?= intval($r['upcoming']) ?>)"><?= $active ? 'Set Perawatan' : 'Aktifkan' ?></button>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>

  <?php
    $reviews = mysqli_query($conn, "
      SELECT rv.id, rv.rating, rv.comment, rv.created_at, u.username, r.title AS room_title
      FROM room_reviews rv JOIN users u ON u.id = rv.user_id JOIN rooms r ON r.id = rv.room_id
      ORDER BY rv.created_at DESC LIMIT 30
    ");
  ?>
  <h3 class="section-title" style="margin-top:32px"><?= icon('check') ?> Ulasan Pelanggan Terbaru</h3>
  <div class="table-wrap">
    <table class="users-table">
      <tr><th>Ruangan</th><th>Rating</th><th>Komentar</th><th>Pengguna</th><th class="col-actions">Aksi</th></tr>
      <?php if (mysqli_num_rows($reviews) === 0): ?>
        <tr><td colspan="5" class="empty-row">Belum ada ulasan.</td></tr>
      <?php endif; ?>
      <?php while ($rv = mysqli_fetch_assoc($reviews)): ?>
      <tr>
        <td><?= htmlspecialchars($rv['room_title']) ?></td>
        <td class="review-stars-admin"><?= str_repeat('★', intval($rv['rating'])) . str_repeat('☆', 5 - intval($rv['rating'])) ?></td>
        <td class="wrap-cell"><?= $rv['comment'] !== '' ? htmlspecialchars($rv['comment']) : '<span class="muted-dash">—</span>' ?></td>
        <td><?= htmlspecialchars($rv['username']) ?><span class="cell-sub"><?= date('d M Y', strtotime($rv['created_at'])) ?></span></td>
        <td class="col-actions">
          <button class="btn-icon" title="Hapus ulasan" aria-label="Hapus ulasan" onclick="deleteReview(<?= intval($rv['id']) ?>)"><?= icon('x') ?></button>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>

  <!-- MODAL GAME PER RUANGAN -->
  <div id="roomGamesModal" class="modal-backdrop" style="display:none">
    <form id="roomGamesForm" class="modal-card" data-games="<?= htmlspecialchars(json_encode($allGames), ENT_QUOTES) ?>">
      <h3 class="panel-title" data-room-games-title>Game di Ruangan</h3>
      <p class="page-sub">Centang game yang terpasang di ruangan ini. Jika tidak ada yang dicentang, website menampilkan semua game untuk konsol ruangan.</p>
      <input type="hidden" name="id">
      <div class="room-games-tools">
        <input type="search" placeholder="Cari game..." data-room-games-search>
        <button type="button" class="btn-ghost-sm" data-room-games-all>Pilih semua</button>
        <button type="button" class="btn-ghost-sm" data-room-games-none>Kosongkan</button>
      </div>
      <p class="form-note" data-room-games-count></p>
      <div class="room-games-list" data-room-games-list></div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary">Simpan</button>
        <button type="button" class="btn-ghost-sm" onclick="closeEditModal('roomGamesModal')">Batal</button>
      </div>
    </form>
  </div>

  <!-- MODAL TAMBAH / EDIT RUANGAN -->
  <div id="editRoomModal" class="modal-backdrop" style="display:none">
    <form id="editRoomForm" class="modal-card">
      <h3 class="panel-title" data-room-modal-title>Edit Ruangan</h3>
      <p class="page-sub">Harga baru hanya berlaku untuk booking berikutnya.</p>
      <input type="hidden" name="id">
      <div class="field-row">
        <label class="field"><span>Nama ruangan</span><input name="title" maxlength="100" placeholder="mis. Room 14" required></label>
        <label class="field"><span>Maks pemain</span><input type="number" name="max_players" min="1" max="8" value="2" required></label>
      </div>
      <div class="field-row">
        <label class="field">
          <span>Kategori</span>
          <select name="category" required>
            <?php foreach ($catLabel as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Kelas</span>
          <select name="tier" required>
            <option value="VVIP">VVIP</option><option value="VIP">VIP</option><option value="Public">Public</option>
          </select>
        </label>
      </div>
      <div class="field-row">
        <label class="field">
          <span>Jenis konsol</span>
          <select name="console" required>
            <option value="PS4">PS4</option><option value="PS5">PS5</option><option value="SWITCH">Nintendo Switch</option>
          </select>
        </label>
        <label class="field"><span>Model konsol</span><input name="console_model" maxlength="30" placeholder="mis. PS5 Pro" required></label>
      </div>
      <div class="field-row">
        <label class="field"><span>Harga / jam</span><input type="number" name="price" min="1" required></label>
        <label class="field"><span>Tambahan weekend / jam</span><input type="number" name="weekend_extra" min="0" value="0" required></label>
      </div>
      <div class="field-row">
        <label class="field"><span>Tambahan Nintendo / jam <em>(kosongkan jika tidak ada)</em></span><input type="number" name="nintendo_extra" min="1"></label>
        <label class="field check-field"><span>Netflix</span><span class="check-opt"><input type="checkbox" name="has_netflix" value="1"> Tersedia</span></label>
      </div>
      <label class="field"><span>Keterangan</span><input name="description" maxlength="255"></label>
      <div class="modal-actions">
        <button type="submit" class="btn-primary">Simpan</button>
        <button type="button" class="btn-ghost-sm" onclick="closeEditModal('editRoomModal')">Batal</button>
      </div>
    </form>
  </div>

  <!-- HARGA & PAKET -->
<?php elseif ($page === 'harga'): ?>
  <?php
    // kelas ruangan per tarif, biar admin tau paket buat siapa
    $rateUse = [];
    foreach ($conn->query("SELECT DISTINCT " . ROOM_CLASS_SQL . " AS cls, price, nintendo_extra FROM rooms WHERE status = 'available'") as $row) {
      $rateUse[intval($row['price'])][] = $row['cls'];
      if ($row['nintendo_extra'] !== null) $rateUse[intval($row['price']) + intval($row['nintendo_extra'])][] = $row['cls'] . ' + Nintendo';
    }
    $rateUse = array_map(fn($l) => implode(', ', array_unique($l)), $rateUse);
    ksort($rateUse);
  ?>
  <div class="page-head">
    <div>
      <h2>Harga &amp; Paket</h2>
      <p class="page-sub">Paket berlaku otomatis untuk ruangan yang tarif per jamnya sama dengan "Tarif / jam" di sini. Booking lama tidak berubah harganya.</p>
    </div>
  </div>

  <form id="pricesForm" data-rates="<?= htmlspecialchars(json_encode($rateUse), ENT_QUOTES) ?>">
    <div class="panel-card">
      <h3 class="panel-title"><?= icon('check') ?> Paket Main Seru (1-<?= PACKAGE_HOURS ?> jam)</h3>
      <p class="page-sub">Total harga untuk 1-<?= PACKAGE_HOURS ?> jam. Jam ke-<?= PACKAGE_HOURS + 1 ?> dan seterusnya memakai tarif biasa. Weekend tetap ditambah biaya weekend ruangan.</p>
      <div class="table-wrap">
        <table class="users-table price-edit" data-price-table="packages">
          <tr><th>Tarif / jam</th><?php for ($h = 1; $h <= PACKAGE_HOURS; $h++): ?><th><?= $h ?> jam</th><?php endfor; ?><th>Dipakai oleh</th><th></th></tr>
          <?php foreach (pricePackages() as $rate => $pkg): ?>
          <tr>
            <td><input type="number" min="1" data-f="rate" value="<?= $rate ?>"></td>
            <?php foreach ($pkg as $price): ?><td><input type="number" min="1" data-f="price" value="<?= $price ?>"></td><?php endforeach; ?>
            <td class="cell-sub" data-use></td>
            <td><button type="button" class="btn-icon" data-row-del title="Hapus baris" aria-label="Hapus baris"><?= icon('x') ?></button></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <button type="button" class="btn-ghost-sm" data-row-add="packages">+ Tambah paket</button>
    </div>

    <div class="panel-card" style="margin-top:18px">
      <h3 class="panel-title"><?= icon('user') ?> Paket Pelajar (Senin-Jumat)</h3>
      <p class="page-sub">Dipasang kasir setelah konsumen menunjukkan kartu pelajar. Lebih dari 3 jam dihitung kelipatan paket 3 jam + sisa per jam.</p>
      <div class="table-wrap">
        <table class="users-table price-edit" data-price-table="student">
          <tr><th>Tarif / jam</th><th>1 jam</th><th>3 jam</th><th>Dipakai oleh</th><th></th></tr>
          <?php foreach (studentPrices() as $rate => [$p1, $p3]): ?>
          <tr>
            <td><input type="number" min="1" data-f="rate" value="<?= $rate ?>"></td>
            <td><input type="number" min="1" data-f="p1" value="<?= $p1 ?>"></td>
            <td><input type="number" min="1" data-f="p3" value="<?= $p3 ?>"></td>
            <td class="cell-sub" data-use></td>
            <td><button type="button" class="btn-icon" data-row-del title="Hapus baris" aria-label="Hapus baris"><?= icon('x') ?></button></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <button type="button" class="btn-ghost-sm" data-row-add="student">+ Tambah tarif pelajar</button>
    </div>

    <div class="price-save-bar">
      <button type="submit" class="btn-primary"><?= icon('check') ?> Simpan Harga</button>
      <span class="page-sub">Tarif yang tidak dipakai ruangan mana pun tidak berpengaruh (ditandai "Belum dipakai").</span>
    </div>
  </form>

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
              <button class="btn-ghost-sm" onclick="resetUserPassword(<?= intval($u['id']) ?>, <?= jsArg($u['username']) ?>)">Reset Password</button>
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
      <input name="title" placeholder="mis. EA FC 26" maxlength="100" required>
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
      <input type="text" id="customGenreInput" placeholder="Nama genre baru (mis. Horror)" maxlength="50" autocomplete="off">
      <button type="button" id="cancelCustomGenre" class="btn-cancel-genre" aria-label="Batal"><?= icon('x') ?></button>
    </div>

    <div class="field">
      <span>Konsol</span>
      <div class="checkbox-group">
        <?php foreach (CONSOLE_TYPES as $c): ?>
          <label><input type="checkbox" name="consoles[]" value="<?= $c ?>"> <?= $c === 'SWITCH' ? 'Switch' : $c ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <label class="file-upload-zone">
      <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp">
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
              <span class="console-chip c-<?= strtolower(htmlspecialchars($c)) ?>"><?= $c === 'SWITCH' ? 'Switch' : htmlspecialchars($c) ?></span>
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
      <?php foreach (['PS4' => 'rgba(127, 167, 201,0.5)', 'PS5' => 'rgba(179, 146, 214,0.5)', 'SWITCH' => 'rgba(239, 122, 95,0.5)'] as $c => $border): ?>
        <label id="editLabel<?= $c ?>" class="modal-chip" style="border-color:<?= $border ?>">
          <input type="checkbox" id="edit<?= $c ?>" value="<?= $c ?>" hidden> <?= $c === 'SWITCH' ? 'Switch' : $c ?>
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
    SELECT mo.id, mo.quantity, mo.unit_price, mo.note, mo.status, mo.created_at,
           mi.name AS item_name,
           u.username, r.title AS room_title, b.order_code
    FROM menu_orders mo
    JOIN menu_items mi ON mi.id = mo.item_id
    JOIN users u       ON u.id  = mo.user_id
    LEFT JOIN bookings b ON b.id = mo.booking_id
    LEFT JOIN rooms r    ON r.id = b.room_id
    -- pending paling lama di atas (antri), sisanya terbaru dulu
    ORDER BY mo.status = 'pending' DESC,
             CASE WHEN mo.status = 'pending' THEN mo.created_at END ASC,
             mo.created_at DESC
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
    <?php $isDone = $o['status'] === 'selesai'; $isVoid = $o['status'] === 'batal'; ?>
    <tr class="<?= $isDone ? 'row-done' : ($isVoid ? 'row-cancelled' : '') ?>">
      <td class="cell-main"><?= htmlspecialchars($o['username']) ?>
        <span class="cell-sub"><?= $o['room_title'] ? 'Antar ke ' . htmlspecialchars($o['room_title']) . ' · ' . htmlspecialchars($o['order_code'] ?? '') : 'Ambil di kasir' ?></span>
      </td>
      <td><?= htmlspecialchars($o['item_name']) ?></td>
      <td><?= intval($o['quantity']) ?>x</td>
      <td>Rp<?= number_format($o['unit_price'] * $o['quantity'], 0, ',', '.') ?></td>
      <td class="wrap-cell"><?= $o['note'] ? htmlspecialchars($o['note']) : '<span class="muted-dash">—</span>' ?></td>
      <td><?= date('d M H:i', strtotime($o['created_at'])) ?></td>
      <td>
        <span class="pay-badge <?= $isDone ? 'pay-paid' : ($isVoid ? 'pay-cancelled' : 'pay-unpaid') ?>"><?= $isDone ? 'Selesai' : ($isVoid ? 'Dibatalkan' : 'Diproses') ?></span>
      </td>
      <td>
        <div class="row-actions">
          <?php if (!$isDone && !$isVoid): ?>
            <button class="btn-paid-sm" onclick="markOrderDone(<?= intval($o['id']) ?>)"><?= icon('check') ?> Sudah Sampai</button>
            <button class="btn-icon" title="Batalkan pesanan" aria-label="Batalkan pesanan" onclick="cancelMenuOrder(<?= intval($o['id']) ?>, <?= jsArg($o['item_name']) ?>)"><?= icon('x') ?></button>
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
      <input name="name" placeholder="mis. Indomie Goreng" maxlength="100" required>
    </label>
    <div class="field-row">
      <label class="field">
        <span>Kategori</span>
        <select name="category" required>
          <option value="">Pilih</option>
          <option value="makanan">Makanan</option>
          <option value="snack">Snack</option>
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
      <input type="text" name="description" placeholder="Deskripsi singkat" maxlength="255">
    </label>
    <label class="file-upload-zone">
      <input type="file" name="item_image" accept="image/jpeg,image/png,image/webp">
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
            <button class="btn-ghost-sm" onclick="openEditMenu(<?= jsArg(['id' => intval($item['id']), 'name' => $item['name'], 'category' => $item['category'], 'price' => intval($item['price']), 'description' => $item['description']]) ?>)">Edit</button>
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

<!-- MODAL EDIT MENU -->
<div id="editMenuModal" class="modal-backdrop" style="display:none">
  <form id="editMenuForm" class="modal-card" enctype="multipart/form-data">
    <h3 class="panel-title">Edit Item Menu</h3>
    <p class="page-sub">Pesanan lama tetap memakai harga saat dipesan.</p>
    <input type="hidden" name="id">
    <label class="field"><span>Nama item</span><input name="name" maxlength="100" required></label>
    <div class="field-row">
      <label class="field">
        <span>Kategori</span>
        <select name="category" required>
          <option value="makanan">Makanan</option>
          <option value="snack">Snack</option>
          <option value="minuman">Minuman</option>
        </select>
      </label>
      <label class="field"><span>Harga</span><input type="number" name="price" min="1" required></label>
    </div>
    <label class="field"><span>Deskripsi <em>(opsional)</em></span><input name="description" maxlength="255"></label>
    <label class="field">
      <span>Ganti foto <em>(opsional, JPG/PNG/WEBP maks 2 MB)</em></span>
      <input type="file" name="item_image" accept="image/jpeg,image/png,image/webp">
    </label>
    <div class="modal-actions">
      <button type="submit" class="btn-primary">Simpan</button>
      <button type="button" class="btn-ghost-sm" onclick="closeEditModal('editMenuModal')">Batal</button>
    </div>
  </form>
</div>

<?php endif; ?>
</main>
<script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>"></script>
<?php endif; ?>
</body>
</html>
