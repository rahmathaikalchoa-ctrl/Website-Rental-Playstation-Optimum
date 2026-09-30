// view handler
const $ = (id) => document.getElementById(id);

const NETWORK_ERROR = "Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.";

// Minta login, lalu setelah login berhasil user dibawa kembali ke halaman tujuan
// (dan ruangan yang tadi dipilih, jika ada) — lihat onLoginSuccess.
function requireLogin(message, view, roomId = null) {
  window._pendingLogin = { view, roomId };
  alert(message);
  document.getElementById("loginModal").classList.add("show");
}

// ===== PEMBAYARAN ONLINE (MIDTRANS SNAP) =====
// { enabled, client_key, snap_js } dari payment_api.php; enabled false bila key belum diisi
let paymentConfig = { enabled: false };
let snapLoading = null;

function loadPaymentConfig() {
  return fetch("payment_api.php?action=config")
    .then((r) => r.json())
    .then((c) => {
      paymentConfig = c;
      const opt = document.getElementById("payOnlineOption");
      if (opt) opt.hidden = !c.enabled;
    })
    .catch(() => {});
}

// Snap JS hanya dimuat saat user benar-benar akan membayar
function loadSnap() {
  if (window.snap) return Promise.resolve();
  if (!snapLoading) {
    snapLoading = new Promise((resolve, reject) => {
      const s = document.createElement("script");
      s.src = paymentConfig.snap_js;
      s.setAttribute("data-client-key", paymentConfig.client_key);
      s.onload = resolve;
      s.onerror = () => { snapLoading = null; reject(); };
      document.head.appendChild(s);
    });
  }
  return snapLoading;
}

// Status diambil dari server (yang mengecek ke Midtrans), bukan dari callback browser
function checkPaymentStatus(bookingId) {
  return fetch(`payment_api.php?action=status&booking_id=${encodeURIComponent(bookingId)}&t=${Date.now()}`)
    .then((r) => r.json())
    .then((r) => r.payment_status || null)
    .catch(() => null);
}

// Buka popup pembayaran. onDone(status) dipanggil setelah status terbaru dicek.
function payOnline(bookingId, onDone) {
  if (!paymentConfig.enabled) {
    alert("Pembayaran online belum tersedia. Silakan bayar di kasir.");
    return;
  }
  fetch("payment_api.php", { method: "POST", body: new URLSearchParams({ action: "create", booking_id: bookingId }) })
    .then((r) => r.json())
    .then((res) => {
      if (res.status !== "ok") {
        alert(res.message || "Gagal memulai pembayaran.");
        onDone?.(null);
        return;
      }
      return loadSnap().then(() => {
        const finish = (msg) => checkPaymentStatus(bookingId).then((st) => {
          if (st === "paid") alert("Pembayaran berhasil! Booking kamu sudah lunas.");
          else if (msg) alert(msg);
          onDone?.(st);
        });
        window.snap.pay(res.token, {
          onSuccess: () => finish(),
          onPending: () => finish("Pembayaran menunggu diselesaikan. Ikuti instruksi pembayaran, lalu cek statusnya di Profil."),
          onError: () => finish("Pembayaran gagal. Kamu bisa coba lagi dari Profil atau bayar di kasir."),
          onClose: () => finish("Pembayaran belum selesai. Kamu bisa melanjutkannya dari Profil atau bayar di kasir."),
        });
      });
    })
    .catch(() => {
      alert(NETWORK_ERROR);
      onDone?.(null);
    });
}

loadPaymentConfig();

// Escape data sebelum dipasang lewat innerHTML, supaya judul/deskripsi/catatan
// yang berisi karakter HTML tidak bisa mengeksekusi script (XSS).
function escapeHtml(str) {
  return String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  }[c]));
}

// Ikon SVG (sama dengan icons.php)
const ICON_PATHS = {
  check: '<path d="M20 6 9 17l-5-5"/>',
  clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
  utensils: '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
};
function icon(name) {
  return `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">${ICON_PATHS[name] || ""}</svg>`;
}

const views = {
  home: "view-home",
  consoles: "view-consoles",
  "room-detail": "view-room-detail",
  booking: "view-booking",
  fasilitas: "view-fasilitas",
  game: "view-game",
  menu: "view-menu",
  profile: "view-profile",
};

// ===== AUTH STATE =====
let IS_LOGGED_IN = false;

// Beberapa browser me-restore isi form dari cache halaman (bfcache) saat
// refresh/kembali, tanpa memicu ulang DOMContentLoaded — jadi fungsi ini
// dipanggil baik di DOMContentLoaded maupun di event "pageshow".
function clearNoFillFields() {
  const noFillIds = ["name", "email", "phone", "regUsername",
                     "forgotUsername", "orderNote"];
  noFillIds.forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    // Nama acak tiap load — browser tidak bisa cocokkan ke history tersimpan
    el.setAttribute("name", "nofill_" + Math.random().toString(36).substr(2, 8));
    el.setAttribute("autocomplete", "new-password");
    // Browser suka "mengingat" isi form terakhir saat halaman di-refresh (F5),
    // terlepas dari localStorage/JS kita — kosongkan paksa tiap load supaya
    // form selalu bersih, bukan cuma setelah submit sukses.
    el.value = "";
  });
  // Nama pemesan tetap diisi otomatis dari akun setelah form dikosongkan
  const nameInput = document.getElementById("name");
  if (nameInput && IS_LOGGED_IN && window.AUTH?.username) nameInput.value = window.AUTH.username;
}

window.addEventListener("pageshow", (e) => {
  clearNoFillFields();
  // Halaman dipulihkan dari bfcache (tombol Back): status login bisa basi
  // (mis. sudah logout), jadi reload kalau berbeda dengan server.
  if (e.persisted) {
    fetch("session.php", { cache: "no-store" })
      .then((res) => res.json())
      .then((sess) => {
        if (!!sess.logged_in !== IS_LOGGED_IN) location.reload();
      })
      .catch(() => {});
  }
});
// Chrome kadang me-restore value form SETELAH DOMContentLoaded/pageshow selesai
// (bagian dari mekanisme internal reload-nya) — jadi kosongkan lagi beberapa saat
// setelah load untuk menimpa restorasi yang telat itu.
window.addEventListener("load", () => {
  setTimeout(clearNoFillFields, 0);
  setTimeout(clearNoFillFields, 300);
});

