<?php
session_start();
?>

<!DOCTYPE html>
<html lang="id">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>GameZone — Rental PlayStation Neon Biru</title>

    <link rel="stylesheet" href="style.css">
  </head>

  <body>
    <header class="site-header">
      <div class="header-inner">
        <!-- left: brand -->
        <div class="brand">GameZone</div>

        <!-- center: search bar -->
        <div class="header-center" aria-hidden="false">
          <form id="navSearchForm" class="search-wrap" role="search">
            <input
              id="navSearchInput"
              type="search"
              placeholder="Cari kamar, game, atau fitur..."
              aria-label="Cari"
            />
            <button type="submit" aria-label="Cari">🔍</button>
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
            <h1>GameZone — Rental PlayStation Neon Biru</h1>
            <p class="muted">
              Rasakan pengalaman bermain dengan suasana neon biru, kursi gaming,
              & koleksi game lengkap.
            </p>
            <div style="display: flex; gap: 12px; margin-top: 12px">
              <button class="btn" data-view="consoles">Lihat Ruangan</button>
              <button class="btn-ghost" data-view="booking">
                Booking Sekarang
              </button>
            </div>
          </div>

          <div class="hero-card" aria-hidden="true">
            <h3 style="color: var(--neon); margin-bottom: 8px">
              Preview Ruang
            </h3>
            <p class="small muted">
              Setup ruangan dengan neon biru & layar besar.
            </p>
            <div
              style="
                height: 160px;
                background: #0f1216;
                border-radius: 10px;
                margin-top: 12px;
              "
            ></div>
          </div>
        </div>

        <div id="upcomingBanner" style="display:none" class="upcoming-banner container">
          <div class="upcoming-banner-inner">
            <span class="upcoming-icon">🎮</span>
            <div class="upcoming-text">
              <span class="upcoming-label">Booking Mendatang</span>
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

          <div class="booking-layout">

            <!-- FORM CARD -->
            <div class="booking-form-card">
              <form id="bookingForm" autocomplete="off">

                <!-- Pemesan -->
                <div class="bsection">
                  <h3 class="bsection-title">👤 Informasi Pemesan</h3>

                  <!-- Indikator akun -->
                  <div class="bfield">
                    <label>Akun</label>
                    <div class="account-indicator">
                      <span class="account-indicator-username" id="bookingAccountName">—</span>
                      <span class="account-indicator-lock">🔒</span>
                    </div>
                  </div>

                  <div class="bfield" style="margin-top:14px">
                    <label>Nama Lengkap</label>
                    <input id="name" placeholder="Nama asli kamu" required autocomplete="new-password">
                  </div>
                  <div class="brow">
                    <div class="bfield">
                      <label>Email</label>
                      <input id="email" type="text" inputmode="email" placeholder="email@contoh.com" autocomplete="new-password">
                    </div>
                    <div class="bfield">
                      <label>No. HP</label>
                      <input id="phone" placeholder="08xxxxxxxxxx" autocomplete="new-password">
                    </div>
                  </div>
                </div>

                <!-- Detail Sesi -->
                <div class="bsection">
                  <h3 class="bsection-title">🎮 Detail Sesi</h3>
                  <div class="bfield">
                    <label>Pilih Ruangan</label>
                    <select id="roomSelect" required></select>
                    <p id="bookedSlotsInfo" style="margin-top:6px;font-size:0.8rem;color:#ff6b6b;min-height:1.2em"></p>
                  </div>
                  <div class="bfield">
                    <label>Tanggal</label>
                    <select id="bookingDate" required></select>
                  </div>
                  <div class="brow">
                    <div class="bfield">
                      <label>Jam Mulai</label>
                      <select id="timeStart" required></select>
                    </div>
                    <div class="bfield">
                      <label>Durasi (jam)</label>
                      <select id="duration" required></select>
                    </div>
                  </div>
                </div>

                <div class="form-actions">
                  <button type="submit" class="btn" style="flex:1">Konfirmasi Booking</button>
                  <button type="button" class="btn-ghost" id="clearBookings">Reset</button>
                </div>
              </form>
            </div>

            <!-- SUMMARY CARD -->
            <div class="booking-summary-card">
              <h3 class="bsection-title">📋 Ringkasan</h3>
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
          <div class="rooms-grid" style="margin-top: 12px">
            <div class="room-card" style="padding: 14px">
              <h3 style="color: var(--neon)">Kursi Gaming</h3>
              <p class="muted small">
                Kursi gaming ergonomis dengan bantalan empuk dan sandaran
                nyaman, mendukung postur tubuh agar tetap rileks meski bermain
                dalam waktu lama.
              </p>
            </div>

            <div class="room-card" style="padding: 14px">
              <h3 style="color: var(--neon)">Meja LED</h3>
              <p class="muted small">
                Meja gaming modern dengan pencahayaan LED neon biru yang
                memberikan kesan futuristik dan menambah suasana bermain yang
                imersif.
              </p>
            </div>

            <div class="room-card" style="padding: 14px">
              <h3 style="color: var(--neon)">Speaker</h3>
              <p class="muted small">
                Sistem audio surround berkualitas tinggi yang menghasilkan suara
                jernih dan detail, membuat pengalaman bermain game terasa lebih
                hidup.
              </p>
            </div>

            <div class="room-card" style="padding: 14px">
              <h3 style="color: var(--neon)">TV Besar</h3>
              <p class="muted small">
                Layar TV berukuran besar dengan resolusi tinggi untuk tampilan
                visual yang tajam, warna lebih hidup, dan pengalaman gaming
                maksimal.
              </p>
            </div>

            <div class="room-card" style="padding: 14px">
              <h3 style="color: var(--neon)">AC</h3>
              <p class="muted small">
                Pendingin ruangan yang menjaga suhu tetap sejuk dan nyaman,
                sehingga fokus bermain tetap terjaga tanpa terganggu panas.
              </p>
            </div>
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
    <p class="muted">Pesan langsung dari ruangan saat sedang bermain</p>

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
      <p><strong>Status:</strong> <span class="status-online">Online</span></p>
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

    <footer class="site-footer">© 2026 GameZone — Rahmat Haikal Choa</footer>

