<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/icons.php';
require __DIR__ . '/db.php';
require __DIR__ . '/site_info.php';
require_once __DIR__ . '/queue_lib.php';

// Harga termurah per kategori dari ruangan aktif, untuk kartu "Info Singkat" di Beranda
const CATEGORY_LABELS = ['public' => 'Public', 'private' => 'Private', 'private_pro' => 'Private Pro'];
$priceFrom = [];
$res = $conn->query("SELECT category, MIN(price) AS p FROM rooms WHERE status = 'available' GROUP BY category ORDER BY p");
while ($row = $res->fetch_assoc()) $priceFrom[CATEGORY_LABELS[$row['category']]] = intval($row['p']);
$queueClasses = roomClasses($conn);

// Teks info diambil dari data ruangan supaya tetap benar bila admin mengubah ruangan
$fmtRp = fn($n) => 'Rp' . number_format($n, 0, ',', '.');
// Tambahan weekend dikelompokkan per nominal, mis. "+Rp3.000 (Public) / +Rp5.000 (Private, Private Pro)"
$weekendBy = [];
$res = $conn->query("SELECT DISTINCT category, weekend_extra AS w FROM rooms WHERE status = 'available' AND weekend_extra > 0 ORDER BY w, category");
while ($row = $res->fetch_assoc()) $weekendBy[intval($row['w'])][] = CATEGORY_LABELS[$row['category']];
$weekendInfo = [];
foreach ($weekendBy as $w => $cats) $weekendInfo[] = '+' . $fmtRp($w) . ' (' . implode(', ', array_unique($cats)) . ')';
$listTitles = function ($where) use ($conn) {
  $titles = array_column($conn->query("SELECT title FROM rooms WHERE status = 'available' AND $where ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'title');
  return count($titles) > 1 ? implode(', ', array_slice($titles, 0, -1)) . ' dan ' . end($titles) : ($titles[0] ?? '');
};
$switchRooms = $listTitles("console_type = 'SWITCH'");
$addonRooms  = $listTitles("nintendo_extra IS NOT NULL");
$nintendoInfo = trim(($switchRooms ? "Tersedia di $switchRooms" : '') . ($switchRooms && $addonRooms ? ', atau sebagai tambahan di ' : ($addonRooms ? 'Bisa ditambahkan di ' : '')) . $addonRooms) . '.';
?>

<!DOCTYPE html>
<html lang="id">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Optimum Playzone — Rental PlayStation</title>

    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
  </head>

  <body data-open-sec="<?= BOOKING_OPEN_SEC ?>" data-close-sec="<?= BOOKING_CLOSE_SEC ?>" data-pricing="<?= htmlspecialchars(pricingJson()) ?>">
    <header class="site-header">
      <div class="header-inner">
        <!-- left: brand -->
        <div class="brand">Optimum Playzone</div>

        <!-- center: search bar -->
        <div class="header-center" aria-hidden="false">
          <form id="navSearchForm" class="search-wrap" role="search">
            <input
              id="navSearchInput"
              type="search"
              placeholder="Cari ruangan atau game..."
              aria-label="Cari"
            />
            <button type="submit" aria-label="Cari"><?= icon('search') ?></button>
          </form>
        </div>

        <!-- right: nav -->
        <div class="header-right">
          <nav class="nav" role="navigation" aria-label="Utama">
            <a href="#" data-view="home">Beranda</a>
            <a href="#" data-view="consoles">Ruangan</a>
            <a href="#" data-view="booking">Booking</a>
            <a href="#" data-view="fasilitas">Fasilitas</a>
            <a href="#" data-view="game">Game</a>
            <a href="#" data-view="menu" id="menuNavLink" style="display:none">Menu</a>
          </nav>

          <!-- Login -->
            <div id="authArea">
  <a href="#" data-view="login" id="loginMenu">Login</a>
</div>

<!-- LOGIN TELAH BERHASIL -->
<div id="profileMenu" class="profile" style="display:none">
  <button id="profileToggle" class="profile-btn">
    <span id="profileName">Username</span>
    <span class="arrow">▼</span>
  </button>
  <!-- DROPDOWN MENU -->
  <div id="profileDropdown" class="profile-dropdown">
    <a href="#" data-view="profile" id="profileDetail">Detail</a>
    <a href="#" id="logoutBtn">Logout</a>
  </div>
</div>
        </div>
      </div>
    </header>

    <main id="app">
      <!-- HERO -->
      <section id="view-home" class="view">
        <div class="hero">
          <div>
            <h1>Optimum Playzone — Rental PlayStation</h1>
            <p class="muted">
              Ruangan Private dan Private Pro dengan PS5, PS5 Pro, Nintendo Switch, dan Netflix,
              plus meja Public PS4 dan PS5. Booking jam tertentu atau ambil antrian untuk main sekarang.
            </p>
            <div style="display: flex; gap: 12px; margin-top: 12px">
              <button class="btn" data-view="consoles">Lihat Ruangan</button>
              <button class="btn-ghost" data-view="booking">
                Booking Sekarang
              </button>
            </div>
          </div>

          <div class="hero-card">
            <h3 style="color: var(--neon); margin-bottom: 12px">Info Singkat</h3>
            <ul class="info-list">
              <li><?= icon('clock') ?> <span><strong>Jam buka</strong><?= htmlspecialchars(SITE_HOURS) ?></span></li>
              <li><?= icon('gamepad') ?>
                <span><strong>Harga mulai</strong>
                  <?php if ($priceFrom): ?>
                    <?php foreach ($priceFrom as $c => $p): ?>
                      <?= htmlspecialchars($c) ?>: Rp<?= number_format($p, 0, ',', '.') ?>/jam<br>
                    <?php endforeach; ?>
                  <?php else: ?>Belum ada ruangan aktif<?php endif; ?>
                </span>
              </li>
              <li><?= icon('check') ?> <span><strong>Paket hemat</strong>Harga paket otomatis untuk 1-5 jam.<?php if ($weekendInfo): ?> Sabtu-Minggu <?= htmlspecialchars(implode(' / ', $weekendInfo)) ?> per jam.<?php endif; ?></span></li>
              <li><?= icon('user') ?> <span><strong>Paket Pelajar</strong>Senin-Jumat, tunjukkan kartu pelajar ke kasir.</span></li>
              <li><?= icon('clipboard') ?> <span><strong>Pembayaran</strong>Bayar di kasir saat datang</span></li>
            </ul>
            <a class="btn-ghost info-wa" href="<?= htmlspecialchars(siteWaLink()) ?>" target="_blank" rel="noopener">Tanya via WhatsApp</a>
          </div>
        </div>

        <div id="upcomingBanner" style="display:none" class="upcoming-banner container">
          <div class="upcoming-banner-inner">
            <span class="upcoming-icon"><?= icon('gamepad') ?></span>
            <div class="upcoming-text">
              <span class="upcoming-label" id="upcomingLabel">Booking Mendatang</span>
              <span id="upcomingDetail"></span>
            </div>
            <button class="btn-ghost upcoming-btn" data-view="profile">Lihat Detail</button>
          </div>
        </div>

        <div class="container" style="margin-top: 22px">
          <h2 style="color: var(--neon); margin-bottom: 12px">
            Ruangan Populer
          </h2>
          <div id="roomsGrid" class="rooms-grid" aria-live="polite"></div>
        </div>

        <div class="container location-card">
          <div>
            <h2 style="color: var(--neon); margin-bottom: 10px">Lokasi &amp; Kontak</h2>
            <ul class="info-list">
              <li><?= icon('clock') ?> <span><strong>Jam operasional</strong><?= htmlspecialchars(SITE_HOURS) ?></span></li>
              <li><?= icon('pin') ?> <span><strong>Alamat</strong><?= htmlspecialchars(SITE_ADDRESS) ?></span></li>
              <li><?= icon('phone') ?> <span><strong>WhatsApp</strong><?= htmlspecialchars(SITE_WA_SHOW) ?></span></li>
            </ul>
          </div>
          <div class="location-actions">
            <a class="btn" href="<?= htmlspecialchars(SITE_MAPS_URL) ?>" target="_blank" rel="noopener">Buka Google Maps</a>
            <a class="btn-ghost" href="<?= htmlspecialchars(siteWaLink()) ?>" target="_blank" rel="noopener">Chat WhatsApp</a>
          </div>
        </div>
      </section>

      <!-- LIST RUANGAN -->
      <section id="view-consoles" class="view" style="display: none">
        <div class="container">
          <h1 style="color: var(--neon)">Daftar Ruangan</h1>
          <p class="muted">Pilih ruangan sesuai tipe:</p>

          <div id="roomCatTabs" class="genre-filters" style="margin-top:16px"></div>
          <div id="allRoomsGrid" class="rooms-grid" style="margin-top:14px"></div>
        </div>
      </section>

      <!-- DETAIL -->
      <section id="view-room-detail" class="view" style="display: none">
        <div class="container">
          <button id="backToRooms" class="btn-back">← Kembali</button>
          <div
            id="roomDetail"
            class="room-detail"
            style="margin-top: 14px"
          ></div>
        </div>
      </section>

      <!-- BOOKING -->
      <section id="view-booking" class="view" style="display:none">
        <div class="container">

          <div class="booking-header">
            <h1 style="color:var(--neon)">Booking Ruangan</h1>
            <p class="muted" id="bookingWelcome"></p>
          </div>

          <div class="booking-howto">
            <div><strong>Mau main sekarang?</strong> Ambil antrian di bawah, ruangan diberikan sesuai urutan.</div>
            <div><strong>Mau main di jam tertentu?</strong> Isi form Reservasi Jam Tertentu.</div>
            <div>Pembayaran di kasir saat datang. Lapor ke kasir paling lambat 15 menit setelah jam mulai, lewat dari itu booking batal otomatis.</div>
            <div>Pelajar? Harga Paket Pelajar (Senin-Jumat) dipasang kasir setelah kamu menunjukkan kartu pelajar.</div>
          </div>

          <!-- ANTRIAN MAIN SEKARANG -->
          <div class="booking-form-card queue-card" style="margin-bottom:18px">
            <h3 class="bsection-title"><?= icon('clock') ?> Main Sekarang — Ambil Antrian</h3>
            <p class="muted small" style="margin-bottom:12px">
              Tanpa pilih jam: kamu masuk antrian bersama pengunjung yang datang langsung,
              dilayani sesuai urutan. Saat giliranmu tiba, datang ke kasir dalam 15 menit.
            </p>
            <div id="queueJoinBox" class="brow">
              <div class="bfield">
                <label for="queueClass">Kelas Ruangan</label>
                <select id="queueClass">
                  <?php foreach ($queueClasses as $qc): ?>
                    <option value="<?= htmlspecialchars($qc['room_class']) ?>" data-price="<?= intval($qc['price']) ?>" data-nintendo="<?= $qc['nintendo_extra'] !== null ? intval($qc['nintendo_extra']) : '' ?>"><?= htmlspecialchars($qc['room_class']) ?> · mulai Rp<?= number_format($qc['price'], 0, ',', '.') ?>/jam</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="bfield">
                <label for="queueDuration">Durasi</label>
                <select id="queueDuration"></select>
              </div>
              <div class="bfield">
                <button type="button" class="btn" id="queueJoinBtn">Ambil Antrian</button>
              </div>
            </div>
            <label class="bcheck" id="queueNintendoField" style="display:none">
              <input type="checkbox" id="queueNintendo">
              <span>Tambah Nintendo Switch <strong id="queueNintendoExtra"></strong></span>
            </label>
            <div id="queueStatusBox" style="display:none">
              <p id="queueStatusText" style="margin-bottom:10px"></p>
              <button type="button" class="btn-ghost" id="queueCancelBtn">Batalkan Antrian</button>
            </div>
          </div>

          <div class="booking-layout">

            <!-- FORM CARD -->
            <div class="booking-form-card">
              <h2 class="booking-form-title"><?= icon('clipboard') ?> Reservasi Jam Tertentu</h2>
              <form id="bookingForm" autocomplete="off">

                <!-- Pemesan -->
                <div class="bsection">
                  <h3 class="bsection-title"><?= icon('user') ?> Informasi Pemesan</h3>

                  <!-- Indikator akun -->
                  <div class="bfield">
                    <label>Akun</label>
                    <div class="account-indicator">
                      <span class="account-indicator-username" id="bookingAccountName">—</span>
                      <span class="account-indicator-lock"><?= icon('lock') ?></span>
                    </div>
                  </div>

                  <div class="bfield" style="margin-top:14px">
                    <label for="name">Nama Lengkap</label>
                    <input id="name" placeholder="Nama asli kamu" maxlength="100" required autocomplete="new-password">
                  </div>
                  <div class="brow">
                    <div class="bfield">
                      <label for="email">Email</label>
                      <input id="email" type="text" inputmode="email" maxlength="100" placeholder="email@contoh.com" autocomplete="new-password">
                    </div>
                    <div class="bfield">
                      <label for="phone">No. HP</label>
                      <input id="phone" type="tel" inputmode="numeric" pattern="[0-9]{9,14}" maxlength="14" placeholder="08xxxxxxxxxx" autocomplete="new-password">
                    </div>
                  </div>
                </div>

                <!-- Detail Sesi -->
                <div class="bsection">
                  <h3 class="bsection-title"><?= icon('gamepad') ?> Detail Sesi</h3>
                  <div class="bfield">
                    <label for="roomSelect">Pilih Ruangan</label>
                    <select id="roomSelect" required></select>
                    <p id="bookedSlotsInfo" style="margin-top:6px;font-size:0.8rem;color:#ff6b6b;min-height:1.2em"></p>
                  </div>
                  <div class="bfield">
                    <label for="bookingDate">Tanggal</label>
                    <select id="bookingDate" required></select>
                  </div>
                  <div class="brow">
                    <div class="bfield">
                      <label for="timeStart">Jam Mulai</label>
                      <select id="timeStart" required></select>
                    </div>
                    <div class="bfield">
                      <label for="duration">Durasi (jam)</label>
                      <select id="duration" required></select>
                    </div>
                  </div>
                  <label class="bcheck" id="nintendoField" style="display:none">
                    <input type="checkbox" id="withNintendo">
                    <span>Tambah Nintendo Switch <strong id="nintendoExtra"></strong></span>
                  </label>
                </div>

                <div class="form-actions">
                  <button type="submit" class="btn" style="flex:1">Konfirmasi Booking</button>
                  <button type="button" class="btn-ghost" id="clearBookings">Reset</button>
                </div>
              </form>
            </div>

            <!-- SUMMARY CARD -->
            <div class="booking-summary-card">
              <h3 class="bsection-title"><?= icon('clipboard') ?> Ringkasan</h3>
              <div id="bookingSummary">
                <div class="summary-placeholder">
                  <p class="muted small">Pilih ruangan & durasi<br>untuk melihat estimasi biaya.</p>
                </div>
              </div>
            </div>

          </div><!-- /booking-layout -->
        </div>
      </section>

      <!-- FASILITAS -->
      <section id="view-fasilitas" class="view" style="display: none">
        <div class="container">
          <h1 style="color: var(--neon)">Fasilitas</h1>
          <p class="muted">Fasilitas di ruangan Private dan Private Pro. Meja Public memakai PS4/PS5 tanpa Netflix.</p>
          <div class="facility-grid">
            <?php
              $facilities = [
                ['chair',   'Kursi Gaming', 'Kursi ergonomis dengan sandaran empuk, tetap nyaman untuk bermain berjam-jam.'],
                ['tv',      'TV Besar',     'Layar besar resolusi tinggi dengan warna tajam untuk pengalaman bermain maksimal.'],
                ['speaker', 'Speaker',      'Audio jernih dan detail sehingga suasana game terasa lebih hidup.'],
                ['light',   'Meja LED',     'Meja gaming dengan pencahayaan LED yang membuat suasana bermain lebih seru.'],
                ['snow',    'AC',           'Ruangan berpendingin agar tetap sejuk dan fokus selama bermain.'],
                ['tv',      'Netflix',      'Semua ruangan Private dan Private Pro sudah termasuk Netflix.'],
                ['gamepad', 'Nintendo Switch', $nintendoInfo],
                ['clipboard', 'Pesan Makanan', 'Makanan dan minuman bisa dipesan dari website dan diantar ke ruanganmu.'],
              ];
            ?>
            <?php foreach ($facilities as [$ic, $title, $desc]): ?>
              <div class="room-card facility-card">
                <span class="facility-icon"><?= icon($ic) ?></span>
                <div>
                  <h3><?= $title ?></h3>
                  <p class="muted small"><?= htmlspecialchars($desc) ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- GAME -->
      <section id="view-game" class="view" style="display: none">
        <div class="container">
          <h1 style="color: var(--neon)">Game Tersedia</h1>
          <div id="genreFilters" class="genre-filters"></div>
          <div id="gameList" class="rooms-grid" style="margin-top: 12px"></div>
        </div>
      </section>

      <!-- MENU MAKANAN & MINUMAN -->
<section id="view-menu" class="view" style="display:none">
  <div class="container">
    <h1 style="color:var(--neon)">Makanan & Minuman</h1>
    <p class="muted">Pesan makanan &amp; minuman. Saat sedang main, pesanan diantar ke ruanganmu; selain itu ambil di kasir.</p>

    <div id="menuCatTabs" class="menu-cat-tabs" style="margin-top:16px"></div>
    <div id="menuGrid" class="rooms-grid" style="margin-top:14px"></div>

    <h2 style="color:var(--neon);margin-top:28px">Pesanan Saya</h2>
    <div id="myOrdersList" style="margin-top:10px"></div>
  </div>
</section>

      <!-- PROFILE DETAIL -->
<section id="view-profile" class="view" style="display:none">
  <div class="container">
    <h1 style="color: var(--neon)">Profil Saya</h1>

    <!-- INFO PROFIL -->
    <div class="room-card" style="padding:16px; margin-top:14px">
      <h3 style="color: var(--neon)">Informasi Akun</h3>

      <p><strong>Username:</strong> <span id="profileUsername">-</span></p>
      <p><strong>Terdaftar sejak:</strong> <span id="profileSince">-</span></p>
      <p style="margin-top:8px"><strong>Total Jam Dimainkan:</strong> <span id="profileTotalHours" style="color:var(--neon)">0 jam</span></p>
    </div>

    <!-- RIWAYAT BOOKING -->
    <div class="room-card" style="padding:16px; margin-top:18px">
      <h3 style="color: var(--neon)">Riwayat Booking</h3>

      <div id="profileBookingList" class="booking-list">
        <p class="muted small">Belum ada booking.</p>
      </div>
    </div>

    <!-- AKSI -->
    <div style="margin-top:18px; display:flex; gap:10px">
      <button id="profileLogoutBtn" class="btn-ghost">Logout</button>
      <button class="btn" data-view="booking">Booking Lagi</button>
    </div>
  </div>
</section>
    </main>

    <footer class="site-footer">
      <div class="footer-inner">
        <div>
          <strong class="footer-brand"><?= htmlspecialchars(SITE_NAME) ?></strong>
          <p class="muted small">Rental ruangan PlayStation PS4, PS5, dan Nintendo Switch.</p>
        </div>
        <ul class="footer-list">
          <li><?= icon('clock') ?> <?= htmlspecialchars(SITE_HOURS) ?></li>
          <li><?= icon('pin') ?> <a href="<?= htmlspecialchars(SITE_MAPS_URL) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(SITE_ADDRESS) ?></a></li>
          <li><?= icon('phone') ?> <a href="<?= htmlspecialchars(siteWaLink()) ?>" target="_blank" rel="noopener">WhatsApp <?= htmlspecialchars(SITE_WA_SHOW) ?></a></li>
        </ul>
      </div>
      <p class="footer-copy">© <?= date('Y') ?> <?= htmlspecialchars(SITE_NAME) ?> — Rahmat Haikal Choa</p>
    </footer>

<!-- LOGIN MODAL -->
<div id="loginModal" class="modal">
  <div class="modal-overlay"></div>

  <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="loginTitle">
    <h2 id="loginTitle">Login User</h2>

    <form id="loginForm" action="javascript:void(0)" autocomplete="off">
      <label>
        Username
        <input type="text" id="loginUsername" required maxlength="50" autocomplete="new-password" />
      </label>

      <label>
        Password
        <input type="password" id="loginPassword" required autocomplete="new-password" />
      </label>

      <div class="modal-actions">
        <button type="submit" class="btn">Login</button>
        <button type="button" class="btn-ghost" id="closeLogin">
          Batal
        </button>
      </div>

      <p class="small muted" style="margin-top: 10px; text-align: center;">
        Belum punya akun? <a href="#" class="daftar">Daftar di sini</a>
      </p>
      <p class="small muted" style="margin-top: 6px; text-align: center;">
        <a href="#" id="openForgotPassword" style="color:var(--neon)">Ganti password</a>
        <span class="field-hint">(perlu password lama; lupa password? hubungi kasir)</span>
      </p>
    </form>
  </div>
</div>

<!-- REGISTER MODAL -->
<div id="registerModal" class="modal">
  <div class="modal-overlay"></div>

  <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="registerTitle" onclick="event.stopPropagation()">
    <h2 id="registerTitle">Register User</h2>

    <form id="registerForm" autocomplete="off">
      <label>
        Username
        <input type="text" id="regUsername" required autocomplete="new-password"
               minlength="3" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" />
        <small class="field-hint">3–30 karakter: huruf, angka, atau garis bawah (_), tanpa spasi.</small>
      </label>

      <label>
        Password
        <input type="password" id="regPassword" maxlength="72" required minlength="6" autocomplete="new-password" />
        <small class="field-hint">Minimal 6 karakter.</small>
      </label>

      <label>
        Konfirmasi Password
        <input type="password" id="regConfirm" maxlength="72" required autocomplete="new-password" />
      </label>

      <div class="modal-actions">
        <button type="submit" class="btn">Daftar</button>
        <button type="button" class="btn-ghost" id="closeRegister">Batal</button>
      </div>

      <p class="small muted" style="margin-top:10px;text-align:center">
        Sudah punya akun? <a href="#" id="backToLogin" style="color:var(--neon)">Kembali ke Login</a>
      </p>
    </form>
  </div>
</div>

<!-- ORDER MODAL -->
<div id="orderModal" class="modal">
  <div class="modal-overlay"></div>
  <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="orderTitle" onclick="event.stopPropagation()">
    <h2 id="orderTitle">Pesan <span id="orderItemName"></span></h2>
    <p class="small muted" id="orderItemPrice" style="margin-bottom:14px"></p>
    <input type="hidden" id="orderItemId">
    <label>
      Jumlah
      <input type="number" id="orderQty" min="1" max="20" value="1" style="margin-top:6px">
    </label>
    <label style="margin-top:10px">
      Catatan (opsional)
      <input type="text" id="orderNote" maxlength="200" placeholder="Contoh: tidak pedas, es batu sedikit" style="margin-top:6px">
    </label>
    <div class="modal-actions" style="margin-top:14px">
      <button type="button" class="btn" id="orderSubmitBtn">Pesan Sekarang</button>
      <button type="button" class="btn-ghost" id="closeOrderModal">Batal</button>
    </div>
  </div>
</div>

<!-- FORGOT PASSWORD MODAL -->
<div id="forgotModal" class="modal">
  <div class="modal-overlay"></div>
  <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="forgotTitle" onclick="event.stopPropagation()">
    <h2 id="forgotTitle">Ganti Password</h2>

    <div id="forgotStep1">
      <p class="small muted" style="margin-bottom:12px">Masukkan username, password lama, dan password baru akunmu.</p>
      <label>
        Username
        <input type="text" id="forgotUsername" maxlength="50" placeholder="Username kamu" required autocomplete="new-password" />
      </label>
      <label style="margin-top:8px">
        Password Lama
        <input type="password" id="forgotOldPass" placeholder="Password lama kamu" required autocomplete="new-password" />
      </label>
      <label style="margin-top:8px">
        Password Baru
        <input type="password" id="forgotNewPass" maxlength="72" placeholder="Min. 6 karakter" required autocomplete="new-password" />
      </label>
      <label style="margin-top:8px">
        Konfirmasi Password Baru
        <input type="password" id="forgotConfirmPass" maxlength="72" placeholder="Ulangi password baru" required autocomplete="new-password" />
      </label>
      <div class="modal-actions" style="margin-top:12px">
        <button type="button" class="btn" id="forgotResetBtn">Ganti Password</button>
        <button type="button" class="btn-ghost" id="closeForgot">Batal</button>
      </div>
    </div>
  </div>
</div>

   <script src="script.js?v=<?= filemtime(__DIR__ . '/script.js') ?>"></script>

   <!-- REFRESH TETAP LOGIN -->
   <script>
  window.AUTH = {
  loggedIn: <?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>,
  username: <?= isset($_SESSION['username']) ? json_encode($_SESSION['username']) : 'null' ?>
};
</script>
  </body>
</html>