document.addEventListener("DOMContentLoaded", () => {
  /* ================= BLOKIR AUTOFILL CHROME ================= */
  clearNoFillFields();

  /* ================= SESSION CHECK ON LOAD ================= */
  // Pakai status dari PHP dulu supaya klik sebelum fetch selesai tidak salah ditolak
  IS_LOGGED_IN = !!window.AUTH?.loggedIn;
  fetch("session.php")
    .then((res) => res.json())
    .then((sess) => {
      if (sess.logged_in) {
        IS_LOGGED_IN = true;
        setLoggedIn(sess.username);
      } else {
        IS_LOGGED_IN = false;
        setLoggedOut();
      }
    })
    .catch(() => {
      // Gangguan jaringan: pertahankan status dari PHP, jangan paksa logout
      if (IS_LOGGED_IN) setLoggedIn(window.AUTH.username);
      else setLoggedOut();
    });

  let roomsData = [];

  // games data
  let gamesData = [];

  function formatRup(n) {
    return (
      "Rp " + (Number(n) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".")
    );
  }

  const statusConfig = {
    available:  { label: "Kosong",     cls: "status-available" },
    occupied:   { label: "Ada Orang",  cls: "status-occupied"  },
    in_service: { label: "In Service", cls: "status-inservice" },
  };

  function createRoomCard(room) {
    const card = document.createElement("div");
    card.className = "room-card";

    const media = document.createElement("div");
    media.className = "room-media";
    media.style.backgroundImage = `url(${JSON.stringify(String(room.img))})`;

    // status badge di atas gambar
    const st = statusConfig[room.currentStatus] || statusConfig.available;
    const badge = document.createElement("span");
    badge.className = `room-status-badge ${st.cls}`;
    badge.textContent = st.label;
    media.appendChild(badge);

    const body = document.createElement("div");
    body.className = "room-body";
    const h = document.createElement("h3");
    h.textContent = room.title;
    const p = document.createElement("p");
    p.className = "muted small";
    p.textContent = room.desc;
    const meta = document.createElement("div");
    meta.className = "room-meta";
    const pr = document.createElement("strong");
    pr.textContent = `${formatRup(room.price)}/jam`;

    const btnDetail = document.createElement("button");
    btnDetail.className = "btn-detail";
    btnDetail.textContent = "Detail";
    btnDetail.setAttribute("data-detail", room.id);

    const btnBook = document.createElement("button");
    btnBook.style.marginLeft = "8px";
    if (room.currentStatus !== "in_service") {
      btnBook.className = "btn";
      btnBook.textContent = "Pesan";
      btnBook.setAttribute("data-book", room.id);
    } else {
      btnBook.className = "btn btn-disabled";
      btnBook.textContent = "Tidak Tersedia";
      btnBook.disabled = true;
    }

    meta.appendChild(pr);
    const rightBox = document.createElement("div");
    rightBox.style.display = "flex";
    rightBox.style.gap = "8px";
    rightBox.appendChild(btnDetail);
    rightBox.appendChild(btnBook);
    meta.appendChild(rightBox);

    body.appendChild(h);
    body.appendChild(p);
    body.appendChild(meta);
    card.appendChild(media);
    card.appendChild(body);
    return card;
  }

  // render preview — ambil 3 ruangan paling sering dibooking
  function renderPreview() {
    const g = $("roomsGrid");
    g.innerHTML = "";

    fetch("popular_rooms_api.php")
      .then((res) => res.json())
      .then((data) => {
        g.innerHTML = "";
        if (data.length === 0) {
          g.innerHTML = "<p class='muted'>Belum ada ruangan tersedia.</p>";
          return;
        }
        data.forEach((r) => {
          g.appendChild(createRoomCard({
            id:            Number(r.id),
            title:         r.title,
            consoleType:   r.console_type,
            price:         Number(r.price),
            img:           r.image || "https://via.placeholder.com/400x200",
            desc:          r.description || "Tidak ada deskripsi",
            currentStatus: r.current_status || "available",
          }));
        });
      })
      .catch(() => {
        g.innerHTML = "<p class='muted'>Gagal memuat ruangan.</p>";
      });
  }

  const roomCatMap = {
    Semua:   null,
    Economy: "PS3",
    Premium: "PS4",
    Deluxe:  "PS5",
  };
  let activeRoomCat = "Semua";

  function renderRoomCatTabs() {
    const container = $("roomCatTabs");
    if (!container) return;
    container.innerHTML = Object.keys(roomCatMap).map(cat =>
      `<button class="genre-btn${cat === activeRoomCat ? ' active' : ''}" data-roomcat="${cat}">${cat}</button>`
    ).join("");
  }

  function renderAll() {
    const grid = $("allRoomsGrid");
    if (!grid) return;
    grid.innerHTML = "";

    const consoleFilter = roomCatMap[activeRoomCat];
    const filtered = consoleFilter
      ? roomsData.filter(r => r.consoleType === consoleFilter)
      : roomsData;

    if (filtered.length === 0) {
      grid.innerHTML = "<p class='muted'>Belum ada ruangan untuk kategori ini.</p>";
    } else {
      filtered.forEach(r => grid.appendChild(createRoomCard(r)));
    }

    renderRoomCatTabs();
  }

  function showDetail(id) {
    const room = roomsData.find((r) => r.id === id);
    if (!room) return;

    const compatibleGames = gamesData.filter(g => g.platforms.includes(room.consoleType));

    const gamesHtml = compatibleGames.length > 0
      ? compatibleGames.map(g => {
          const cover = g.image ? `assets/images/games/${g.image}` : `assets/images/games/default.jpg`;
          return `
            <div class="game-mini-card">
              <img src="${escapeHtml(cover)}" alt="${escapeHtml(g.title)}" onerror="this.src='assets/images/games/default.jpg'">
              <div class="game-mini-body">
                <p class="game-mini-title">${escapeHtml(g.title)}</p>
                <p class="muted small">${escapeHtml(g.genre)}</p>
              </div>
            </div>`;
        }).join('')
      : `<p class="muted small">Tidak ada game untuk konsol ini.</p>`;

    const stDetail = statusConfig[room.currentStatus] || statusConfig.available;
    const bookBtnHtml = room.currentStatus !== "in_service"
      ? `<button class="btn" data-book="${room.id}">Booking</button>`
      : `<button class="btn btn-disabled" disabled style="opacity:0.45;cursor:not-allowed">Ruangan Tidak Tersedia</button>`;

    const box = $("roomDetail");
    box.innerHTML = `
      <h2 style="color:var(--neon);margin-bottom:10px">${escapeHtml(room.title)}
        <span class="room-status-badge ${stDetail.cls}" style="position:static;display:inline-block;margin-left:10px;vertical-align:middle">${stDetail.label}</span>
      </h2>
      <img src="${escapeHtml(room.img)}" alt="${escapeHtml(room.title)}" style="width:100%;height:300px;object-fit:cover;border-radius:10px;margin-bottom:12px">
      <p class="muted">${escapeHtml(room.desc)}</p>
      <p style="margin-top:6px"><strong>${formatRup(room.price)}/jam</strong></p>
      <div style="margin-top:12px;display:flex;gap:8px">
        ${bookBtnHtml}
        <button class="btn-ghost" data-action="back-to-rooms">Kembali ke list</button>
      </div>
      <div style="margin-top:24px">
        <h3 style="color:var(--neon);margin-bottom:4px">Jadwal Hari Ini</h3>
        <p class="muted small" style="margin-bottom:8px">Untuk besok atau lusa, cek jam yang tersedia di form booking.</p>
        <div id="roomTimeline" class="room-timeline"><span class="muted small">Memuat jadwal...</span></div>
      </div>
      <div style="margin-top:24px">
        <h3 style="color:var(--neon);margin-bottom:12px">Game Tersedia di ${escapeHtml(room.consoleType)}</h3>
        <div class="game-mini-list">${gamesHtml}</div>
      </div>
    `;
    showView("room-detail");

    fetch(`booked_slots_api.php?room_id=${room.id}`)
      .then((r) => r.json())
      .then((slots) => {
        const tl = document.getElementById("roomTimeline");
        if (!tl) return;
        const hours = [];
        const midnight = new Date();
        midnight.setHours(0, 0, 0, 0);
        const dayStart = midnight.getTime() / 1000;
        for (let h = 11; h <= 23; h++) {
          // Bandingkan timestamp (bukan getHours) supaya sesi berakhir 24:00
          // atau mulai di menit ganjil (dari antrian) tetap terdeteksi
          const hs = dayStart + h * 3600;
          const booked = slots.some((s) => hs < s.end_time && hs + 3600 > s.start_time);
          hours.push(
            `<div class="tl-hour ${booked ? "tl-booked" : "tl-free"}" title="${h}:00 — ${booked ? "Terpesan" : "Kosong"}">
               <span>${h}</span>
             </div>`
          );
        }
        tl.innerHTML = hours.join("");
      })
      .catch(() => {
        const tl = document.getElementById("roomTimeline");
        if (tl) tl.innerHTML = "<span class='muted small'>Gagal memuat jadwal.</span>";
      });
  }

  function populateSelect() {
    const sel = $("roomSelect");
    sel.innerHTML = '<option value="" disabled selected>-- Pilih Ruangan --</option>';
    roomsData.forEach((r) => {
      const op = document.createElement("option");
      op.value = r.id;
      // Status "sedang dipakai" tidak relevan untuk jam/tanggal lain, jadi tidak ditampilkan.
      // Ketersediaan jam dicek per slot lewat booked_slots_api.php.
      op.textContent = r.title;
      if (r.currentStatus === "in_service") {
        op.textContent += " (perawatan)";
        op.disabled = true;
      }
      sel.appendChild(op);
    });
  }

  function prefillBookingUser() {
    const welcome = $("bookingWelcome");

    // Tampilkan username di indikator akun (tidak bisa diedit)
    if (IS_LOGGED_IN && window.AUTH?.username) {
      const accountBadge = document.getElementById("bookingAccountName");
      if (accountBadge) accountBadge.textContent = window.AUTH.username;
      if (welcome) welcome.textContent = `Halo, ${window.AUTH.username}! Pilih cara booking di bawah.`;
      const nameInput = $("name");
      if (nameInput && !nameInput.value) nameInput.value = window.AUTH.username;
    }
  }

  function updateBookingSummary() {
    const box       = $("bookingSummary");
    if (!box) return;

    const roomId   = $("roomSelect")?.value;
    const dur      = parseInt($("duration")?.value) || 0;
    const timeVal  = $("timeStart")?.value || "";
    const dateSel  = document.getElementById("bookingDate");
    const dateVal  = dateSel?.value || getTodayISO();
    const dateLabel = dateSel?.options[dateSel.selectedIndex]?.text || dateVal;

    const room = roomsData.find((r) => String(r.id) === String(roomId));

    if (!room || !dur || !timeVal) {
      box.innerHTML = `<div class="summary-placeholder"><p class="muted small">Pilih ruangan & durasi<br>untuk melihat estimasi biaya.</p></div>`;
      return;
    }

    const total = room.price * dur;

    const [h, m] = timeVal.split(":").map(Number);
    const endH   = ((h + dur) % 24).toString().padStart(2, "0");
    const endTime = `${endH}:${m.toString().padStart(2, "0")}`;

    box.innerHTML = `
      <div class="summary-row">
        <span class="sr-label">Ruangan</span>
        <span class="sr-val">${escapeHtml(room.title)}</span>
      </div>
      <div class="summary-row">
        <span class="sr-label">Konsol</span>
        <span class="sr-val">${escapeHtml(room.consoleType)}</span>
      </div>
      <div class="summary-row">
        <span class="sr-label">Tanggal</span>
        <span class="sr-val">${dateLabel}</span>
      </div>
      <div class="summary-row">
        <span class="sr-label">Sesi</span>
        <span class="sr-val">${timeVal} – ${endTime}</span>
      </div>
      <div class="summary-row">
        <span class="sr-label">Durasi</span>
        <span class="sr-val">${dur} jam</span>
      </div>
      <div class="summary-row">
        <span class="sr-label">Harga/jam</span>
        <span class="sr-val">${formatRup(room.price)}</span>
      </div>
      <div class="summary-row total-row">
        <span class="sr-label">Total</span>
        <span class="sr-val">${formatRup(total)}</span>
      </div>
    `;
  }

  // Ganti isi kotak Ringkasan dengan bukti booking (kode, jadwal, total, cara bayar).
  // payStatus: "paid" | "pending" | lainnya (belum bayar)
  function showBookingConfirmation(b, payStatus = "unpaid") {
    const box = $("bookingSummary");
    if (!box) return;
    const start = new Date(b.start_time * 1000);
    const fmtTime = (d) => d.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
    const dateText = start.toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long" });
    const checkin = b.expires_at ? fmtTime(new Date(b.expires_at * 1000)) : null;
    const lapor = checkin ? ` Lapor paling lambat <strong>${checkin}</strong>, lewat dari itu booking batal otomatis.` : "";
    const note = payStatus === "paid"
      ? `Sudah lunas. Tunjukkan kode ini ke kasir saat datang.${checkin ? ` Lapor paling lambat <strong>${checkin}</strong>.` : ""}`
      : payStatus === "pending"
        ? `Menunggu pembayaran online. Selesaikan pembayaran, atau bayar di kasir saat datang.${lapor}`
        : `Tunjukkan kode ini dan bayar di kasir saat datang.${lapor}`;
    const payBtn = payStatus !== "paid" && paymentConfig.enabled
      ? `<button type="button" class="btn" data-pay-booking="${escapeHtml(b.id)}">Bayar Online Sekarang</button>`
      : "";
    // Simpan data booking agar kartu bisa digambar ulang setelah status bayar berubah
    box.dataset.booking = JSON.stringify(b);
    box.innerHTML = `
      <div class="confirm-card">
        <div class="confirm-head">${icon('check')} Booking berhasil${payStatus === "paid" ? " · Lunas" : ""}</div>
        <div>
          <span class="sr-label">Kode booking</span>
          <div class="confirm-code">${escapeHtml(b.order_code)}</div>
        </div>
        <div class="summary-row"><span class="sr-label">Ruangan</span><span class="sr-val">${escapeHtml(b.room)}</span></div>
        <div class="summary-row"><span class="sr-label">Tanggal</span><span class="sr-val">${dateText}</span></div>
        <div class="summary-row"><span class="sr-label">Sesi</span><span class="sr-val">${fmtTime(start)} – ${fmtTime(new Date(b.end_time * 1000))}</span></div>
        <div class="summary-row total-row"><span class="sr-label">Total</span><span class="sr-val">${formatRup(b.total_price)}</span></div>
        <p class="confirm-note">${note}</p>
        ${payBtn}
        <button type="button" class="btn-ghost" data-view="profile">Lihat di Profil</button>
      </div>`;
  }

  // Update summary setiap kali room/durasi/waktu berubah
  document.addEventListener("change", (e) => {
    if (["roomSelect", "duration", "timeStart", "bookingDate"].includes(e.target.id)) {
      updateBookingSummary();
    }

    // Update preview biaya saat jam perpanjangan berubah
    if (e.target.id?.startsWith("extend-hours-")) {
      const bid    = e.target.id.replace("extend-hours-", "");
      const price  = parseInt(e.target.dataset.price) || 0;
      const extra  = parseInt(e.target.value) || 1;
      const costEl = document.getElementById(`extend-cost-${bid}`);
      if (costEl) costEl.textContent = `+${formatRup(price * extra)}`;
    }
  });

  let activeGenre = 'Semua';

  function renderGenreFilters() {
    const container = $("genreFilters");
    if (!container) return;
    const genres = ['Semua', ...[...new Set(gamesData.map(g => g.genre))].sort()];
    container.innerHTML = genres.map(g =>
      `<button class="genre-btn${g === activeGenre ? ' active' : ''}" data-genre="${escapeHtml(g)}">${escapeHtml(g)}</button>`
    ).join('');
  }

  function renderGames() {
    const wrap = $("gameList");
    wrap.innerHTML = "";

    const filtered = activeGenre === 'Semua'
      ? gamesData
      : gamesData.filter(g => g.genre === activeGenre);

    if (filtered.length === 0) {
      wrap.innerHTML = "<p class='muted'>Tidak ada game untuk genre ini.</p>";
      return;
    }

    filtered.forEach((g) => {
      const card = document.createElement("div");
      card.className = "room-card";

      const badges = g.platforms
        .map((p) => `<span class="badge badge-${escapeHtml(p.toLowerCase())}">${escapeHtml(p)}</span>`)
        .join(" ");

      const cover = g.image ? `assets/images/games/${g.image}` : `assets/images/games/default.jpg`;
      const coverHtml = `<img class="game-cover-img" src="${escapeHtml(cover)}" alt="${escapeHtml(g.title)}" onerror="this.src='assets/images/games/default.jpg'">`;

      card.innerHTML = `
      ${coverHtml}
      <div class="room-body">
        <h3 style="color:var(--neon)">${escapeHtml(g.title)}</h3>
        <p class="muted small">${escapeHtml(g.genre)}</p>
        <div style="margin-top:8px">${badges}</div>
      </div>
    `;
      wrap.appendChild(card);
    });
  }

  document.addEventListener("click", (e) => {
    // 1. DETAIL KAMAR (PRIORITAS UTAMA)
    const detailBtn = e.target.closest("[data-detail]");
    if (detailBtn) {
      e.preventDefault();
      showDetail(Number(detailBtn.dataset.detail));
      return;
    }

    // 2. BOOKING DARI DETAIL / CARD
    const bookBtn = e.target.closest("[data-book]");
    if (bookBtn) {
      e.preventDefault();

      if (!IS_LOGGED_IN) {
        requireLogin("Silakan login terlebih dahulu untuk booking.", "booking", bookBtn.dataset.book);
        return;
      }

      populateSelect();
      generateDateOptions();
      generateTimeOptions();
      updateDurationOptions();
      prefillBookingUser();
      const sel = $("roomSelect");
      if (sel) sel.value = bookBtn.dataset.book;
      fetchBookedSlots(bookBtn.dataset.book);
      updateBookingSummary();
      showView("booking");
      return;
    }

    // 1. JANGAN SENTUH KLIK DI DALAM MODAL (LOGIN / REGISTER)
    if (e.target.closest(".modal")) {
      return;
    }

    // ===== NAVIGATION =====
    const vbtn = e.target.closest("[data-view]");
    if (vbtn) {
      const v = vbtn.dataset.view;

      e.preventDefault();

      if (v === "login") return;
      if (v === "profile") {
        // Sama seperti link "Detail" di dropdown profil — supaya tombol
        // "Lihat Detail" di banner booking mendatang (Beranda) menampilkan
        // data booking yang sama, bukan halaman profil kosong.
        openProfileDetail();
        return;
      }
      if (v === "consoles") renderAll();
      if (v === "booking") {
        if (!IS_LOGGED_IN) {
          requireLogin("Silakan login terlebih dahulu untuk melakukan booking.", "booking");
          return;
        }

        populateSelect();
        generateDateOptions();
        generateTimeOptions();
        updateDurationOptions();
        prefillBookingUser();
        updateBookingSummary();
        const selVal = $("roomSelect")?.value;
        // Tanpa ruangan terpilih, ini mengosongkan info jam terpesan ruangan sebelumnya
        fetchBookedSlots(selVal);
      }
      if (v === "game") loadGamesFromDB();
      if (v === "menu") {
        loadMenu();
        loadMyOrders();
        startMenuRefresh();
      } else {
        stopMenuRefresh();
      }

      showView(v);
      return;
    }
  });

  // back link
  $("backToRooms").addEventListener("click", (ev) => {
    ev.preventDefault();
    ev.stopPropagation();
    renderAll();
    showView("consoles");
  });

  // reset booking form
  $("clearBookings").addEventListener("click", () => {
    $("bookingForm").reset();
    // Kosongkan slot lewat fetchBookedSlots supaya respons lama yang masih jalan diabaikan
    fetchBookedSlots("");
    generateDateOptions();
    generateTimeOptions();
    updateDurationOptions();
    applyBookedSlots();
    prefillBookingUser();
    updateBookingSummary();
  });

  // submit booking
  $("bookingForm").addEventListener("submit", (e) => {
    e.preventDefault();

    if (!IS_LOGGED_IN) {
      requireLogin("Silakan login terlebih dahulu untuk melakukan booking.", "booking");
      return;
    }

    const name = $("name").value.trim();
    const email = $("email").value.trim();
    const phone = $("phone").value.trim();
    const roomId = $("roomSelect").value;
    const time = $("timeStart").value;
    const duration = $("duration").value;

    if (!time || !duration) {
      alert("Tidak ada jam tersisa untuk tanggal ini. Silakan pilih tanggal lain.");
      return;
    }

    if (!name || !roomId) {
      alert("Isi nama dan pilih ruangan terlebih dahulu.");
      return;
    }

    if (phone && !/^[0-9]{9,14}$/.test(phone)) {
      alert("Nomor HP tidak valid (hanya angka, 9-14 digit).");
      return;
    }

    const bookingDate = document.getElementById("bookingDate")?.value || getTodayISO();
    // Dibaca sebelum form di-reset setelah booking berhasil
    const payMethod = $("bookingForm").querySelector('input[name="pay_method"]:checked')?.value || "cashier";

    const submitBtn = $("bookingForm").querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch("apikr.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: new URLSearchParams({
        name,
        email,
        phone,
        room_id: roomId,
        time,
        duration,
        date: bookingDate,
      }),
    })
      .then((r) => r.json())
      .then((r) => {
        if (r.status === "success") {
          loadUpcomingBooking();
          $("bookingForm").reset();
          generateDateOptions();
          generateTimeOptions();
          updateDurationOptions();
          // Form kembali ke "Pilih Ruangan": kosongkan slot & info ruangan sebelumnya
          fetchBookedSlots($("roomSelect").value);
          prefillBookingUser();
          showBookingConfirmation(r);
          if (payMethod === "midtrans") {
            payOnline(r.id, (st) => {
              if (st) showBookingConfirmation(r, st);
              loadUpcomingBooking();
            });
          }
        } else {
          alert("Booking gagal: " + (r.message || "Terjadi kesalahan"));
        }
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { if (submitBtn) submitBtn.disabled = false; });
  });

  // search center form
  $("navSearchForm").addEventListener("submit", (ev) => {
    ev.preventDefault();
    const q = ($("navSearchInput").value || "").trim().toLowerCase();
    if (!q) {
      alert("Masukkan kata kunci pencarian.");
      return;
    }
    stopMenuRefresh();
    const roomFound = roomsData.find((r) =>
      (r.title + " " + r.desc).toLowerCase().includes(q),
    );
    if (roomFound) {
      showDetail(roomFound.id);
      return;
    }
    const gameFound = gamesData.find((g) => g.title.toLowerCase().includes(q));
    if (gameFound) {
      alert(
        `Game ditemukan: ${
          gameFound.title
        } — bisa dimainkan di: ${gameFound.platforms.join(", ")}`,
      );
      loadGamesFromDB();
      showView("game");
      return;
    }
    alert("Tidak ditemukan hasil untuk: " + q);
  });

  // load data dari database SETELAH UI SIAP
  loadRoomsFromDB();
  fetch("games_api.php")
    .then((r) => r.json())
    .then((data) => { gamesData.length = 0; data.forEach((g) => gamesData.push(g)); })
    .catch(() => {});

  function refreshRoomsUI() {
    if (!Array.isArray(roomsData) || roomsData.length === 0) return;
    renderPreview();
    renderAll();
    populateSelect();
  }

  // TAMBAH ROOMS BY SQL
  function loadRoomsFromDB() {
    fetch("rooms_api.php")
      .then((res) => res.json())
      .then((data) => {
        roomsData.length = 0;
        data.forEach((r) => {
          roomsData.push({
            id:            Number(r.id),
            title:         r.title,
            consoleType:   r.console_type,
            price:         Number(r.price),
            img:           r.image || "https://via.placeholder.com/400x200",
            desc:          r.description || "Tidak ada deskripsi",
            currentStatus: r.current_status || "available",
          });
        });
        refreshRoomsUI();
      })
      .catch((err) => console.error("Rooms load error:", err));
  }

  document.addEventListener("click", (e) => {
    // Filter genre game
    const genreBtn = e.target.closest(".genre-btn[data-genre]");
    if (genreBtn) {
      activeGenre = genreBtn.dataset.genre;
      renderGenreFilters();
      renderGames();
      return;
    }

    // Filter kategori ruangan
    const roomCatBtn = e.target.closest(".genre-btn[data-roomcat]");
    if (roomCatBtn) {
      activeRoomCat = roomCatBtn.dataset.roomcat;
      renderAll();
      return;
    }

    // Kembali ke daftar ruangan dari detail
    if (e.target.closest("[data-action='back-to-rooms']")) {
      renderAll();
      showView("consoles");
    }
  });

  // FUNCTION LOADGAMES DB
  function loadGamesFromDB() {
    fetch("games_api.php")
      .then((res) => res.json())
      .then((data) => {
        gamesData.length = 0;
        activeGenre = 'Semua';
        data.forEach((g) => gamesData.push(g));
        renderGenreFilters();
        renderGames();
      })
      .catch(() => {
        $("gameList").innerHTML = "<p class='muted'>Gagal memuat game.</p>";
      });
  }

  // ===== MENU MAKANAN & MINUMAN =====
  let menuData = [];
  let activeMenuCat = 'Semua';
  let menuOrdersTimer = null;

  function startMenuRefresh() {
    stopMenuRefresh();
    menuOrdersTimer = setInterval(() => {
      loadMyOrders(true); // silent = tanpa loading text
    }, 15000);
  }

  function stopMenuRefresh() {
    if (menuOrdersTimer) {
      clearInterval(menuOrdersTimer);
      menuOrdersTimer = null;
    }
  }

  function renderMenuCatTabs() {
    const container = $("menuCatTabs");
    if (!container) return;
    const cats = ['Semua', 'Makanan', 'Minuman'];
    container.innerHTML = cats.map(c =>
      `<button class="menu-cat-btn${c === activeMenuCat ? ' active' : ''}" data-cat="${c}">${c}</button>`
    ).join('');
  }

  function renderMenuGrid() {
    const grid = $("menuGrid");
    if (!grid) return;
    grid.innerHTML = "";
    const filtered = activeMenuCat === 'Semua'
      ? menuData
      : menuData.filter(m => m.category === activeMenuCat.toLowerCase());

    if (filtered.length === 0) {
      grid.innerHTML = "<p class='muted'>Belum ada item menu.</p>";
      return;
    }

    filtered.forEach((item) => {
      const card = document.createElement("div");
      card.className = "room-card menu-item-card";

      const imgSrc = item.image
        ? `assets/images/menu/${item.image}`
        : `assets/images/menu/default.jpg`;

      const stockBadge = item.is_available == 1
        ? `<span class="menu-stock-badge stock-tersedia">Tersedia</span>`
        : `<span class="menu-stock-badge stock-habis">Stok Habis</span>`;

      const btnPesan = item.is_available == 1
        ? `<button class="btn menu-order-btn" data-id="${escapeHtml(item.id)}" data-name="${escapeHtml(item.name)}" data-price="${escapeHtml(item.price)}">Pesan</button>`
        : `<button class="btn btn-disabled" disabled>Stok Habis</button>`;

      card.innerHTML = `
        <div class="menu-img-wrap">
          <img src="${escapeHtml(imgSrc)}" alt="${escapeHtml(item.name)}" onerror="this.src='assets/images/games/default.jpg'">
          ${stockBadge}
        </div>
        <div class="room-body">
          <h3 style="color:var(--neon)">${escapeHtml(item.name)}</h3>
          <p class="muted small">${escapeHtml(item.description || '')}</p>
          <div class="room-meta" style="margin-top:10px">
            <strong>${formatRup(item.price)}</strong>
            ${btnPesan}
          </div>
        </div>
      `;
      grid.appendChild(card);
    });
  }

  function loadMenu() {
    fetch("menu_api.php")
      .then((res) => res.json())
      .then((data) => {
        menuData = data;
        renderMenuCatTabs();
        renderMenuGrid();
      })
      .catch(() => {
        const grid = $("menuGrid");
        if (grid) grid.innerHTML = "<p class='muted'>Gagal memuat menu.</p>";
      });
  }

  function loadMyOrders(silent = false) {
    const box = $("myOrdersList");
    if (!box) return;
    if (!silent) box.innerHTML = "<p class='muted small'>Memuat pesanan...</p>";

    fetch("menu_order_api.php?action=my_orders")
      .then((res) => res.json())
      .then((data) => {
        if (data.length === 0) {
          box.innerHTML = "<p class='muted small'>Belum ada pesanan.</p>";
          return;
        }

        // Cek apakah ada status yg baru berubah jadi selesai
        if (silent) {
          const prevDone = new Set(
            [...box.querySelectorAll(".menu-order-item[data-status='selesai']")]
              .map(el => el.dataset.id)
          );
          data.forEach(o => {
            if (o.status === 'selesai' && !prevDone.has(String(o.id))) {
              showOrderNotif(o.name);
            }
          });
        }

        box.innerHTML = data.map(o => {
          const total = formatRup(o.price * o.quantity);
          const statusCls = o.status === 'selesai' ? 'order-done' : 'order-pending';
          const statusLabel = o.status === 'selesai' ? `${icon('check')} Sudah Sampai` : `${icon('clock')} Diproses`;
          const tgl = new Date(o.created_at).toLocaleString('id-ID', {
            day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
          });
          const note = o.note ? `<p class="muted small">Catatan: ${escapeHtml(o.note)}</p>` : '';
          return `
            <div class="menu-order-item" data-id="${escapeHtml(o.id)}" data-status="${escapeHtml(o.status)}">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                <div>
                  <strong>${escapeHtml(o.name)}</strong>
                  <p class="muted small" style="text-transform:capitalize">${escapeHtml(o.category)} · ${Number(o.quantity)}x · ${total}</p>
                  ${note}
                  <p class="muted small">${tgl}</p>
                </div>
                <span class="menu-order-status ${statusCls}">${statusLabel}</span>
              </div>
            </div>`;
        }).join('');
      })
      .catch(() => {
        if (!silent) box.innerHTML = "<p class='muted small'>Gagal memuat pesanan.</p>";
      });
  }

  function showOrderNotif(itemName) {
    const notif = document.createElement("div");
    notif.className = "order-notif";
    notif.innerHTML = `${icon('utensils')} "${escapeHtml(itemName)}" sudah sampai!`;
    document.body.appendChild(notif);
    requestAnimationFrame(() => notif.classList.add("show"));
    setTimeout(() => {
      notif.classList.remove("show");
      setTimeout(() => notif.remove(), 400);
    }, 4000);
  }

  // Dipakai juga oleh kode antrian di luar closure ini
  window.loadUpcomingBooking = loadUpcomingBooking;
  function loadUpcomingBooking() {
    const banner = document.getElementById("upcomingBanner");
    const detail = document.getElementById("upcomingDetail");
    if (!banner || !IS_LOGGED_IN) return;

    fetch("upcoming_booking_api.php")
      .then((r) => r.json())
      .then((data) => {
        // Bisa saja user logout saat request ini masih berjalan
        if (!data || !IS_LOGGED_IN) { banner.style.display = "none"; return; }
        const fmt = (u) => new Date(u * 1000).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
        const now = Date.now() / 1000;
        const label = document.getElementById("upcomingLabel");
        const started = data.start_time <= now;
        const waitingArrival = started && data.expires_at;
        if (label) {
          label.textContent = waitingArrival ? "Menunggu Kedatanganmu"
            : started ? "Sesi Sedang Berjalan" : "Booking Mendatang";
        }
        if (detail) {
          const day = new Date(data.start_time * 1000).toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "short" });
          let text = started
            ? `${data.room} — selesai ${fmt(data.end_time)}`
            : `${data.room} — ${day}, jam ${fmt(data.start_time)} (${data.duration} jam) · ${data.order_code}`;
          if (data.expires_at) text += ` · lapor ke kasir sebelum ${fmt(data.expires_at)}`;
          detail.textContent = text;
        }
        banner.style.display = "block";
      })
      .catch(() => { banner.style.display = "none"; });
  }

  function showSessionWarning(roomName, minutes) {
    const notif = document.createElement("div");
    notif.className = "order-notif session-warning";
    notif.innerHTML = `${icon('alert')} Sesi <strong>${escapeHtml(roomName)}</strong> tersisa ${minutes} menit!`;
    document.body.appendChild(notif);
    requestAnimationFrame(() => notif.classList.add("show"));
    setTimeout(() => {
      notif.classList.remove("show");
      setTimeout(() => notif.remove(), 400);
    }, 8000);
  }

  // ===== TOMBOL BAYAR ONLINE (kartu konfirmasi & profil) =====
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-pay-booking]");
    if (!btn) return;
    btn.disabled = true;
    const inSummary = !!btn.closest("#bookingSummary");
    const inProfile = !!btn.closest("#profileBookingList");
    payOnline(btn.dataset.payBooking, (st) => {
      btn.disabled = false;
      const box = $("bookingSummary");
      if (inSummary && st && box?.dataset.booking) showBookingConfirmation(JSON.parse(box.dataset.booking), st);
      if (inProfile) document.getElementById("profileDetail")?.click();
      loadUpcomingBooking();
    });
  });

  // ===== EXTEND BOOKING EVENT DELEGATION =====
  document.addEventListener("click", (e) => {
    // Tombol "Perpanjang" → tampilkan form + cek ketersediaan jam
    const extBtn = e.target.closest(".extend-btn");
    if (extBtn) {
      const bid      = extBtn.dataset.bid;
      const roomId   = extBtn.dataset.roomid;
      const endTs    = parseInt(extBtn.dataset.end);
      const price    = parseInt(extBtn.dataset.price) || 0;
      const curDur   = parseInt(extBtn.dataset.duration) || 0;
      const startTs  = parseInt(extBtn.dataset.start);
      const form     = document.getElementById(`extend-form-${bid}`);
      if (!form) return;

      const isOpen = form.style.display === "block";
      form.style.display = isOpen ? "none" : "block";
      if (isOpen) return;

      // Mirror batas server: total durasi maks 12 jam & tidak boleh lewat tengah malam
      // hari mulainya sesi (lihat extend_booking.php).
      const maxTotalDuration = 12;
      const startDate = new Date(startTs * 1000);
      const dayStart  = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate()).getTime() / 1000;
      const closeLimit = dayStart + 24 * 3600;

      fetch(`booked_slots_api.php?room_id=${roomId}`)
        .then((r) => r.json())
        .then((slots) => {
          const sel        = document.getElementById(`extend-hours-${bid}`);
          const noAvailEl  = document.getElementById(`extend-noavail-${bid}`);
          const costEl     = document.getElementById(`extend-cost-${bid}`);
          if (!sel) return;

          let anyAvailable = false;
          Array.from(sel.options).forEach((opt) => {
            const extra  = parseInt(opt.value);
            const newEnd = endTs + extra * 3600;
            const clash  = slots.some(
              (s) => parseInt(s.start_time) < newEnd && parseInt(s.end_time) > endTs
            );
            const overCap   = curDur + extra > maxTotalDuration;
            const pastClose = newEnd > closeLimit;
            const disabled  = clash || overCap || pastClose;

            opt.disabled    = disabled;
            opt.textContent = clash ? `+${extra} jam — Terpesan`
              : overCap ? `+${extra} jam — Lewat batas 12 jam`
              : pastClose ? `+${extra} jam — Lewat jam tutup`
              : `+${extra} jam`;
            if (!disabled) anyAvailable = true;
          });

          if (noAvailEl) noAvailEl.style.display = anyAvailable ? "none" : "block";

          const first = Array.from(sel.options).find((o) => !o.disabled);
          if (first) {
            sel.value = first.value;
            if (costEl) costEl.textContent = `+${formatRup(price * parseInt(first.value))}`;
          }
        })
        .catch(() => {});
      return;
    }

    // Tombol "Batal"
    const cancelBtn = e.target.closest("[data-extend-cancel]");
    if (cancelBtn) {
      const form = document.getElementById(`extend-form-${cancelBtn.dataset.extendCancel}`);
      if (form) form.style.display = "none";
      return;
    }

    // Tombol "Konfirmasi" extend
    const confirmBtn = e.target.closest("[data-extend]");
    if (confirmBtn) {
      const bid        = confirmBtn.dataset.extend;
      const extraHours = document.getElementById(`extend-hours-${bid}`)?.value || 1;

      confirmBtn.disabled = true;
      confirmBtn.textContent = "Memproses...";

      fetch("extend_booking.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ booking_id: bid, extra_hours: extraHours }),
      })
        .then((r) => r.json())
        .then((res) => {
          if (res.status === "ok") {
            alert(`Sesi berhasil diperpanjang ${extraHours} jam!`);
            loadUpcomingBooking();
            document.getElementById("profileDetail")?.click();
          } else {
            alert("Gagal: " + res.message);
            confirmBtn.disabled = false;
            confirmBtn.textContent = "Konfirmasi";
          }
        })
        .catch(() => {
          alert(NETWORK_ERROR);
          confirmBtn.disabled = false;
          confirmBtn.textContent = "Konfirmasi";
        });
      return;
    }

    // Tombol "Batalkan" booking
    const cancelBookingBtn = e.target.closest(".cancel-booking-btn");
    if (cancelBookingBtn) {
      const bid  = cancelBookingBtn.dataset.bid;
      const room = cancelBookingBtn.dataset.room;
      if (!confirm(`Batalkan booking ruangan "${room}"?`)) return;

      cancelBookingBtn.disabled = true;
      cancelBookingBtn.textContent = "Membatalkan...";

      fetch("cancel_booking.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ booking_id: bid }),
      })
        .then((r) => r.json())
        .then((res) => {
          if (res.status === "ok") {
            alert("Booking berhasil dibatalkan.");
            loadUpcomingBooking();
            document.getElementById("profileDetail")?.click();
          } else {
            alert("Gagal: " + res.message);
            cancelBookingBtn.disabled = false;
            cancelBookingBtn.textContent = "Batalkan";
          }
        })
        .catch(() => {
          alert(NETWORK_ERROR);
          cancelBookingBtn.disabled = false;
          cancelBookingBtn.textContent = "Batalkan";
        });
      return;
    }

    // Tombol "Pesan Lagi"
    const reorderBtn = e.target.closest(".reorder-btn");
    if (reorderBtn) {
      const roomId = reorderBtn.dataset.roomid;
      populateSelect();
      generateDateOptions();
      generateTimeOptions();
      updateDurationOptions();
      prefillBookingUser();
      const sel = $("roomSelect");
      if (sel) sel.value = roomId;
      fetchBookedSlots(roomId);
      updateBookingSummary();
      showView("booking");
      return;
    }
  });

  // Tab kategori menu
  document.addEventListener("click", (e) => {
    const btn = e.target.closest(".menu-cat-btn");
    if (!btn) return;
    activeMenuCat = btn.dataset.cat;
    renderMenuCatTabs();
    renderMenuGrid();
  });

  // Tombol Pesan pada menu card — buka order modal
  document.addEventListener("click", (e) => {
    const btn = e.target.closest(".menu-order-btn");
    if (!btn) return;
    $("orderItemId").value   = btn.dataset.id;
    $("orderItemName").textContent = btn.dataset.name;
    $("orderItemPrice").textContent = formatRup(btn.dataset.price) + " / item";
    $("orderQty").value  = 1;
    $("orderNote").value = "";
    document.getElementById("orderModal").classList.add("show");
  });

  // Tutup order modal
  document.getElementById("closeOrderModal")?.addEventListener("click", () => {
    document.getElementById("orderModal").classList.remove("show");
  });
  document.querySelector("#orderModal .modal-overlay")?.addEventListener("click", () => {
    document.getElementById("orderModal").classList.remove("show");
  });

  // Submit pesanan
  document.getElementById("orderSubmitBtn")?.addEventListener("click", (e) => {
    const itemId   = $("orderItemId").value;
    const quantity = Math.max(1, Math.min(20, parseInt($("orderQty").value) || 1));
    const note     = $("orderNote").value.trim();

    if (!IS_LOGGED_IN) {
      alert("Kamu harus login dulu untuk memesan.");
      return;
    }

    const btn = e.currentTarget;
    btn.disabled = true;

    const body = new URLSearchParams({ action: "order", item_id: itemId, quantity, note });
    fetch("menu_order_api.php", { method: "POST", body })
      .then((res) => res.json())
      .then((res) => {
        document.getElementById("orderModal").classList.remove("show");
        if (res.status === "ok") {
          alert("Pesanan berhasil! Silakan tunggu.");
          loadMyOrders();
        } else {
          alert("Gagal memesan: " + (res.message || "Error"));
        }
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { btn.disabled = false; });
  });

  // ===== LOGIN MODAL =====
  const loginLink = document.querySelector('[data-view="login"]');
  const loginModal = document.getElementById("loginModal");
  const closeLogin = document.getElementById("closeLogin");

  loginLink?.addEventListener("click", (e) => {
    e.preventDefault();
    window._pendingLogin = null;
    resetLoginForm();
    loginModal.classList.add("show");
  });

  closeLogin?.addEventListener("click", () => {
    loginModal.classList.remove("show");
  });

  // overlay LOGIN
  const loginOverlay = loginModal?.querySelector(".modal-overlay");

  loginOverlay?.addEventListener("click", (e) => {
    if (e.target === loginOverlay) {
      loginModal.classList.remove("show");
    }
  });

  // ===== REGISTER MODAL =====
  const daftarLink = document.querySelector(".daftar");
  const registerModal = document.getElementById("registerModal");
  const closeRegister = document.getElementById("closeRegister");

  daftarLink?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();

    resetRegisterForm();
    loginModal.classList.remove("show");
    registerModal.classList.add("show");
  });

  closeRegister?.addEventListener("click", () => {
    resetRegisterForm();
    registerModal.classList.remove("show");
  });

  document.getElementById("backToLogin")?.addEventListener("click", (e) => {
    e.preventDefault();
    resetRegisterForm();
    registerModal.classList.remove("show");
    resetLoginForm();
    loginModal.classList.add("show");
  });

  // ===== GANTI PASSWORD MODAL (verifikasi via password lama) =====
  const forgotModal    = document.getElementById("forgotModal");

  function openForgot() {
    document.getElementById("forgotUsername").value = "";
    document.getElementById("forgotOldPass").value = "";
    document.getElementById("forgotNewPass").value = "";
    document.getElementById("forgotConfirmPass").value = "";
    loginModal.classList.remove("show");
    forgotModal.classList.add("show");
  }

  function closeForgot() {
    forgotModal.classList.remove("show");
  }

  document.getElementById("openForgotPassword")?.addEventListener("click", (e) => {
    e.preventDefault();
    openForgot();
  });

  document.getElementById("closeForgot")?.addEventListener("click", closeForgot);
  forgotModal?.querySelector(".modal-overlay")?.addEventListener("click", closeForgot);

  document.getElementById("forgotResetBtn")?.addEventListener("click", (e) => {
    const username = document.getElementById("forgotUsername").value.trim();
    const oldPass  = document.getElementById("forgotOldPass").value;
    const newPass  = document.getElementById("forgotNewPass").value;
    const confirm  = document.getElementById("forgotConfirmPass").value;

    if (!username || !oldPass) { alert("Lengkapi username dan password lama."); return; }
    if (newPass.length < 6) { alert("Password baru minimal 6 karakter."); return; }
    if (newPass !== confirm) { alert("Konfirmasi password baru tidak cocok."); return; }

    const btn = e.currentTarget;
    btn.disabled = true;

    fetch("forgot_password.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `username=${encodeURIComponent(username)}&old_password=${encodeURIComponent(oldPass)}&new_password=${encodeURIComponent(newPass)}`
    })
      .then(r => r.json())
      .then(res => {
        if (res.status === "ok") {
          alert("Password berhasil diganti! Silakan login kembali.");
          closeForgot();
          loginModal.classList.add("show");
        } else {
          alert(res.message || "Gagal mengganti password.");
        }
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { btn.disabled = false; });
  });

  // overlay REGISTER
  const registerOverlay = registerModal?.querySelector(".modal-overlay");

  registerOverlay?.addEventListener("click", (e) => {
    if (e.target === registerOverlay) {
      resetRegisterForm();
      registerModal.classList.remove("show");
    }
  });

  // ===== SUBMIT LOGIN (MYSQL) =====
  document.getElementById("loginForm")?.addEventListener("submit", (e) => {
    e.preventDefault();

    const username = e.target.querySelector('input[type="text"]').value.trim();
    const password = e.target
      .querySelector('input[type="password"]')
      .value.trim();

    if (!username || !password) {
      alert("Username & password wajib diisi");
      return;
    }

    const submitBtn = e.target.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch("login.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ username, password }),
    })
      .then((res) => res.json())
      .then((res) => {
        if (res.status === "success") {
          onLoginSuccess(res.username);
        } else {
          alert(res.message);
        }
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { if (submitBtn) submitBtn.disabled = false; });
  });

  // ===== REGISTER =====
  const registerForm = document.getElementById("registerForm");

  if (registerForm) {
    registerForm.addEventListener(
      "submit",
      (e) => {
        e.preventDefault();

        const username = document.getElementById("regUsername").value.trim();
        const password = document.getElementById("regPassword").value.trim();
        const confirm = document.getElementById("regConfirm").value.trim();

        if (!username || !password || !confirm) {
          alert("Semua field wajib diisi");
          return;
        }

        if (password !== confirm) {
          alert("Password dan konfirmasi tidak sama");
          return;
        }

        const submitBtn = registerForm.querySelector('[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        fetch("register.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: new URLSearchParams({
            username,
            password,
          }),
        })
          .then((r) => r.json())
          .then((r) => {
            if (r.status === "success") {
              alert("Register berhasil, silakan login");
              resetRegisterForm();
              document.getElementById("registerModal").classList.remove("show");
              document.getElementById("loginModal").classList.add("show");
            } else {
              alert("Gagal: " + (r.message || "Terjadi kesalahan"));
            }
          })
          .catch(() => alert(NETWORK_ERROR))
          .finally(() => { if (submitBtn) submitBtn.disabled = false; });
      },
    );
  }

  /* ===== AUTH UI ===== */
  function setLoggedIn(username) {
    document.getElementById("authArea").style.display = "none";
    document.getElementById("profileMenu").style.display = "block";
    document.getElementById("profileName").textContent = username;
    const menuLink = document.getElementById("menuNavLink");
    if (menuLink) menuLink.style.display = "block";
    loadUpcomingBooking();
  }

  function setLoggedOut() {
    document.getElementById("authArea").style.display = "block";
    document.getElementById("profileMenu").style.display = "none";
    const menuLink = document.getElementById("menuNavLink");
    if (menuLink) menuLink.style.display = "none";

    window.AUTH = null;
    window._notifiedSessions = new Set();
    stopMenuRefresh();
    queueWaiting = false;
    renderQueueEntry(null);
    if (window._bookingCountdownInterval) {
      clearInterval(window._bookingCountdownInterval);
      window._bookingCountdownInterval = null;
    }
    const banner = document.getElementById("upcomingBanner");
    if (banner) banner.style.display = "none";

    const accountBadge = document.getElementById("bookingAccountName");
    if (accountBadge) accountBadge.textContent = "";
    const welcome = document.getElementById("bookingWelcome");
    if (welcome) welcome.textContent = "";

    const bookingForm = document.getElementById("bookingForm");
    if (bookingForm) bookingForm.reset();
    fetchBookedSlots("");

    resetProfileData();
  }

  /* LOGIN SUCCESS HANDLER */
  function resetProfileData() {
    const totalHours = document.getElementById("profileTotalHours");
    if (totalHours) totalHours.textContent = "0 jam";
    const profileUsername = document.getElementById("profileUsername");
    if (profileUsername) profileUsername.textContent = "-";
    const profileBookingList = document.getElementById("profileBookingList");
    if (profileBookingList) profileBookingList.innerHTML = '<p class="muted small">Belum ada booking.</p>';
    const myOrdersList = document.getElementById("myOrdersList");
    if (myOrdersList) myOrdersList.innerHTML = "";
  }

  function onLoginSuccess(username) {
    IS_LOGGED_IN = true;
    window.AUTH = { loggedIn: true, username: username };

    resetProfileData();
    resetLoginForm();

    document.getElementById("loginModal").classList.remove("show");
    setLoggedIn(username);

    // Kembali ke halaman yang tadi meminta login (mis. booking), bukan ke Beranda
    const pending = window._pendingLogin;
    window._pendingLogin = null;
    const link = pending && document.querySelector(`.nav a[data-view="${pending.view}"]`);
    if (link) {
      link.click();
      if (pending.roomId) {
        $("roomSelect").value = pending.roomId;
        fetchBookedSlots(pending.roomId);
        updateBookingSummary();
      }
      return;
    }
    showView("home");
  }

  /* LOGOUT */
  function doLogout() {
    if (!confirm("Yakin mau logout?")) return;
    IS_LOGGED_IN = false;
    setLoggedOut();
    resetLoginForm();
    showView("home");
    fetch("logout.php").catch(() => {});
  }

  document.getElementById("logoutBtn")?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    doLogout();
  });

  // RESET LOGIN
  function resetLoginForm() {
    const form = document.getElementById("loginForm");
    if (!form) return;

    form.reset();

    // pastikan benar-benar kosong (anti browser cache)
    const inputs = form.querySelectorAll("input");
    inputs.forEach((inp) => {
      inp.value = "";
      inp.blur();
    });
  }

  // RESET REGISTER
  function resetRegisterForm() {
    const form = document.getElementById("registerForm");
    if (!form) return;

    form.reset();

    const inputs = form.querySelectorAll("input");
    inputs.forEach((inp) => {
      inp.value = "";
      inp.blur();
    });
  }

  /* ===== PROFILE DROPDOWN TOGGLE ===== */
  const profileToggle = document.getElementById("profileToggle");
  const profileDropdown = document.getElementById("profileDropdown");

  profileToggle?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();

    const isOpen = profileDropdown.style.display === "flex";
    profileDropdown.style.display = isOpen ? "none" : "flex";
  });

  /* klik di luar → dropdown nutup */
  document.addEventListener("click", () => {
    if (profileDropdown) {
      profileDropdown.style.display = "none";
    }
  });

  // DROPDOWN RESET
  function closeProfileDropdown() {
    const dropdown = document.getElementById("profileDropdown");
    if (dropdown) {
      dropdown.style.display = "none";
    }
  }

  // logout dari halaman profile
  document.getElementById("profileLogoutBtn")?.addEventListener("click", (e) => {
    e.stopPropagation();
    doLogout();
  });

  // ===== PROFILE DETAIL (FINAL – SINGLE SOURCE OF TRUTH) =====
  // Dipakai baik oleh link "Detail" di dropdown profil maupun tombol
  // "Lihat Detail" di banner booking mendatang (halaman Beranda), supaya
  // keduanya selalu menampilkan data booking yang sama & ter-update.
  function openProfileDetail() {
    stopMenuRefresh();
    loadUserBookings();

    // 1️⃣ tampilkan view
    showView("profile");

    // RIWAYAT BOOKING PADA DETAIL PROFILE
    function loadUserBookings() {
        const box = document.getElementById("profileBookingList");
        if (!box) return;

        if (window._bookingCountdownInterval) {
          clearInterval(window._bookingCountdownInterval);
          window._bookingCountdownInterval = null;
        }

        box.innerHTML = '<p class="muted small">Memuat booking...</p>';

        fetch("get_user_bookings.php?t=" + Date.now())
          .then((r) => r.json())
          .then((data) => {
            if (!IS_LOGGED_IN) return;
            if (!data || data.length === 0) {
              box.innerHTML = '<p class="muted small">Belum ada booking.</p>';
              return;
            }

            box.innerHTML = "";
            const now = Math.floor(Date.now() / 1000);

            const totalHours = data
              .filter(b => parseInt(b.end_time) <= now && b.payment_status !== "cancelled")
              .reduce((sum, b) => sum + parseInt(b.duration), 0);
            const totalEl = document.getElementById("profileTotalHours");
            if (totalEl) totalEl.textContent = totalHours + " jam";

            data.forEach((b) => {
              const start = parseInt(b.start_time);
              const end   = parseInt(b.end_time);
              // Batas lapor ke kasir sudah lewat tapi server belum sempat membatalkan
              const missedCheckin = b.payment_status === "unpaid" && b.expires_at && now > b.expires_at;
              const isCancelled = b.payment_status === "cancelled" || missedCheckin;
              const isActive   = !isCancelled && now >= start && now < end;
              const isUpcoming = !isCancelled && now < start;
              const isDone     = !isCancelled && now >= end;

              // expires_at terisi = belum lapor ke kasir (belum check-in)
              const notCheckedIn = !isCancelled && b.expires_at && (isUpcoming || isActive);
              const fmtTime = (u) => new Date(u * 1000).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });

              let statusHtml;
              if (isCancelled) {
                statusHtml = `<span class="booking-status cancelled">${b.expires_at ? "Batal · tidak hadir" : "Dibatalkan"}</span>`;
              } else if (isActive && notCheckedIn) {
                statusHtml = `<span class="booking-status upcoming">Menunggu kedatanganmu</span>`;
              } else if (isUpcoming) {
                statusHtml = `<span class="booking-status upcoming">Menunggu mulai</span>`;
              } else if (isDone) {
                statusHtml = `<span class="booking-status done">Selesai</span>`;
              } else {
                statusHtml = `<span class="booking-status active" id="status-${b.id}">Sisa: <span id="countdown-${b.id}">--:--:--</span></span>`;
              }

              const payHtml = isCancelled ? ""
                : b.payment_status === "paid"
                  ? `<span class="pay-chip paid">Lunas${b.payment_method === "midtrans" ? " · online" : ""}</span>`
                  : b.payment_status === "pending"
                    ? `<span class="pay-chip pending">Menunggu pembayaran online</span>`
                    : `<span class="pay-chip unpaid">Belum bayar · bayar di kasir</span>`;
              // Bayar online untuk reservasi yang belum lunas (bukan giliran antrian)
              const payBtnHtml = paymentConfig.enabled && !b.from_queue && (isUpcoming || isActive)
                && ["unpaid", "pending"].includes(b.payment_status)
                ? `<button class="btn pay-btn" data-pay-booking="${escapeHtml(b.id)}">
                     ${b.payment_status === "pending" ? "Lanjutkan Pembayaran" : "Bayar Online"}
                   </button>`
                : "";
              const metaHtml = `<div class="booking-meta">
                  ${b.order_code ? `<span class="code-chip">${escapeHtml(b.order_code)}</span>` : ""}${payHtml}
                </div>`;
              const checkinHtml = notCheckedIn
                ? `<p class="checkin-note">Lapor ke kasir sebelum ${fmtTime(b.expires_at)}, lewat dari itu booking batal otomatis.</p>`
                : "";

              const cancelHtml = (isUpcoming || notCheckedIn) && b.payment_status !== "paid" ? `
                <div style="margin-top:8px">
                  <button class="btn-ghost cancel-booking-btn" data-bid="${b.id}" data-room="${escapeHtml(b.room)}"
                    style="font-size:0.78rem;padding:4px 10px;color:#ff6b6b;border-color:#ff6b6b44">
                    Batalkan
                  </button>
                </div>` : '';

              const reorderHtml = isDone ? `
                <div style="margin-top:8px">
                  <button class="btn-ghost reorder-btn" data-roomid="${b.room_id}"
                    style="font-size:0.78rem;padding:4px 10px">
                    Pesan Lagi
                  </button>
                </div>` : '';

              const extendHtml = isActive && !notCheckedIn ? `
                <div style="margin-top:8px">
                  <button class="btn-ghost extend-btn"
                    data-bid="${b.id}" data-roomid="${b.room_id}"
                    data-end="${b.end_time}" data-price="${b.price}"
                    data-duration="${b.duration}" data-start="${b.start_time}"
                    style="font-size:0.78rem;padding:4px 10px">+ Perpanjang</button>
                  <div id="extend-form-${b.id}" style="display:none;margin-top:8px">
                    <p id="extend-noavail-${b.id}" style="display:none;color:#ff6b6b;font-size:0.8rem;margin-bottom:6px">
                      Tidak ada jam tersedia untuk diperpanjang.
                    </p>
                    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px">
                      <select id="extend-hours-${b.id}" data-price="${b.price}"
                        style="padding:4px 8px;background:#1a2233;color:#fff;border:1px solid #00eaff44;border-radius:6px">
                        <option value="1">+1 jam</option>
                        <option value="2">+2 jam</option>
                        <option value="3">+3 jam</option>
                      </select>
                      <span id="extend-cost-${b.id}" style="color:#00eaff;font-size:0.82rem;font-weight:600">
                        +${formatRup(b.price)}
                      </span>
                      <button class="btn" data-extend="${b.id}" style="font-size:0.78rem;padding:4px 12px">Konfirmasi</button>
                      <button class="btn-ghost" data-extend-cancel="${b.id}" style="font-size:0.78rem;padding:4px 10px">Batal</button>
                    </div>
                  </div>
                </div>` : '';

              box.innerHTML += `
                <div class="booking-item">
                  <strong>${escapeHtml(b.room)}</strong><br>
                  <span class="small muted">${new Date(start * 1000).toLocaleDateString("id-ID", { day: "numeric", month: "short" })} &bull; Jam: ${escapeHtml(b.time)} &bull; Durasi: ${escapeHtml(String(b.duration))} jam &bull; ${formatRup(b.total_price)}</span><br>
                  ${statusHtml}
                  ${metaHtml}
                  ${checkinHtml}
                  ${payBtnHtml}
                  ${extendHtml}
                  ${cancelHtml}
                  ${reorderHtml}
                </div>
              `;
            });

            // Booking yang menunggu pembayaran online: cek status terbaru ke Midtrans,
            // muat ulang daftar sekali bila ada yang berubah (lunas/kedaluwarsa)
            const pendings = data.filter((b) => b.payment_status === "pending");
            if (pendings.length && !window._paySyncing) {
              window._paySyncing = true;
              Promise.all(pendings.map((b) => checkPaymentStatus(b.id))).then((sts) => {
                window._paySyncing = false;
                if (sts.some((s) => s && s !== "pending")) loadUserBookings();
              });
            }

            if (!window._notifiedSessions) window._notifiedSessions = new Set();

            function tick() {
              const nowTick = Math.floor(Date.now() / 1000);
              let hasActive = false;
              data.forEach((b) => {
                const el = document.getElementById(`countdown-${b.id}`);
                if (!el) return;
                const remaining = parseInt(b.end_time) - nowTick;
                if (remaining > 0) {
                  hasActive = true;
                  const h = Math.floor(remaining / 3600).toString().padStart(2, '0');
                  const m = Math.floor((remaining % 3600) / 60).toString().padStart(2, '0');
                  const s = (remaining % 60).toString().padStart(2, '0');
                  el.textContent = `${h}:${m}:${s}`;

                  if (remaining <= 600 && !window._notifiedSessions.has(b.id)) {
                    window._notifiedSessions.add(b.id);
                    showSessionWarning(b.room, Math.ceil(remaining / 60));
                  }
                } else {
                  const statusEl = document.getElementById(`status-${b.id}`);
                  if (statusEl) {
                    statusEl.className = 'booking-status done';
                    statusEl.textContent = 'Selesai';
                  }
                }
              });
              if (!hasActive) {
                clearInterval(window._bookingCountdownInterval);
                window._bookingCountdownInterval = null;
              }
            }

            // Request lama yang selesai belakangan bisa sudah memasang interval
            if (window._bookingCountdownInterval) clearInterval(window._bookingCountdownInterval);
            tick();
            window._bookingCountdownInterval = setInterval(tick, 1000);
          })
          .catch(() => {
            box.innerHTML = '<p class="muted small">Gagal memuat riwayat booking.</p>';
          });
      }

      // 2️⃣ pastikan isi muncul
      const uname = document.getElementById("profileUsername");
      const statusEl = document.querySelector("#view-profile .status-online");

      fetch("profile_status.php")
        .then((r) => r.json())
        .then((d) => {
          if (d.error) return;
          if (uname) uname.textContent = d.username || "User";

          if (statusEl) {
            statusEl.textContent = d.status;
            statusEl.style.color =
              d.status === "Online" ? "#00ff9d" : "#ff5555";
          }
        })
        .catch(() => {
          if (uname) uname.textContent = "User";
          if (statusEl) statusEl.textContent = "Offline";
        });
  }

  const profileDetailLink = document.getElementById("profileDetail");
  if (profileDetailLink) {
    profileDetailLink.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation(); // STOP SEMUA EVENT GLOBAL
      closeProfileDropdown();
      openProfileDetail();
    });
  }

  showView("home");
});