<!-- LOGIN MODAL -->
<div id="loginModal" class="modal">
  <div class="modal-overlay"></div>

  <div class="modal-content">
    <h2>Login User</h2>

    <form id="loginForm" action="javascript:void(0)" autocomplete="off">
      <label>
        Username
        <input type="text" required autocomplete="new-password" />
      </label>

      <label>
        Password
        <input type="password" required autocomplete="new-password" />
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
        <a href="#" id="openForgotPassword" style="color:var(--neon)">Lupa Password?</a>
      </p>
    </form>
  </div>
</div>

<!-- REGISTER MODAL -->
<div id="registerModal" class="modal">
  <div class="modal-overlay"></div>

  <div class="modal-content" onclick="event.stopPropagation()">
    <h2>Register User</h2>

    <form id="registerForm" data-ignore-global autocomplete="off">
      <label>
        Username
        <input type="text" id="regUsername" required autocomplete="new-password" />
      </label>

      <label>
        Password
        <input type="password" id="regPassword" required autocomplete="new-password" />
      </label>

      <label>
        Konfirmasi Password
        <input type="password" id="regConfirm" required autocomplete="new-password" />
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
  <div class="modal-content" onclick="event.stopPropagation()">
    <h2>Pesan <span id="orderItemName"></span></h2>
    <p class="small muted" id="orderItemPrice" style="margin-bottom:14px"></p>
    <input type="hidden" id="orderItemId">
    <label>
      Jumlah
      <input type="number" id="orderQty" min="1" max="20" value="1" style="margin-top:6px">
    </label>
    <label style="margin-top:10px">
      Catatan (opsional)
      <input type="text" id="orderNote" placeholder="Contoh: tidak pedas, es batu sedikit" style="margin-top:6px">
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
  <div class="modal-content" onclick="event.stopPropagation()">
    <h2>Reset Password</h2>

    <!-- Step 1: cek username -->
    <div id="forgotStep1">
      <p class="small muted" style="margin-bottom:12px">Masukkan username akunmu untuk mereset password.</p>
      <label>
        Username
        <input type="text" id="forgotUsername" placeholder="Username kamu" required autocomplete="new-password" />
      </label>
      <div class="modal-actions" style="margin-top:12px">
        <button type="button" class="btn" id="forgotCheckBtn">Lanjut</button>
        <button type="button" class="btn-ghost" id="closeForgot">Batal</button>
      </div>
    </div>

    <!-- Step 2: set password baru -->
    <div id="forgotStep2" style="display:none">
      <p class="small muted" style="margin-bottom:12px">Masukkan password baru untuk akun <strong id="forgotUsernameLabel"></strong>.</p>
      <label>
        Password Baru
        <input type="password" id="forgotNewPass" placeholder="Min. 6 karakter" required autocomplete="new-password" />
      </label>
      <label style="margin-top:8px">
        Konfirmasi Password
        <input type="password" id="forgotConfirmPass" placeholder="Ulangi password baru" required autocomplete="new-password" />
      </label>
      <div class="modal-actions" style="margin-top:12px">
        <button type="button" class="btn" id="forgotResetBtn">Reset Password</button>
        <button type="button" class="btn-ghost" id="closeForgot2">Batal</button>
      </div>
    </div>
  </div>
</div>

   <script src="script.js"></script>

   <!-- REFRESH TETAP LOGIN -->
   <script>
  window.AUTH = {
  loggedIn: <?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>,
  username: <?= isset($_SESSION['username']) ? json_encode($_SESSION['username']) : 'null' ?>
};
</script>
  </body>
</html>