//JAM MULAI (11.00–23.00)
const timeSelect = document.getElementById("timeStart");
const durationSelect = document.getElementById("duration");

// Format tanggal lokal (bukan UTC) supaya "hari ini" tidak salah tanggal
// untuk pengguna WIB antara jam 00:00-06:59 (toISOString() memakai UTC).
function toLocalISODate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function getTodayISO() {
  return toLocalISODate(new Date());
}

function generateDateOptions() {
  const sel = document.getElementById("bookingDate");
  if (!sel) return;
  sel.innerHTML = "";
  const labels = ["Hari ini", "Besok", "Lusa"];
  for (let i = 0; i < 3; i++) {
    const d = new Date();
    d.setDate(d.getDate() + i);
    const iso = toLocalISODate(d);
    const opt = document.createElement("option");
    opt.value = iso;
    opt.textContent = `${labels[i]} · ${d.toLocaleDateString("id-ID", { day: "numeric", month: "short" })}`;
    sel.appendChild(opt);
  }
}

function generateTimeOptions() {
  const dateSel = document.getElementById("bookingDate");
  const selectedDate = dateSel?.value || getTodayISO();
  const isToday = selectedDate === getTodayISO();
  const nowHour = new Date().getHours();

  timeSelect.innerHTML = "";
  let firstAvailable = null;
  for (let hour = 11; hour <= 23; hour++) {
    if (isToday && hour <= nowHour) continue;
    const h = hour.toString().padStart(2, "0");
    const opt = document.createElement("option");
    opt.value = `${h}:00`;
    opt.textContent = `${h}:00`;
    timeSelect.appendChild(opt);
    if (!firstAvailable) firstAvailable = `${h}:00`;
  }

  if (firstAvailable) {
    timeSelect.value = firstAvailable;
    timeSelect.disabled = false;
  } else {
    // Sudah lewat jam operasional untuk tanggal ini (mis. sudah jam 23 lewat hari ini)
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "Tidak ada jam tersisa hari ini";
    timeSelect.appendChild(opt);
    timeSelect.disabled = true;
  }
}

function updateDurationOptions() {
  durationSelect.innerHTML = "";

  if (!timeSelect.value) {
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "Pilih tanggal lain";
    durationSelect.appendChild(opt);
    durationSelect.disabled = true;
    return;
  }
  durationSelect.disabled = false;

  const [hour] = timeSelect.value.split(":").map(Number);
  const maxDuration = 12; // sesuai batas maksimal di server (apikr.php)
  const remaining = Math.min(24 - hour, maxDuration);
  const start = slotStartTs(timeSelect.value);

  for (let i = 1; i <= remaining; i++) {
    // Durasi yang menabrak booking berikutnya tidak ditawarkan
    if (isRangeBlocked(start, start + i * 3600)) break;
    const opt = document.createElement("option");
    opt.value = i;
    opt.textContent = `${i} jam`;
    durationSelect.appendChild(opt);
  }

  if (durationSelect.options.length === 0) {
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "Jam ini sudah terpesan";
    durationSelect.appendChild(opt);
    durationSelect.disabled = true;
    return;
  }

  durationSelect.value = durationSelect.options[0].value;
}

let bookedSlots = [];

generateDateOptions();
generateTimeOptions();
updateDurationOptions();
timeSelect.addEventListener("change", updateDurationOptions);

document.getElementById("bookingDate")?.addEventListener("change", () => {
  generateTimeOptions();
  updateDurationOptions();
  const roomId = document.getElementById("roomSelect")?.value;
  if (roomId) fetchBookedSlots(roomId);
  // Ringkasan diperbarui oleh listener "change" di dalam DOMContentLoaded
});

// ===== BOOKED SLOTS =====

// Nomor request terakhir: respons lama (ganti ruangan/tanggal cepat) diabaikan
let bookedSlotsReq = 0;

function fetchBookedSlots(roomId) {
  const infoEl = document.getElementById("bookedSlotsInfo");
  const date = document.getElementById("bookingDate")?.value || getTodayISO();
  const reqId = ++bookedSlotsReq;
  if (!roomId) {
    bookedSlots = [];
    if (infoEl) infoEl.textContent = "";
    applyBookedSlots();
    return;
  }
  fetch(`booked_slots_api.php?room_id=${encodeURIComponent(roomId)}&date=${date}`)
    .then((r) => r.json())
    .then((data) => {
      if (reqId !== bookedSlotsReq) return;
      bookedSlots = data;
      applyBookedSlots();
      if (infoEl) {
        if (data.length === 0) {
          infoEl.textContent = "";
        } else {
          const ranges = data.map((s) => {
            const fmt = (unix) =>
              new Date(unix * 1000).toLocaleTimeString("id-ID", {
                hour: "2-digit",
                minute: "2-digit",
              });
            return `${fmt(s.start_time)}–${fmt(s.end_time)}`;
          });
          infoEl.textContent = "Jam terpesan: " + ranges.join(", ");
        }
      }
    })
    .catch(() => {
      if (reqId !== bookedSlotsReq) return;
      bookedSlots = [];
      applyBookedSlots();
    });
}

// Timestamp (detik) untuk jam "HH:MM" pada tanggal booking yang dipilih
function slotStartTs(timeVal) {
  const date = document.getElementById("bookingDate")?.value || getTodayISO();
  return Math.floor(new Date(`${date}T${timeVal}:00`).getTime() / 1000);
}

// Sama dengan cek bentrok di apikr.php: start < endLain && end > startLain
function isRangeBlocked(start, end) {
  return bookedSlots.some((s) => start < s.end_time && end > s.start_time);
}

function applyBookedSlots() {
  if (!timeSelect) return;
  const currentVal = timeSelect.value;
  Array.from(timeSelect.options).forEach((opt) => {
    if (!opt.value) return;
    const start = slotStartTs(opt.value);
    if (isRangeBlocked(start, start + 3600)) {
      opt.disabled = true;
      opt.textContent = `${opt.value} — Terpesan`;
    } else {
      opt.disabled = false;
      opt.textContent = opt.value;
    }
  });
  const currentOpt = Array.from(timeSelect.options).find((o) => o.value === currentVal);
  if (!currentOpt || currentOpt.disabled) {
    const first = Array.from(timeSelect.options).find((o) => !o.disabled);
    if (first) timeSelect.value = first.value;
  } else {
    timeSelect.value = currentVal;
  }
  if (timeSelect.value !== currentVal) {
    // Jam berubah: picu "change" supaya durasi dan ringkasan booking ikut diperbarui
    timeSelect.dispatchEvent(new Event("change", { bubbles: true }));
  } else {
    // Jam sama: hitung ulang opsi durasi, pertahankan pilihan user bila masih valid
    const prevDuration = durationSelect.value;
    updateDurationOptions();
    if (Array.from(durationSelect.options).some((o) => o.value === prevDuration && !o.disabled)) {
      durationSelect.value = prevDuration;
    }
    durationSelect.dispatchEvent(new Event("change", { bubbles: true }));
  }
}

document.getElementById("roomSelect")?.addEventListener("change", (e) => {
  fetchBookedSlots(e.target.value);
});

function showView(v) {
  Object.values(views).forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.style.display = "none";
  });

  const target = document.getElementById(views[v]);
  if (target) target.style.display = "block";

  if (v === "booking") refreshQueue();
  setActiveNav(v);

  // reset scroll & state
  window.scrollTo({ top: 0, behavior: "smooth" });
}

function setActiveNav(view) {
  document.querySelectorAll(".nav a").forEach((a) => {
    a.classList.toggle("active", a.dataset.view === view);
  });
}

// ===== ANTRIAN FIFO (MAIN SEKARANG) =====
let queueWaiting = false;

function renderQueueEntry(entry) {
  const joinBox = $("queueJoinBox");
  const statusBox = $("queueStatusBox");
  const text = $("queueStatusText");
  const cancelBtn = $("queueCancelBtn");
  if (!joinBox) return;

  const wasWaiting = queueWaiting;
  queueWaiting = !!entry && entry.state === "waiting";

  if (!entry) {
    joinBox.style.display = "";
    // Tadinya masih menunggu lalu hilang tanpa dibatalkan sendiri: beri tahu user
    if (wasWaiting) {
      statusBox.style.display = "";
      cancelBtn.style.display = "none";
      text.innerHTML = `${icon('alert')} Antrianmu sudah tidak aktif (dibatalkan kasir atau melewati jam tutup). Silakan ambil antrian lagi bila perlu.`;
    } else {
      statusBox.style.display = "none";
    }
    return;
  }
  const fmt = (unix) => new Date(unix * 1000).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
  const ended = entry.state === "expired" || entry.state === "cancelled";
  // Kalau giliran sudah batal, user boleh ambil antrian lagi
  joinBox.style.display = ended ? "" : "none";
  statusBox.style.display = "";
  // Giliran yang sudah didapat tapi belum lapor ke kasir juga boleh dibatalkan
  const canCancelTurn = entry.state === "assigned" && !!entry.checkin_until && entry.payment_status !== "paid";
  cancelBtn.style.display = entry.state === "waiting" || canCancelTurn ? "" : "none";
  cancelBtn.textContent = canCancelTurn ? "Batalkan Giliran" : "Batalkan Antrian";

  if (entry.state === "waiting") {
    text.innerHTML = `Antrian <strong>${escapeHtml(entry.console_type)}</strong> · ${Number(entry.duration)} jam — posisi kamu: <strong>#${Number(entry.position)}</strong>`;
  } else if (entry.state === "expired") {
    text.innerHTML = `${icon('alert')} Giliranmu di <strong>${escapeHtml(entry.room)}</strong> dibatalkan karena tidak datang dalam 15 menit.`;
  } else if (entry.state === "cancelled") {
    text.innerHTML = `${icon('alert')} Booking dari antrian di <strong>${escapeHtml(entry.room)}</strong> dibatalkan admin.`;
  } else {
    const checkin = entry.checkin_until
      ? ` Datang dan lapor ke kasir sebelum <strong>${fmt(entry.checkin_until)}</strong>, lewat dari itu giliran batal otomatis.`
      : "";
    text.innerHTML = `${icon('check')} Giliranmu! Silakan ke <strong>${escapeHtml(entry.room)}</strong> — sesi s/d ${fmt(entry.end_time)}.${checkin}`;
    if (wasWaiting) {
      alert(`Giliranmu! Silakan ke ${entry.room} dalam 15 menit.`);
      window.loadUpcomingBooking?.();
    }
  }
}

function refreshQueue() {
  if (!IS_LOGGED_IN) {
    queueWaiting = false;
    renderQueueEntry(null);
    return;
  }
  fetch("queue_api.php?action=status&t=" + Date.now())
    .then((r) => r.json())
    .then((res) => { if (res.status === "ok") renderQueueEntry(res.entry); })
    .catch(() => {});
}

function postQueue(params) {
  return fetch("queue_api.php", { method: "POST", body: new URLSearchParams(params) })
    .then((r) => r.json());
}

(function initQueueForm() {
  const durSel = $("queueDuration");
  if (durSel) {
    for (let i = 1; i <= 12; i++) durSel.add(new Option(`${i} jam`, i));
  }

  $("queueJoinBtn")?.addEventListener("click", () => {
    if (!IS_LOGGED_IN) {
      requireLogin("Silakan login terlebih dahulu untuk mengambil antrian.", "booking");
      return;
    }
    const btn = $("queueJoinBtn");
    btn.disabled = true;
    postQueue({ action: "join", console_type: $("queueConsole").value, duration: durSel.value })
      .then((res) => {
        if (res.status !== "ok") { alert(res.message || "Gagal mengambil antrian"); return; }
        renderQueueEntry(res.entry);
        if (res.entry?.state === "assigned") alert(`Ruangan tersedia! Silakan ke ${res.entry.room}.`);
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { btn.disabled = false; });
  });

  $("queueCancelBtn")?.addEventListener("click", () => {
    if (!confirm(`${$("queueCancelBtn").textContent}? Posisi/ruanganmu akan diberikan ke orang berikutnya.`)) return;
    const btn = $("queueCancelBtn");
    btn.disabled = true;
    postQueue({ action: "cancel" })
      .then((res) => {
        if (res.status !== "ok") alert(res.message || "Gagal membatalkan antrian");
        else queueWaiting = false; // dibatalkan sendiri, jangan tampilkan pesan "dibatalkan kasir"
        refreshQueue();
        window.loadUpcomingBooking?.();
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { btn.disabled = false; });
  });

  // Polling: saat di halaman booking, atau selama masih menunggu giliran
  setInterval(() => {
    const onBooking = $("view-booking")?.style.display === "block";
    if (IS_LOGGED_IN && (onBooking || queueWaiting)) refreshQueue();
  }, 15000);

  document.addEventListener("DOMContentLoaded", refreshQueue);
})();

