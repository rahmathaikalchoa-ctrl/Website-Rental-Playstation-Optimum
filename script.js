// view handler
const $ = (id) => document.getElementById(id);

const NETWORK_ERROR = "Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.";

// ===== SESI LOGIN HABIS =====
// endpoint kirim header X-Auth-Required kalo sesi server habis tapi tampilan masih login
// semua fetch dicek di sini biar UI gak diem-diem kosong
const nativeFetch = window.fetch.bind(window);
window.fetch = (...args) => nativeFetch(...args).then((res) => {
  if (res.headers.get("X-Auth-Required") && IS_LOGGED_IN) {
    IS_LOGGED_IN = false;
    window.onSessionExpired?.();
  }
  return res;
});

// ===== JAM WIB =====
// jam & tanggal selalu WIB (zona server), bukan jam device
// selisih jam device dikoreksi dari jam server (session.php)
const TZ = "Asia/Jakarta";
const WIB_OFFSET = 7 * 3600;
let serverSkew = 0;
function nowSec() {
  return Math.floor(Date.now() / 1000) + serverSkew;
}
function syncServerClock(serverTime) {
  if (Number.isFinite(serverTime)) serverSkew = serverTime - Math.floor(Date.now() / 1000);
}
// tanggal WIB dari timestamp
function wibParts(ts = nowSec()) {
  const d = new Date((ts + WIB_OFFSET) * 1000);
  return { y: d.getUTCFullYear(), m: d.getUTCMonth() + 1, d: d.getUTCDate() };
}
// timestamp 00:00 WIB di hari yg sama
function wibDayStart(ts = nowSec()) {
  return Math.floor((ts + WIB_OFFSET) / 86400) * 86400 - WIB_OFFSET;
}
function wibISODate(ts = nowSec()) {
  const p = wibParts(ts);
  return `${p.y}-${String(p.m).padStart(2, "0")}-${String(p.d).padStart(2, "0")}`;
}
// timestamp dari YYYY-MM-DD + HH:MM WIB
function wibTs(isoDate, hhmm = "00:00") {
  const [y, m, d] = isoDate.split("-").map(Number);
  const [h, mi] = hhmm.split(":").map(Number);
  return Date.UTC(y, m - 1, d, h, mi) / 1000 - WIB_OFFSET;
}

// jam operasional dari <body>: 10:00-22:00, slot 30 menit, gak boleh lewat tutup
const OPEN_SEC  = Number(document.body.dataset.openSec ?? 36000); // 0 = buka 00:00, jangan dianggap kosong
const CLOSE_SEC = Number(document.body.dataset.closeSec ?? 79200);
const SLOT_SEC  = 1800;
// HH:MM dari detik
function secToHHMM(sec) {
  return `${String(Math.floor(sec / 3600)).padStart(2, "0")}:${String(Math.floor(sec % 3600 / 60)).padStart(2, "0")}`;
}

// ===== HARGA =====
// paket dari booking_lib.php (atribut <body>), rumus sama kayak bookingPrice() server, total akhir tetap dari server
const PRICING = (() => {
  try { return JSON.parse(document.body.dataset.pricing || "{}"); } catch { return {}; }
})();
// sabtu/minggu (WIB)
function isWeekendTs(ts) {
  const day = new Date((ts + WIB_OFFSET) * 1000).getUTCDay();
  return day === 0 || day === 6;
}
function roomHourlyRate(room, withNintendo) {
  return room.price + (withNintendo && room.nintendoExtra ? room.nintendoExtra : 0);
}
// paket 1-5 jam (kalo ada) + weekend per jam
function bookingPrice(room, hours, startTs, withNintendo = false) {
  const rate = roomHourlyRate(room, withNintendo);
  const pkg = PRICING.packages?.[rate];
  let total = pkg ? (hours <= 5 ? pkg[hours - 1] : pkg[4] + (hours - 5) * rate) : rate * hours;
  if (isWeekendTs(startTs)) total += room.weekendExtra * hours;
  return total;
}

// ===== PENGINGAT BOOKING =====
// tombol simpan ke kalender (.ics, alarm 1 jam sebelum), booking udah sah tanpa konfirmasi WA
// b: { order_code, room, start_time, end_time }
function reminderButtons(b) {
  return `<div class="reminder-actions">
      <button type="button" class="btn-ghost reminder-btn" data-ics="${escapeHtml(JSON.stringify({ code: b.order_code, room: b.room, start: Number(b.start_time), end: Number(b.end_time) }))}">${icon("clock")} Simpan ke Kalender</button>
    </div>`;
}

// file .ics dibuat di browser
document.addEventListener("click", (e) => {
  const btn = e.target.closest("[data-ics]");
  if (!btn) return;
  const d = JSON.parse(btn.dataset.ics);
  const stamp = (u) => new Date(u * 1000).toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");
  const esc = (s) => String(s).replace(/[\\;,]/g, (c) => "\\" + c);
  const ics = [
    "BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Optimum Playzone//Booking//ID", "BEGIN:VEVENT",
    `UID:${d.code}@optimum-playzone`, `DTSTAMP:${stamp(Math.floor(Date.now() / 1000))}`,
    `DTSTART:${stamp(d.start)}`, `DTEND:${stamp(d.end)}`,
    `SUMMARY:${esc(`Main di Optimum Playzone (${d.room})`)}`,
    `DESCRIPTION:${esc(`Kode booking ${d.code}. Lapor ke kasir paling lambat 15 menit setelah jam mulai.`)}`,
    "BEGIN:VALARM", "TRIGGER:-PT60M", "ACTION:DISPLAY", "DESCRIPTION:Booking Optimum Playzone 1 jam lagi", "END:VALARM",
    "END:VEVENT", "END:VCALENDAR",
  ].join("\r\n");
  const url = URL.createObjectURL(new Blob([ics], { type: "text/calendar" }));
  const a = document.createElement("a");
  a.href = url;
  a.download = `booking-${d.code}.ics`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
});

// isi otomatis form booking (dari cari ruangan / rundown), jam & durasi dipasang setelah slot terpesan dimuat
let pendingBookingPrefill = null;
function prefillBooking(p) {
  showView("booking");
  const dateSel = $("bookingDate");
  if ([...dateSel.options].some((o) => o.value === p.date)) dateSel.value = p.date;
  pendingBookingPrefill = p;
  $("roomSelect").value = String(p.roomId);
  dateSel.dispatchEvent(new Event("change", { bubbles: true }));
  $("roomSelect").dispatchEvent(new Event("change", { bubbles: true }));
}
function applyPendingPrefill() {
  const p = pendingBookingPrefill;
  if (!p || $("roomSelect").value !== String(p.roomId)) return;
  pendingBookingPrefill = null;
  const t = [...timeSelect.options].find((o) => o.value === p.time && !o.disabled);
  if (!t) return;
  timeSelect.value = p.time;
  timeSelect.dispatchEvent(new Event("change", { bubbles: true }));
  if ([...durationSelect.options].some((o) => o.value === String(p.duration))) durationSelect.value = String(p.duration);
  const n = $("withNintendo");
  if (n) n.checked = !!p.nintendo;
  durationSelect.dispatchEvent(new Event("change", { bubbles: true }));
  $("name")?.focus();
}

// minta login, abis login balik ke halaman & ruangan tujuan (onLoginSuccess)
function requireLogin(message, view, roomId = null) {
  window._pendingLogin = { view, roomId };
  alert(message);
  document.getElementById("loginModal").classList.add("show");
}

// escape sebelum masuk innerHTML biar gak XSS
function escapeHtml(str) {
  return String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  }[c]));
}

// ikon SVG (sama kayak icons.php)
const ICON_PATHS = {
  check: '<path d="M20 6 9 17l-5-5"/>',
  clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
  utensils: '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
};
function icon(name) {
  return `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">${ICON_PATHS[name] || ""}</svg>`;
}

const views = {
  home: "view-home",
  consoles: "view-consoles",
  "room-detail": "view-room-detail",
  booking: "view-booking",
  promo: "view-promo",
  fasilitas: "view-fasilitas",
  game: "view-game",
  menu: "view-menu",
  profile: "view-profile",
};

// ===== AUTH STATE =====
let IS_LOGGED_IN = false;

// browser bisa restore isi form dari bfcache tanpa DOMContentLoaded
// jadi dipanggil di DOMContentLoaded & pageshow
function clearNoFillFields() {
  const noFillIds = ["name", "email", "phone", "regUsername",
                     "forgotUsername", "orderNote"];
  noFillIds.forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    // jangan hapus yg udah diketik user
    if (el.dataset.userTyped) return;
    // nama acak tiap load biar browser gak nyocokin ke history
    el.setAttribute("name", "nofill_" + Math.random().toString(36).substr(2, 8));
    el.setAttribute("autocomplete", "new-password");
    // browser suka ingat isi form pas F5, kosongin paksa tiap load
    el.value = "";
  });
  // nama pemesan tetap diisi dari akun
  const nameInput = document.getElementById("name");
  if (nameInput && IS_LOGGED_IN && window.AUTH?.username) nameInput.value = window.AUTH.username;
}

window.addEventListener("pageshow", (e) => {
  clearNoFillFields();
  // balik dari bfcache (Back): status login bisa basi, reload kalo beda sama server
  if (e.persisted) {
    fetch("session.php", { cache: "no-store" })
      .then((res) => res.json())
      .then((sess) => {
        if (!!sess.logged_in !== IS_LOGGED_IN) location.reload();
      })
      .catch(() => {});
  }
});
// chrome kadang restore value telat abis load, jadi dikosongin lagi bentar kemudian
// input yg diketik user ditandai biar gak ikut kehapus
document.addEventListener("input", (e) => {
  if (e.isTrusted && e.target.dataset) e.target.dataset.userTyped = "1";
});
window.addEventListener("load", () => {
  setTimeout(clearNoFillFields, 0);
  setTimeout(clearNoFillFields, 300);
});

document.addEventListener("DOMContentLoaded", () => {
  /* ===== BLOKIR AUTOFILL CHROME ===== */
  clearNoFillFields();

  /* ===== CEK SESI PAS LOAD ===== */
  // pakai status dari PHP dulu biar klik sebelum fetch selesai gak ditolak
  IS_LOGGED_IN = !!window.AUTH?.loggedIn;
  fetch("session.php")
    .then((res) => res.json())
    .then((sess) => {
      // jam device bisa salah, samain sama server terus update pilihan tanggal/jam
      syncServerClock(sess.server_time);
      refreshStaleDateOptions();
      if (sess.logged_in) {
        IS_LOGGED_IN = true;
        setLoggedIn(sess.username);
      } else {
        IS_LOGGED_IN = false;
        setLoggedOut();
      }
    })
    .catch(() => {
      // jaringan error: pakai status dari PHP, jangan paksa logout
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
    h.textContent = room.title + " ";
    const chip = document.createElement("span");
    chip.className = `badge badge-${String(room.consoleType).toLowerCase()}`;
    chip.textContent = room.consoleModel;
    h.appendChild(chip);
    if (room.reviewCount) {
      const star = document.createElement("span");
      star.className = "rating-chip";
      star.textContent = `★ ${room.avgRating.toFixed(1)} (${room.reviewCount})`;
      star.title = `${room.reviewCount} ulasan`;
      h.appendChild(star);
    }
    const spec = document.createElement("p");
    spec.className = "room-spec small";
    spec.textContent = roomSpecText(room);
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

    // rundown hari ini: jadwal di kiri, status di kanan
    const rd = document.createElement("div");
    rd.className = "room-rundown";
    rd.dataset.rdCard = room.id;
    rd.innerHTML = `<span class="rr-time">--:--</span><span class="rr-status"></span>`;
    fillCardRundown(rd);

    body.appendChild(h);
    body.appendChild(spec);
    body.appendChild(p);
    body.appendChild(rd);
    body.appendChild(meta);
    card.appendChild(media);
    card.appendChild(body);
    return card;
  }

  const CATEGORY_LABEL = { private: "Private", private_pro: "Private Pro", public: "Public" };

  // teks spek ruangan, cth VVIP · Netflix · maks 4 pemain · +Nintendo
  function roomSpecText(room) {
    const parts = [room.tier === "Public" ? "Area Public" : `${CATEGORY_LABEL[room.category]} ${room.tier}`];
    if (room.hasNetflix) parts.push("Netflix");
    parts.push(`maks ${room.maxPlayers} pemain`);
    if (room.nintendoExtra) parts.push("bisa tambah Nintendo Switch");
    return parts.join(" · ");
  }

  // ubah data API ruangan ke bentuk data kartu
  function toRoom(r) {
    return {
      id:            Number(r.id),
      title:         r.title,
      category:      r.category,
      tier:          r.tier,
      consoleType:   r.console_type,
      consoleModel:  r.console_model,
      price:         Number(r.price),
      weekendExtra:  Number(r.weekend_extra),
      nintendoExtra: r.nintendo_extra !== null ? Number(r.nintendo_extra) : 0,
      maxPlayers:    Number(r.max_players),
      hasNetflix:    Number(r.has_netflix) === 1,
      avgRating:     r.avg_rating !== null ? Number(r.avg_rating) : null,
      reviewCount:   Number(r.review_count) || 0,
      // daftar game khusus ruangan (kosong = semua game konsolnya)
      gameIds:       r.game_ids ? r.game_ids.split(",").map(Number) : [],
      img:           r.image ? `assets/images/rooms/${r.image}` : "assets/images/rooms/default.svg",
      desc:          r.description || "Tidak ada deskripsi",
      // deskripsi asli buat pencarian
      rawDesc:       r.description || "",
      currentStatus: r.current_status || "available",
    };
  }

  // preview 3 ruangan paling sering dibooking
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
        data.forEach((r) => g.appendChild(createRoomCard(toRoom(r))));
      })
      .catch(() => {
        g.innerHTML = "<p class='muted'>Gagal memuat ruangan.</p>";
      });
  }

  // tab kategori ruangan (Private / Private Pro / Public)
  const roomCatMap = {
    "Semua":       null,
    "Private":     "private",
    "Private Pro": "private_pro",
    "Public":      "public",
  };
  let activeRoomCat = "Semua";
  let roomQuery = "";   // kata kunci dari search bar header

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

    const catFilter = roomCatMap[activeRoomCat];
    let filtered = catFilter
      ? roomsData.filter(r => r.category === catFilter)
      : roomsData;
    // hasil dari search bar header
    if (roomQuery) {
      filtered = filtered.filter((r) => roomMatches(r, roomQuery));
      const note = document.createElement("p");
      note.className = "muted small search-note";
      note.innerHTML = `Hasil pencarian "<strong>${escapeHtml(roomQuery)}</strong>" · ${filtered.length} ruangan · <a href="#" data-clear-room-search>Tampilkan semua</a>`;
      grid.appendChild(note);
    }

    if (filtered.length === 0) {
      grid.insertAdjacentHTML("beforeend", "<p class='muted'>Belum ada ruangan yg cocok.</p>");
    } else {
      filtered.forEach(r => grid.appendChild(createRoomCard(r)));
    }

    renderRoomCatTabs();
  }

  function showDetail(id) {
    const room = roomsData.find((r) => r.id === id);
    if (!room) return;

    // game konsol utama + game Switch kalo bisa tambah Nintendo
    const gameCard = (g) => {
          const cover = g.image ? `assets/images/games/${g.image}` : `assets/images/games/default.jpg`;
          return `
            <div class="game-mini-card">
              <img src="${escapeHtml(cover)}" alt="${escapeHtml(g.title)}" onerror="this.src='assets/images/games/default.jpg'">
              <div class="game-mini-body">
                <p class="game-mini-title">${escapeHtml(g.title)}</p>
                <p class="muted small">${escapeHtml(g.genre)}</p>
              </div>
            </div>`;
    };
    const gameList = (list) =>
      list.length ? list.map(gameCard).join('') : `<p class="muted small">Belum ada game untuk ruangan ini.</p>`;
    const roomGames = roomGameList(room);
    let gamesSection = `
      <h3 style="color:var(--neon);margin-bottom:12px">Game di ${escapeHtml(room.title)} <span class="muted small">(${roomGames.length})</span></h3>
      <div class="game-mini-list">${gameList(roomGames)}</div>`;
    if (room.nintendoExtra) {
      gamesSection += `
        <h3 style="color:var(--neon);margin:20px 0 12px">Game Nintendo Switch <span class="muted small">(dengan tambahan Nintendo)</span></h3>
        <div class="game-mini-list">${gameList(gamesData.filter(g => g.platforms.includes("SWITCH")))}</div>`;
    }

    // tabel harga: tarif, paket, weekend
    const priceRow = (label, rate) => {
      const pkg = PRICING.packages?.[rate];
      const cells = [1, 2, 3, 4, 5].map(h => `<td>${formatRup(pkg ? pkg[h - 1] : rate * h)}</td>`).join("");
      return `<tr><th>${label}</th>${cells}</tr>`;
    };
    let priceRows = priceRow(escapeHtml(room.consoleModel), room.price);
    if (room.nintendoExtra) priceRows += priceRow("+ Nintendo Switch", room.price + room.nintendoExtra);
    const student = PRICING.student?.[room.price];
    const priceHtml = `
      <div class="table-scroll">
        <table class="price-table">
          <thead><tr><th></th><th>1 jam</th><th>2 jam</th><th>3 jam</th><th>4 jam</th><th>5 jam</th></tr></thead>
          <tbody>${priceRows}</tbody>
        </table>
      </div>
      <p class="muted small" style="margin-top:6px">Sabtu & Minggu +${formatRup(room.weekendExtra)} per jam.${student ? ` Paket Pelajar (Senin-Jumat, tunjukkan kartu pelajar ke kasir): 1 jam ${formatRup(student[0])}, 3 jam ${formatRup(student[1])}.` : ""}</p>`;

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
      <p class="room-spec">${escapeHtml(roomSpecText(room))}</p>
      <p class="muted">${escapeHtml(room.desc)}</p>
      <div style="margin-top:14px">
        <h3 style="color:var(--neon);margin-bottom:8px">Harga</h3>
        ${priceHtml}
      </div>
      <div style="margin-top:12px;display:flex;gap:8px">
        ${bookBtnHtml}
        <button class="btn-ghost" data-action="back-to-rooms">Kembali ke list</button>
      </div>
      <div style="margin-top:24px">
        <h3 style="color:var(--neon);margin-bottom:4px">Jadwal Hari Ini</h3>
        <p class="muted small" style="margin-bottom:8px">Untuk besok atau lusa, cek jam yang tersedia di form booking.</p>
        <div id="roomTimeline" class="room-timeline"><span class="muted small">Memuat jadwal...</span></div>
      </div>
      <div style="margin-top:24px">${gamesSection}</div>
      <div style="margin-top:24px">
        <h3 style="color:var(--neon);margin-bottom:8px">Ulasan Pelanggan</h3>
        <div id="roomReviews"><span class="muted small">Memuat ulasan...</span></div>
      </div>
    `;
    showView("room-detail");

    fetch(`review_api.php?room_id=${room.id}`)
      .then((r) => r.json())
      .then((d) => {
        const box = document.getElementById("roomReviews");
        if (!box) return;
        if (!d.count) {
          box.innerHTML = `<p class="muted small">Belum ada ulasan. Main di sini lalu beri ulasan dari halaman Profil.</p>`;
          return;
        }
        box.innerHTML = `<p class="rating-summary">★ ${d.avg_rating.toFixed(1)} <span class="muted small">dari ${d.count} ulasan</span></p>`
          + d.reviews.map((rv) => `
            <div class="review-item">
              <div><strong>${escapeHtml(rv.username)}</strong> <span class="review-stars">${"★".repeat(rv.rating)}${"☆".repeat(5 - rv.rating)}</span> <span class="muted small">${escapeHtml(rv.date)}</span></div>
              ${rv.comment ? `<p class="small">${escapeHtml(rv.comment)}</p>` : ""}
            </div>`).join("");
      })
      .catch(() => {
        const box = document.getElementById("roomReviews");
        if (box) box.innerHTML = `<p class="muted small">Gagal memuat ulasan.</p>`;
      });

    fetch(`booked_slots_api.php?room_id=${room.id}`)
      .then((r) => r.json())
      .then((slots) => {
        const tl = document.getElementById("roomTimeline");
        if (!tl) return;
        const hours = [];
        const dayStart = wibDayStart();
        // 1 kotak = 30 menit dari buka sampai tutup, pakai timestamp biar sesi antrian menit ganjil tetap kedeteksi
        for (let t = OPEN_SEC; t < CLOSE_SEC; t += SLOT_SEC) {
          const hs = dayStart + t;
          const booked = slots.some((s) => hs < s.end_time && hs + SLOT_SEC > s.start_time);
          // jam lewat dikasih abu-abu
          const past = hs + SLOT_SEC <= nowSec();
          const label = secToHHMM(t);
          hours.push(
            `<div class="tl-hour ${booked ? "tl-booked" : "tl-free"}${past ? " timeline-past" : ""}" title="${label}–${secToHHMM(t + SLOT_SEC)} — ${past ? "Sudah lewat" : booked ? "Terpesan" : "Kosong"}">
               <span>${label}</span>
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
      // status dipakai gak relevan buat jam/tanggal lain, cek per slot lewat booked_slots_api.php
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

    // username di indikator akun (gak bisa diedit)
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
    syncNintendoField(room);

    if (!room || !dur || !timeVal) {
      box.innerHTML = `<div class="summary-placeholder"><p class="muted small">Pilih ruangan & durasi<br>untuk melihat estimasi biaya.</p></div>`;
      return;
    }

    const withN   = !!room.nintendoExtra && $("withNintendo")?.checked;
    const startTs = slotStartTs(timeVal);
    const rate    = roomHourlyRate(room, withN);
    const total   = bookingPrice(room, dur, startTs, withN);
    const weekend = isWeekendTs(startTs);
    // hemat paket dibanding tarif x jam
    const saving  = rate * dur + (weekend ? room.weekendExtra * dur : 0) - total;
    // saran naik 1 jam kalo jam tambahannya lebih murah dari tarif biasa
    let upsellHtml = "";
    const nextOpt = [...($("duration")?.options || [])].find((o) => o.value === String(dur + 1) && !o.disabled);
    if (nextOpt && PRICING.packages?.[rate] && dur < 5) {
      const add = bookingPrice(room, dur + 1, startTs, withN) - total;
      const normalAdd = rate + (weekend ? room.weekendExtra : 0);
      if (add < normalAdd) {
        upsellHtml = `<div class="upsell-box">
            <span>Tambah 1 jam jadi <strong>${dur + 1} jam</strong> hanya <strong>+${formatRup(add)}</strong> (hemat ${formatRup(normalAdd - add)})</span>
            <button type="button" class="btn-ghost" data-upsell="${dur + 1}">Tambah 1 jam</button>
          </div>`;
      }
    }

    const [h, m] = timeVal.split(":").map(Number);
    const endTime = secToHHMM(Math.min(h * 3600 + m * 60 + dur * 3600, CLOSE_SEC));

    box.innerHTML = `
      <div class="summary-row">
        <span class="sr-label">Ruangan</span>
        <span class="sr-val">${escapeHtml(room.title)}</span>
      </div>
      <div class="summary-row">
        <span class="sr-label">Konsol</span>
        <span class="sr-val">${escapeHtml(room.consoleModel)}${withN ? " + Nintendo Switch" : ""}</span>
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
        <span class="sr-val">${formatRup(rate)}</span>
      </div>
      ${weekend ? `<div class="summary-row"><span class="sr-label">Weekend</span><span class="sr-val">+${formatRup(room.weekendExtra)}/jam</span></div>` : ""}
      ${saving > 0 ? `<div class="summary-row"><span class="sr-label">Hemat paket</span><span class="sr-val">-${formatRup(saving)}</span></div>` : ""}
      <div class="summary-row total-row">
        <span class="sr-label">Total</span>
        <span class="sr-val">${formatRup(total)}</span>
      </div>
      ${upsellHtml}
    `;
  }

  // opsi Nintendo cuma buat ruangan yg ada
  function syncNintendoField(room) {
    const field = $("nintendoField");
    const box = $("withNintendo");
    if (!field || !box) return;
    const available = !!room?.nintendoExtra;
    field.style.display = available ? "" : "none";
    if (!available) box.checked = false;
    else $("nintendoExtra").textContent = `(+${formatRup(room.nintendoExtra)}/jam)`;
  }

  // ganti Ringkasan jadi bukti booking (kode, jadwal, total, cara bayar)
  function showBookingConfirmation(b) {
    const box = $("bookingSummary");
    if (!box) return;
    const start = new Date(b.start_time * 1000);
    const fmtTime = (d) => d.toLocaleTimeString("id-ID", { timeZone: TZ, hour: "2-digit", minute: "2-digit" });
    const dateText = start.toLocaleDateString("id-ID", { timeZone: TZ, weekday: "long", day: "numeric", month: "long" });
    const checkin = b.expires_at ? fmtTime(new Date(b.expires_at * 1000)) : null;
    const note = `Booking kamu sudah tercatat di sistem kasir, <strong>tidak perlu konfirmasi lagi</strong>. Cukup datang, tunjukkan kode ini, dan bayar di kasir.${checkin ? ` Lapor paling lambat <strong>${checkin}</strong>, lewat dari itu booking batal otomatis.` : ""} Datang telat, jam selesai tetap sesuai booking.`;
    box.innerHTML = `
      <div class="confirm-card">
        <div class="confirm-head">${icon('check')} Booking berhasil</div>
        <div>
          <span class="sr-label">Kode booking</span>
          <div class="confirm-code">${escapeHtml(b.order_code)}</div>
        </div>
        <div class="summary-row"><span class="sr-label">Ruangan</span><span class="sr-val">${escapeHtml(b.room)}</span></div>
        <div class="summary-row"><span class="sr-label">Tanggal</span><span class="sr-val">${dateText}</span></div>
        <div class="summary-row"><span class="sr-label">Sesi</span><span class="sr-val">${fmtTime(start)} – ${fmtTime(new Date(b.end_time * 1000))}</span></div>
        <div class="summary-row total-row"><span class="sr-label">Total</span><span class="sr-val">${formatRup(b.total_price)}</span></div>
        <p class="confirm-note">${note}</p>
        ${reminderButtons({ order_code: b.order_code, room: b.room, start_time: b.start_time, end_time: b.end_time })}
        <button type="button" class="btn-ghost" data-view="profile">Lihat di Profil</button>
      </div>`;
  }

  // update ringkasan tiap ruangan/durasi/jam berubah
  document.addEventListener("change", (e) => {
    if (["roomSelect", "duration", "timeStart", "bookingDate", "withNintendo"].includes(e.target.id)) {
      updateBookingSummary();
    }

    // update biaya pas jam perpanjangan diganti
    if (e.target.id?.startsWith("extend-hours-")) {
      const bid    = e.target.id.replace("extend-hours-", "");
      const costEl = document.getElementById(`extend-cost-${bid}`);
      if (costEl) costEl.textContent = `+${formatRup(Number(e.target.selectedOptions[0]?.dataset.cost) || 0)}`;
    }
  });

  let activeGenre = 'Semua';
  let gameQuery = ''; // keyword dari search header

  function renderGenreFilters() {
    const container = $("genreFilters");
    if (!container) return;
    const genres = ['Semua', ...[...new Set(gamesData.map(g => g.genre))].sort()];
    container.innerHTML = genres.map(g =>
      `<button class="genre-btn${g === activeGenre ? ' active' : ''}" data-genre="${escapeHtml(g)}">${escapeHtml(g)}</button>`
    ).join('');
  }

  // game di ruangan: room_games kalo ada, kalo gak semua game konsolnya
  function roomGameList(room) {
    return room.gameIds.length
      ? gamesData.filter(g => room.gameIds.includes(g.id))
      : gamesData.filter(g => g.platforms.includes(room.consoleType));
  }

  // teks ruangan di kartu game, cth Room 1, Room 3 +4 lainnya
  function gameRoomsText(game) {
    const names = roomsData
      .filter(r => r.currentStatus !== "in_service")
      .filter(r => r.gameIds.length ? r.gameIds.includes(game.id) : game.platforms.includes(r.consoleType))
      .map(r => r.title);
    if (game.platforms.includes("SWITCH") && roomsData.some(r => r.nintendoExtra && r.currentStatus !== "in_service")) {
      names.push("ruangan dengan tambahan Nintendo");
    }
    if (!names.length) return "";
    return names.length > 4 ? `${names.slice(0, 4).join(", ")} +${names.length - 4} lainnya` : names.join(", ");
  }

  function renderGames() {
    const wrap = $("gameList");
    wrap.innerHTML = "";

    const filtered = gamesData
      .filter(g => activeGenre === 'Semua' || g.genre === activeGenre)
      .filter(g => !gameQuery || matchAll(normSearch([g.title, g.genre, (g.platforms || []).join(" ")].join(" ")), gameQuery));

    if (gameQuery) {
      const note = document.createElement("p");
      note.className = "muted small search-note";
      note.innerHTML = `Hasil pencarian "<strong>${escapeHtml(gameQuery)}</strong>" · <a href="#" data-clear-game-search>Tampilkan semua game</a>`;
      wrap.appendChild(note);
    }

    if (filtered.length === 0) {
      wrap.insertAdjacentHTML("beforeend", "<p class='muted'>Tidak ada game yang cocok.</p>");
      return;
    }

    filtered.forEach((g) => {
      const card = document.createElement("div");
      card.className = "room-card";

      const badges = g.platforms
        .map((p) => `<span class="badge badge-${escapeHtml(p.toLowerCase())}">${p === "SWITCH" ? "Switch" : escapeHtml(p)}</span>`)
        .join(" ");

      const cover = g.image ? `assets/images/games/${g.image}` : `assets/images/games/default.jpg`;
      const roomsText = gameRoomsText(g);
      const coverHtml = `<img class="game-cover-img" src="${escapeHtml(cover)}" alt="${escapeHtml(g.title)}" onerror="this.src='assets/images/games/default.jpg'">`;

      card.innerHTML = `
      ${coverHtml}
      <div class="room-body">
        <h3 style="color:var(--neon)">${escapeHtml(g.title)}</h3>
        <p class="muted small">${escapeHtml(g.genre)}</p>
        <div style="margin-top:8px">${badges}</div>
        ${roomsText ? `<p class="muted small" style="margin-top:8px">Tersedia di: ${escapeHtml(roomsText)}</p>` : ""}
      </div>
    `;
      wrap.appendChild(card);
    });
  }

  document.addEventListener("click", (e) => {
    // 1. detail ruangan (prioritas)
    const detailBtn = e.target.closest("[data-detail]");
    if (detailBtn) {
      e.preventDefault();
      showDetail(Number(detailBtn.dataset.detail));
      return;
    }

    // 2. booking dari detail / kartu
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

    // 1. jangan ganggu klik di dalam modal
    if (e.target.closest(".modal")) {
      return;
    }

    // ===== NAVIGASI =====
    const vbtn = e.target.closest("[data-view]");
    if (vbtn) {
      const v = vbtn.dataset.view;

      e.preventDefault();

      if (v === "login") return;
      if (v === "profile") {
        // sama kayak link Detail di dropdown, biar data bookingnya sama
        openProfileDetail();
        return;
      }
      if (v === "consoles") { roomQuery = ""; renderAll(); }
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
        // tanpa ruangan, info jam terpesan sebelumnya dikosongin
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

  // tombol kembali
  $("backToRooms").addEventListener("click", (ev) => {
    ev.preventDefault();
    ev.stopPropagation();
    renderAll();
    showView("consoles");
  });

  // reset form booking
  $("clearBookings").addEventListener("click", () => {
    $("bookingForm").reset();
    // kosongin lewat fetchBookedSlots biar respons lama diabaikan
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
      alert("Tidak ada jam yang bisa dipilih untuk ruangan dan tanggal ini. Pilih tanggal atau ruangan lain.");
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
        nintendo: $("withNintendo")?.checked ? "1" : "",
        lock_room: $("lockRoom")?.checked ? "1" : "",
      }),
    })
      .then((r) => r.json())
      .then((r) => {
        if (r.status === "success") {
          loadUpcomingBooking();
          $("bookingForm").reset();
          syncNintendoField(null);
          generateDateOptions();
          generateTimeOptions();
          updateDurationOptions();
          // form balik ke Pilih Ruangan: kosongin slot & info ruangan
          fetchBookedSlots($("roomSelect").value);
          prefillBookingUser();
          showBookingConfirmation(r);
        } else {
          alert("Booking gagal: " + (r.message || "Terjadi kesalahan"));
        }
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { if (submitBtn) submitBtn.disabled = false; });
  });

  // search header
  // ===== SEARCH BAR HEADER =====
  const searchInput = $("navSearchInput");
  const suggestBox = $("searchSuggest");
  let suggestItems = [];   // item yg lagi tampil di dropdown
  let suggestActive = -1;  // index item yg disorot keyboard
  let menuSearchData = null;

  // menu dimuat sekali buat pencarian (tamu juga bisa liat hasilnya)
  function ensureMenuSearchData() {
    if (menuSearchData) return;
    menuSearchData = [];
    fetch("menu_api.php").then((r) => r.json())
      .then((d) => { menuSearchData = Array.isArray(d) ? d : []; if (searchInput.value.trim()) renderSuggest(); })
      .catch(() => {});
  }

  // halaman yg bisa dicari, kw = kata kunci tambahan
  const SEARCH_PAGES = [
    { label: "Cek ruangan kosong", view: "consoles", kw: "ruangan kosong cari jadwal rundown tersedia available daftar ruangan room" },
    { label: "Booking / ambil antrian", view: "booking", kw: "booking pesan reservasi antrian main sekarang" },
    { label: "Promo & paket hemat", view: "promo", kw: "promo paket hemat diskon pelajar harga murah weekend" },
    { label: "Fasilitas", view: "fasilitas", kw: "fasilitas wifi netflix ac parkir toilet" },
    { label: "Jam buka & lokasi", view: "home", kw: "jam buka operasional lokasi alamat maps whatsapp wa kontak beranda" },
    { label: "Daftar game", view: "game", kw: "game daftar game semua game" },
    { label: "Menu makanan & minuman", view: "menu", kw: "menu makanan minuman snack jajan pesan makan" },
    { label: "Profil & riwayat booking", view: "profile", kw: "profil akun riwayat booking saya poin" },
  ];

  // huruf kecil, "ps 5" = "ps5", nintendo = switch
  function normSearch(s) {
    return String(s || "").toLowerCase()
      .replace(/\bps\s+(\d)/g, "ps$1")
      .replace(/nintendo/g, "nintendo switch")
      .replace(/[^a-z0-9+ ]+/g, " ").replace(/\s+/g, " ").trim();
  }
  const CAT_LABEL = { private: "private", private_pro: "private pro", public: "public area" };
  function roomHaystack(r) {
    return normSearch([r.title, CAT_LABEL[r.category] || r.category, r.tier, r.consoleModel, r.rawDesc,
      r.hasNetflix ? "netflix" : "", r.nintendoExtra ? "bisa tambah nintendo" : "",
      `${r.maxPlayers} orang ${r.maxPlayers} pemain`].join(" "));
  }
  // semua kata harus ketemu (AND)
  // kata pendek / ada angka harus cocok di awal kata ("vip" gak kena "vvip", "3" gak kena "13")
  // loose = cari di tengah kata juga, dipakai kalo hasil ketat kosong
  let searchLoose = false;
  function matchAll(hay, q) {
    const words = normSearch(q).split(" ").filter(Boolean);
    const hw = hay.split(" ");
    return words.length > 0 && words.every((w) =>
      searchLoose || (w.length > 3 && !/\d/.test(w)) ? hay.includes(w) : hw.some((h) => h.startsWith(w)));
  }
  // "4 orang" / "3 pemain" = kapasitas minimal, bukan nama ruangan
  function roomMatches(r, q) {
    const cap = normSearch(q).match(/(\d+) (orang|pemain)/);
    if (cap) {
      if (r.maxPlayers < Number(cap[1])) return false;
      q = normSearch(q).replace(cap[0], " ").trim();
      if (!q) return true;
    }
    return matchAll(roomHaystack(r), q);
  }
  // makin kecil makin atas: awal judul > ada di judul > field lain
  function rankBy(title, q) {
    const t = normSearch(title), n = normSearch(q);
    return t.startsWith(n) ? 0 : t.includes(n) ? 1 : 2;
  }

  function searchAll(q) {
    searchLoose = false;
    const strict = searchAllOnce(q);
    if (strict.rooms.length + strict.games.length + strict.menus.length + strict.pages.length) return strict;
    searchLoose = true;
    const loose = searchAllOnce(q);
    searchLoose = false;
    return loose;
  }
  function searchAllOnce(q) {
    const sortRank = (arr, titleOf) => arr
      .map((x) => ({ x, r: rankBy(titleOf(x), q) }))
      .sort((a, b) => a.r - b.r)
      .map((o) => o.x);
    const rooms = sortRank(roomsData.filter((r) => roomMatches(r, q)), (r) => r.title);
    const games = sortRank(gamesData.filter((g) =>
      matchAll(normSearch([g.title, g.genre, (g.platforms || []).join(" ")].join(" ")), q)), (g) => g.title);
    const menus = sortRank((menuSearchData || []).filter((m) =>
      Number(m.is_available) !== 0 && matchAll(normSearch([m.name, m.category, m.description].join(" ")), q)), (m) => m.name);
    const pages = SEARCH_PAGES.filter((p) => matchAll(normSearch(p.label + " " + p.kw), q));
    return { rooms, games, menus, pages };
  }

  // status singkat ruangan hari ini dari rundown (kalau udah kemuat)
  function roomNowText(id) {
    const d = typeof rundownCache !== "undefined" ? rundownCache : null;
    if (!d) return "";
    const room = d.rooms.find((r) => r.id === id);
    if (room?.maintenance) return "Perawatan";
    if (d.now >= d.close) return "Tutup";
    if (d.now < d.open) return "Belum buka";
    const cur = d.slots.find((s) => s.room_id === id && s.start <= d.now && s.end > d.now);
    return cur ? (cur.playing ? "Dipakai" : "Dipesan") : "Kosong";
  }

  function closeSuggest() {
    suggestBox.hidden = true;
    suggestBox.innerHTML = "";
    suggestItems = [];
    suggestActive = -1;
    searchInput.setAttribute("aria-expanded", "false");
  }

  function renderSuggest() {
    const q = searchInput.value.trim();
    if (!q) { closeSuggest(); return; }
    const res = searchAll(q);
    const groups = [];
    const add = (title, list, toItem) => { if (list.length) groups.push({ title, items: list.slice(0, 4).map(toItem), more: list.length - 4 }); };
    add("Ruangan", res.rooms, (r) => ({
      label: r.title, sub: `${r.consoleModel} · ${formatRup(r.price)}/jam`, badge: roomNowText(r.id),
      run: () => { stopMenuRefresh(); showDetail(r.id); },
    }));
    add("Game", res.games, (g) => ({
      label: g.title, sub: [g.genre, (g.platforms || []).map((p) => p === "SWITCH" ? "Switch" : p).join(", ")].filter(Boolean).join(" · "),
      run: () => { stopMenuRefresh(); loadGamesFromDB(g.title); showView("game"); },
    }));
    add("Menu", res.menus, (m) => ({
      label: m.name, sub: formatRup(m.price),
      run: () => goView("menu"),
    }));
    add("Halaman", res.pages, (p) => ({ label: p.label, sub: "", run: () => goView(p.view) }));

    suggestItems = [];
    let html = "";
    groups.forEach((g) => {
      html += `<div class="ss-group">${escapeHtml(g.title)}${g.more > 0 ? ` <span>+${g.more}</span>` : ""}</div>`;
      g.items.forEach((it) => {
        const i = suggestItems.push(it) - 1;
        html += `<div class="ss-item" role="option" data-ss="${i}">
          <span class="ss-main"><strong>${escapeHtml(it.label)}</strong>${it.sub ? `<small>${escapeHtml(it.sub)}</small>` : ""}</span>
          ${it.badge ? `<span class="ss-badge ss-${escapeHtml(it.badge.toLowerCase())}">${escapeHtml(it.badge)}</span>` : ""}
        </div>`;
      });
    });
    if (!suggestItems.length) {
      html = `<div class="ss-empty">Tidak ada hasil untuk "<strong>${escapeHtml(q)}</strong>"</div>`;
      [["Cek ruangan kosong", "consoles"], ["Lihat semua game", "game"]].forEach(([label, view]) => {
        const i = suggestItems.push({ label, sub: "", run: () => goView(view) }) - 1;
        html += `<div class="ss-item" role="option" data-ss="${i}"><span class="ss-main"><strong>${label}</strong></span></div>`;
      });
    } else {
      const i = suggestItems.push({ label: "all", run: () => runSearch(q) }) - 1;
      html += `<div class="ss-item ss-all" role="option" data-ss="${i}">Lihat semua hasil untuk "${escapeHtml(q)}"</div>`;
    }
    suggestBox.innerHTML = html;
    suggestBox.hidden = false;
    suggestActive = -1;
    searchInput.setAttribute("aria-expanded", "true");
  }

  // pindah halaman lewat link nav biar logika tiap halaman (login, load data) tetap kepake
  function goView(view) {
    if (view === "menu" && !IS_LOGGED_IN) {
      requireLogin("Silakan login dulu untuk pesan menu.", "menu");
      return;
    }
    const link = document.querySelector(`.nav [data-view="${view}"], [data-view="${view}"]`);
    if (link) link.click(); else showView(view);
  }

  // Enter tanpa milih saran
  function runSearch(raw) {
    const q = raw.trim();
    closeSuggest();
    if (!q) return;
    const res = searchAll(q);
    stopMenuRefresh();
    if (res.rooms.length === 1 && !res.games.length) { showDetail(res.rooms[0].id); return; }
    if (res.rooms.length) {
      roomQuery = q;
      activeRoomCat = "Semua";
      renderAll();
      showView("consoles");
      return;
    }
    if (res.games.length) { loadGamesFromDB(q); showView("game"); return; }
    if (res.menus.length) { goView("menu"); return; }
    if (res.pages.length) { goView(res.pages[0].view); return; }
    renderSuggest(); // tampilin pesan gak ada hasil
  }

  function pickSuggest(i) {
    const it = suggestItems[i];
    if (!it) return;
    closeSuggest();
    searchInput.blur();
    it.run();
  }

  function highlightSuggest(i) {
    const els = suggestBox.querySelectorAll(".ss-item");
    if (!els.length) return;
    suggestActive = (i + els.length) % els.length;
    els.forEach((el, k) => el.classList.toggle("active", k === suggestActive));
    els[suggestActive].scrollIntoView({ block: "nearest" });
  }

  searchInput.addEventListener("input", () => { ensureMenuSearchData(); renderSuggest(); });
  searchInput.addEventListener("focus", () => { ensureMenuSearchData(); if (searchInput.value.trim()) renderSuggest(); });
  searchInput.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown") { e.preventDefault(); if (suggestBox.hidden) renderSuggest(); highlightSuggest(suggestActive + 1); }
    else if (e.key === "ArrowUp") { e.preventDefault(); highlightSuggest(suggestActive - 1); }
    else if (e.key === "Escape") { closeSuggest(); }
  });
  // mousedown biar kepilih sebelum input blur
  suggestBox.addEventListener("mousedown", (e) => {
    const el = e.target.closest("[data-ss]");
    if (!el) return;
    e.preventDefault();
    pickSuggest(Number(el.dataset.ss));
  });
  document.addEventListener("click", (e) => { if (!e.target.closest("#navSearchForm")) closeSuggest(); });

  $("navSearchForm").addEventListener("submit", (ev) => {
    ev.preventDefault();
    if (!searchInput.value.trim()) { searchInput.focus(); return; }
    if (suggestActive >= 0 && !suggestBox.hidden) { pickSuggest(suggestActive); return; }
    searchInput.blur();
    runSearch(searchInput.value);
  });

  // load data dari DB setelah UI siap
  loadRoomsFromDB();
  fetch("games_api.php")
    .then((r) => r.json())
    .then((data) => { gamesData.length = 0; data.forEach((g) => gamesData.push(g)); })
    .catch(() => {});

  function refreshRoomsUI() {
    // tetap render walau kosong biar ruangan yg dihapus ikut hilang
    if (!Array.isArray(roomsData)) return;
    renderPreview();
    renderAll();
    populateSelect();
  }

  // ambil ruangan dari DB
  function loadRoomsFromDB() {
    fetch("rooms_api.php")
      .then((res) => res.json())
      .then((data) => {
        roomsData.length = 0;
        data.forEach((r) => roomsData.push(toRoom(r)));
        refreshRoomsUI();
      })
      .catch((err) => console.error("Rooms load error:", err));
  }

  document.addEventListener("click", (e) => {
    // filter genre game
    const genreBtn = e.target.closest(".genre-btn[data-genre]");
    if (genreBtn) {
      activeGenre = genreBtn.dataset.genre;
      renderGenreFilters();
      renderGames();
      return;
    }

    // hapus filter cari game
    if (e.target.closest("[data-clear-game-search]")) {
      e.preventDefault();
      gameQuery = "";
      const input = $("navSearchInput");
      if (input) input.value = "";
      renderGames();
      return;
    }

    // hapus filter cari ruangan
    if (e.target.closest("[data-clear-room-search]")) {
      e.preventDefault();
      roomQuery = "";
      const input = $("navSearchInput");
      if (input) input.value = "";
      renderAll();
      return;
    }

    // filter kategori ruangan (hasil search ikut dilepas)
    const roomCatBtn = e.target.closest(".genre-btn[data-roomcat]");
    if (roomCatBtn) {
      activeRoomCat = roomCatBtn.dataset.roomcat;
      roomQuery = "";
      renderAll();
      return;
    }

    // balik ke daftar ruangan
    if (e.target.closest("[data-action='back-to-rooms']")) {
      renderAll();
      showView("consoles");
    }
  });

  // ambil game dari DB
  function loadGamesFromDB(query = "") {
    fetch("games_api.php")
      .then((res) => res.json())
      .then((data) => {
        gamesData.length = 0;
        activeGenre = 'Semua';
        gameQuery = query;
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
      loadMyOrders(true); // silent = tanpa teks loading
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
    const cats = ['Semua', 'Makanan', 'Snack', 'Minuman'];
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
        : `assets/images/menu/default.svg`;

      const stockBadge = item.is_available == 1
        ? `<span class="menu-stock-badge stock-tersedia">Tersedia</span>`
        : `<span class="menu-stock-badge stock-habis">Stok Habis</span>`;

      const btnPesan = item.is_available == 1
        ? `<button class="btn menu-order-btn" data-id="${escapeHtml(item.id)}" data-name="${escapeHtml(item.name)}" data-price="${escapeHtml(item.price)}" data-desc="${escapeHtml(item.description || "")}">Pesan</button>`
        : `<button class="btn btn-disabled" disabled>Stok Habis</button>`;

      card.innerHTML = `
        <div class="menu-img-wrap">
          <img src="${escapeHtml(imgSrc)}" alt="${escapeHtml(item.name)}" onerror="this.src='assets/images/menu/default.svg'">
          ${stockBadge}
        </div>
        <div class="room-body">
          <h3 style="color:var(--neon)">${escapeHtml(item.name)}</h3>
          ${item.description && item.description.trim() !== "-" ? `<p class="muted small">${escapeHtml(item.description)}</p>` : ""}
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

  // pesanan yg udah selesai, null = belum pernah kemuat (biar pesanan lama gak dinotif)
  let knownDoneOrders = null;
  window.resetKnownOrders = () => { knownDoneOrders = null; };

  function loadMyOrders(silent = false) {
    const box = $("myOrdersList");
    if (!box) return;
    if (!silent) box.innerHTML = "<p class='muted small'>Memuat pesanan...</p>";

    fetch("menu_order_api.php?action=my_orders")
      .then((res) => res.json())
      .then((data) => {
        // user keburu logout: jangan tampilin pesanan lama
        if (!IS_LOGGED_IN || !Array.isArray(data)) return;

        // notif cuma buat pesanan yg baru selesai
        const doneNow = data.filter((o) => o.status === 'selesai');
        if (knownDoneOrders) {
          doneNow.forEach((o) => {
            if (!knownDoneOrders.has(String(o.id))) showOrderNotif(o.name);
          });
        }
        knownDoneOrders = new Set(doneNow.map((o) => String(o.id)));

        if (data.length === 0) {
          box.innerHTML = "<p class='muted small'>Belum ada pesanan.</p>";
          return;
        }

        box.innerHTML = data.map(o => {
          const total = formatRup(o.price * o.quantity);
          const statusCls = o.status === 'selesai' ? 'order-done' : o.status === 'batal' ? 'order-cancelled' : 'order-pending';
          const statusLabel = o.status === 'selesai' ? `${icon('check')} Sudah Sampai`
            : o.status === 'batal' ? `${icon('alert')} Dibatalkan kasir` : `${icon('clock')} Diproses`;
          const [cDate, cTime] = String(o.created_at).split(" ");
          const tgl = new Date(wibTs(cDate, (cTime || "00:00").slice(0, 5)) * 1000).toLocaleString('id-ID', { timeZone: TZ,
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
    notif.setAttribute("role", "status");
    notif.setAttribute("aria-live", "polite");
    const stacked = document.querySelectorAll(".order-notif").length;
    if (stacked) notif.style.bottom = `${28 + stacked * 64}px`;
    notif.innerHTML = `${icon('utensils')} "${escapeHtml(itemName)}" sudah sampai!`;
    document.body.appendChild(notif);
    requestAnimationFrame(() => notif.classList.add("show"));
    setTimeout(() => {
      notif.classList.remove("show");
      setTimeout(() => notif.remove(), 400);
    }, 4000);
  }

  // dipakai juga kode antrian di luar closure
  window.loadUpcomingBooking = loadUpcomingBooking;
  function loadUpcomingBooking() {
    const banner = document.getElementById("upcomingBanner");
    const detail = document.getElementById("upcomingDetail");
    if (!banner || !IS_LOGGED_IN) return;

    fetch("upcoming_booking_api.php")
      .then((r) => r.json())
      .then((data) => {
        // user bisa logout pas request masih jalan
        if (!data || !IS_LOGGED_IN) { banner.style.display = "none"; return; }
        const fmt = (u) => new Date(u * 1000).toLocaleTimeString("id-ID", { timeZone: TZ, hour: "2-digit", minute: "2-digit" });
        const now = nowSec();
        const label = document.getElementById("upcomingLabel");
        const started = data.start_time <= now;
        const waitingArrival = started && data.expires_at;
        if (label) {
          label.textContent = waitingArrival ? "Menunggu Kedatanganmu"
            : started ? "Sesi Sedang Berjalan" : "Booking Mendatang";
        }
        if (detail) {
          const day = new Date(data.start_time * 1000).toLocaleDateString("id-ID", { timeZone: TZ, weekday: "long", day: "numeric", month: "short" });
          let text = started
            ? `${data.room} — selesai ${fmt(data.end_time)}`
            : `${data.room} — ${day}, jam ${fmt(data.start_time)} (${data.duration} jam) · ${data.order_code}`;
          if (data.expires_at) text += ` · lapor ke kasir sebelum ${fmt(data.expires_at)}`;
          detail.textContent = text;
        }
        banner.style.display = "block";
        scheduleStatusRefresh([data.start_time, data.end_time, data.expires_at]);
      })
      .catch(() => { banner.style.display = "none"; });
  }

  // reload banner (& profil kalo kebuka) pas jam mulai, batas lapor, atau jam selesai lewat
  let statusRefreshTimer = null;
  function scheduleStatusRefresh(times) {
    const now = nowSec();
    const next = times.map(Number).filter((t) => t && t > now).sort((a, b) => a - b)[0];
    clearTimeout(statusRefreshTimer);
    if (!next || next - now > 6 * 3600) return;
    statusRefreshTimer = setTimeout(() => {
      if (!IS_LOGGED_IN) return;
      loadUpcomingBooking();
      const profileOpen = document.getElementById("view-profile")?.style.display !== "none";
      const extendOpen = [...document.querySelectorAll("[id^='extend-form-']")].some((f) => f.style.display === "block");
      if (profileOpen && !extendOpen) openProfileDetail(true);
    }, (next - now + 2) * 1000);
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

  // ===== PERPANJANG BOOKING =====
  document.addEventListener("click", (e) => {
    // tombol Perpanjang
    const extBtn = e.target.closest(".extend-btn");
    if (extBtn) {
      // opsi jam & harga udah dari server, tinggal buka form
      const form = document.getElementById(`extend-form-${extBtn.dataset.bid}`);
      if (form) form.style.display = form.style.display === "block" ? "none" : "block";
      return;
    }

    // tombol Batal
    const cancelBtn = e.target.closest("[data-extend-cancel]");
    if (cancelBtn) {
      const form = document.getElementById(`extend-form-${cancelBtn.dataset.extendCancel}`);
      if (form) form.style.display = "none";
      return;
    }

    // tombol Konfirmasi perpanjang
    const confirmBtn = e.target.closest("[data-extend]");
    if (confirmBtn) {
      const bid        = confirmBtn.dataset.extend;
      const sel        = document.getElementById(`extend-hours-${bid}`);
      const extraHours = sel?.value || 1;
      const altRoom    = sel?.selectedOptions[0]?.dataset.alt || "";
      if (altRoom && !confirm(`Ruangan ini sudah dipesan orang lain. Lanjut ${extraHours} jam di ${altRoom} (kelas & harga sama)? Pindah ruangan setelah sesi sekarang selesai.`)) return;

      confirmBtn.disabled = true;
      confirmBtn.textContent = "Memproses...";

      fetch(altRoom ? "continue_session.php" : "extend_booking.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ booking_id: bid, extra_hours: extraHours }),
      })
        .then((r) => r.json())
        .then((res) => {
          if (res.status === "ok") {
            alert(altRoom
              ? `Siap! Setelah sesi ini selesai, lanjut main ${extraHours} jam di ${res.room}. Tagihan tambahan dibayar di kasir.`
              : `Sesi berhasil diperpanjang ${extraHours} jam!`);
            loadUpcomingBooking();
            openProfileDetail(true);
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

    // tombol Batalkan booking
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
            openProfileDetail(true);
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

    // tombol Pesan Lagi
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

  // tab kategori menu
  document.addEventListener("click", (e) => {
    const btn = e.target.closest(".menu-cat-btn");
    if (!btn) return;
    activeMenuCat = btn.dataset.cat;
    renderMenuCatTabs();
    renderMenuGrid();
  });

  // tombol Pesan di kartu menu, buka modal
  document.addEventListener("click", (e) => {
    const btn = e.target.closest(".menu-order-btn");
    if (!btn) return;
    $("orderItemId").value   = btn.dataset.id;
    $("orderItemName").textContent = btn.dataset.name;
    // item bervarian (cth Mojito: Strawberry / Mangga), minta tulis pilihan di catatan
    const variants = btn.dataset.desc && btn.dataset.desc.includes("/") ? btn.dataset.desc : "";
    $("orderItemPrice").textContent = formatRup(btn.dataset.price) + " / item" + (variants ? ` · Pilihan: ${variants}` : "");
    $("orderQty").value  = 1;
    $("orderNote").value = "";
    $("orderNote").placeholder = variants ? "Tulis pilihanmu, contoh: " + variants.split("/")[0].trim() : "Contoh: tidak pedas, es batu sedikit";
    document.getElementById("orderModal").classList.add("show");
  });

  // tutup modal pesan
  document.getElementById("closeOrderModal")?.addEventListener("click", () => {
    document.getElementById("orderModal").classList.remove("show");
  });
  document.querySelector("#orderModal .modal-overlay")?.addEventListener("click", () => {
    document.getElementById("orderModal").classList.remove("show");
  });

  // submit pesanan
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

  // ===== TOMBOL LIHAT PASSWORD =====
  // input password dibungkus biar ada tombol mata
  document.querySelectorAll('input[type="password"]').forEach((input) => {
    const wrap = document.createElement("span");
    wrap.className = "pass-wrap";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "pass-toggle";
    btn.innerHTML = icon("eye");
    btn.setAttribute("aria-label", "Tampilkan password");
    btn.addEventListener("click", () => {
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      btn.classList.toggle("on", show);
      btn.setAttribute("aria-label", show ? "Sembunyikan password" : "Tampilkan password");
    });
    wrap.appendChild(btn);
  });
  // form ditutup/reset: balik ke tersembunyi
  document.querySelectorAll("form").forEach((f) => f.addEventListener("reset", () => {
    f.querySelectorAll(".pass-wrap input").forEach((i) => { i.type = "password"; });
    f.querySelectorAll(".pass-toggle").forEach((b) => b.classList.remove("on"));
  }));

  // ===== MODAL: ESC & FOKUS =====
  // Esc = klik Batal modal yg kebuka
  // fokus ke input pertama pas buka, balik ke elemen asal pas tutup
  const MODAL_CLOSE_BTN = { loginModal: "closeLogin", registerModal: "closeRegister",
                            orderModal: "closeOrderModal", forgotModal: "closeForgot", reviewModal: "closeReview", rescheduleModal: "closeReschedule" };
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    const open = document.querySelector(".modal.show");
    if (open) document.getElementById(MODAL_CLOSE_BTN[open.id])?.click();
  });
  let focusBeforeModal = null;
  const modalObserver = new MutationObserver((muts) => muts.forEach((m) => {
    const wasOpen = (m.oldValue || "").split(" ").includes("show");
    const isOpen = m.target.classList.contains("show");
    if (isOpen && !wasOpen) {
      if (!document.activeElement?.closest(".modal")) focusBeforeModal = document.activeElement;
      m.target.querySelector("input:not([type=hidden]), button")?.focus();
    } else if (!isOpen && wasOpen && !document.querySelector(".modal.show")) {
      focusBeforeModal?.focus?.();
      focusBeforeModal = null;
    }
  }));
  document.querySelectorAll(".modal").forEach((m) =>
    modalObserver.observe(m, { attributes: true, attributeFilter: ["class"], attributeOldValue: true }));

  // ===== MODAL LOGIN =====
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

  // overlay login
  const loginOverlay = loginModal?.querySelector(".modal-overlay");

  loginOverlay?.addEventListener("click", (e) => {
    if (e.target === loginOverlay) {
      loginModal.classList.remove("show");
    }
  });

  // ===== MODAL DAFTAR =====
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

  // ===== MODAL GANTI PASSWORD (pakai password lama) =====
  const forgotModal    = document.getElementById("forgotModal");

  function openForgot() {
    // bukan <form>, jadi tombol mata direset di sini
    forgotModal.querySelectorAll(".pass-wrap input").forEach((i) => { i.type = "password"; });
    forgotModal.querySelectorAll(".pass-toggle").forEach((b) => b.classList.remove("on"));
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

  // overlay daftar
  const registerOverlay = registerModal?.querySelector(".modal-overlay");

  registerOverlay?.addEventListener("click", (e) => {
    if (e.target === registerOverlay) {
      resetRegisterForm();
      registerModal.classList.remove("show");
    }
  });

  // ===== SUBMIT LOGIN =====
  document.getElementById("loginForm")?.addEventListener("submit", (e) => {
    e.preventDefault();

    const username = document.getElementById("loginUsername").value.trim();
    const password = document.getElementById("loginPassword").value.trim();

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

  // ===== DAFTAR =====
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
    window.resetKnownOrders?.();
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

  /* abis login */
  function resetProfileData() {
    const totalHours = document.getElementById("profileTotalHours");
    if (totalHours) totalHours.textContent = "0 jam";
    const profileUsername = document.getElementById("profileUsername");
    if (profileUsername) profileUsername.textContent = "-";
    const profileBookingList = document.getElementById("profileBookingList");
    if (profileBookingList) profileBookingList.innerHTML = '<p class="muted small">Belum ada booking.</p>';
    const myOrdersList = document.getElementById("myOrdersList");
    if (myOrdersList) myOrdersList.innerHTML = "";
    const profileSince = document.getElementById("profileSince");
    if (profileSince) profileSince.textContent = "-";
    renderPoints(0, 10);
  }

  function onLoginSuccess(username) {
    IS_LOGGED_IN = true;
    window.AUTH = { loggedIn: true, username: username };

    resetProfileData();
    resetLoginForm();

    document.getElementById("loginModal").classList.remove("show");
    setLoggedIn(username);

    // balik ke halaman yg tadi minta login, bukan Beranda
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

  /* logout */
  function doLogout() {
    if (!confirm("Yakin mau logout?")) return;
    IS_LOGGED_IN = false;
    setLoggedOut();
    resetLoginForm();
    showView("home");
    fetch("logout.php", { method: "POST" }).catch(() => {});
  }

  // sesi server habis: tampilan jadi logout, minta login lagi, abis login balik ke sini
  window.onSessionExpired = () => {
    const view = Object.keys(views).find((k) => document.getElementById(views[k])?.style.display === "block") || "home";
    setLoggedOut();
    if (view === "profile") showView("home");
    requireLogin("Sesi login kamu sudah habis karena lama tidak aktif. Silakan login lagi.", view);
  };

  document.getElementById("logoutBtn")?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    doLogout();
  });

  // reset form login
  function resetLoginForm() {
    const form = document.getElementById("loginForm");
    if (!form) return;

    form.reset();

    // pastiin kosong (anti cache browser)
    const inputs = form.querySelectorAll("input");
    inputs.forEach((inp) => {
      inp.value = "";
      inp.blur();
    });
  }

  // reset form daftar
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

  /* ===== DROPDOWN PROFIL ===== */
  const profileToggle = document.getElementById("profileToggle");
  const profileDropdown = document.getElementById("profileDropdown");

  profileToggle?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();

    const isOpen = profileDropdown.style.display === "flex";
    profileDropdown.style.display = isOpen ? "none" : "flex";
  });

  /* klik di luar -> dropdown nutup */
  document.addEventListener("click", () => {
    if (profileDropdown) {
      profileDropdown.style.display = "none";
    }
  });

  // tutup dropdown
  function closeProfileDropdown() {
    const dropdown = document.getElementById("profileDropdown");
    if (dropdown) {
      dropdown.style.display = "none";
    }
  }

  // logout dari halaman profil
  document.getElementById("profileLogoutBtn")?.addEventListener("click", (e) => {
    e.stopPropagation();
    doLogout();
  });

  // ===== DETAIL PROFIL =====
  // dipakai link Detail di dropdown & tombol Lihat Detail di banner biar datanya sama
  // keepScroll: reload data tanpa loncat ke atas & tanpa ngosongin daftar
  function openProfileDetail(keepScroll = false) {
    stopMenuRefresh();
    loadUserBookings();

    // tampilin view
    showView("profile", keepScroll);

    // riwayat booking di profil
    function loadUserBookings() {
        const box = document.getElementById("profileBookingList");
        if (!box) return;

        if (window._bookingCountdownInterval) {
          clearInterval(window._bookingCountdownInterval);
          window._bookingCountdownInterval = null;
        }

        if (!keepScroll) box.innerHTML = '<p class="muted small">Memuat booking...</p>';

        let playedHours = null;
        fetch("get_user_bookings.php?t=" + Date.now())
          .then((r) => { playedHours = r.headers.get("X-Played-Hours"); return r.json(); })
          .then((data) => {
            if (!IS_LOGGED_IN) return;
            if (!data || data.length === 0) {
              box.innerHTML = '<p class="muted small">Belum ada booking.</p>';
              return;
            }

            box.innerHTML = "";
            const now = nowSec();

            const totalHours = data
              // no-show gak dihitung jam main (udah selesai tapi expires_at masih ada = gak pernah check-in)
              .filter(b => parseInt(b.end_time) <= now && b.payment_status !== "cancelled" && !b.expires_at)
              .reduce((sum, b) => sum + parseInt(b.duration), 0);
            const totalEl = document.getElementById("profileTotalHours");
            // dari server (semua booking), riwayat di sini cuma 50 terbaru
            if (totalEl) totalEl.textContent = (playedHours !== null ? Number(playedHours) : totalHours) + " jam";

            data.forEach((b) => {
              const start = parseInt(b.start_time);
              const end   = parseInt(b.end_time);
              // batas lapor udah lewat tapi server belum sempat batalin
              const missedCheckin = b.payment_status === "unpaid" && b.expires_at && now > b.expires_at;
              const isCancelled = b.payment_status === "cancelled" || missedCheckin;
              const isActive   = !isCancelled && now >= start && now < end;
              const isUpcoming = !isCancelled && now < start;
              const isDone     = !isCancelled && now >= end;

              // expires_at keisi = belum check-in
              const notCheckedIn = !isCancelled && b.expires_at && (isUpcoming || isActive);
              const fmtTime = (u) => new Date(u * 1000).toLocaleTimeString("id-ID", { timeZone: TZ, hour: "2-digit", minute: "2-digit" });

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
                  ? `<span class="pay-chip paid">Lunas</span>`
                  : `<span class="pay-chip unpaid">Belum bayar · bayar di kasir</span>`;
              const metaHtml = `<div class="booking-meta">
                  ${b.order_code ? `<span class="code-chip">${escapeHtml(b.order_code)}</span>` : ""}${payHtml}
                </div>`;
              const checkinHtml = notCheckedIn
                ? `<p class="checkin-note">Lapor ke kasir sebelum ${fmtTime(b.expires_at)}, lewat dari itu booking batal otomatis.</p>`
                : "";

              // sama kayak cancel_booking.php: belum lunas & belum check-in
              const canCancel = !isCancelled && b.payment_status !== "paid" && !isDone
                && (b.expires_at || start > now + 900);
              const cancelHtml = canCancel ? `
                <div style="margin-top:8px">
                  <button class="btn-ghost cancel-booking-btn" data-bid="${b.id}" data-room="${escapeHtml(b.room)}"
                    style="font-size:0.78rem;padding:4px 10px;color:#ef7a5f;border-color:#ef7a5f44">
                    Batalkan
                  </button>
                </div>` : '';

              // layanan mandiri: ubah jadwal (sebelum mulai & belum lapor) & tukar poin (belum lunas)
              const canReschedule = isUpcoming && !!b.expires_at;
              const canRedeem = !isCancelled && !isDone && b.payment_status !== "paid";
              const selfHtml = canReschedule || canRedeem ? `
                <div class="self-actions">
                  ${canReschedule ? `<button class="btn-ghost reschedule-btn" data-reschedule="${escapeHtml(JSON.stringify({
                    id: b.id, room_id: Number(b.room_id), start: start, duration: Number(b.duration), room: b.room,
                    nintendo: !!b.with_nintendo, student: !!b.is_student, discount: Number(b.discount) || 0,
                    paid: Number(b.amount_paid) || 0 }))}">Ubah Jadwal</button>` : ""}
                  ${canRedeem ? (b.discount
                    ? `<button class="btn-ghost redeem-btn" data-redeem="${b.id}" data-undo="1">Batal Pakai Poin</button>`
                    : `<button class="btn-ghost redeem-btn" data-redeem="${b.id}" data-need-points hidden>Pakai 10 Poin (gratis 1 jam)</button>`) : ""}
                </div>` : "";

              const reminderHtml = isUpcoming && b.order_code
                ? reminderButtons({ order_code: b.order_code, room: b.room, start_time: start, end_time: end })
                : "";
              // ulasan cuma buat sesi yg beneran dimainin
              const reviewHtml = isDone && !b.expires_at
                ? (b.reviewed
                    ? `<span class="small muted review-done">★ Sudah diulas</span>`
                    : `<button class="btn-ghost review-btn" data-review="${b.id}" data-room="${escapeHtml(b.room)}" style="font-size:0.78rem;padding:4px 10px">★ Beri Ulasan</button>`)
                : "";

              const reorderHtml = isDone ? `
                <div style="margin-top:8px">
                  <button class="btn-ghost reorder-btn" data-roomid="${b.room_id}"
                    style="font-size:0.78rem;padding:4px 10px">
                    Pesan Lagi
                  </button>
                  ${reviewHtml}
                </div>` : '';

              // opsi tambah jam dari server: di ruangan ini atau lanjut di ruangan sekelas
              let extendHtml = "";
              if (isActive && !notCheckedIn && b.extend) {
                const opts = Object.values(b.extend.options);
                const optHtml = opts.map((o) => {
                  const label = o.same_room ? `+${o.hours} jam`
                    : o.alt_room ? `+${o.hours} jam — lanjut di ${o.alt_room}`
                    : o.reason === "close" ? `+${o.hours} jam — lewat jam tutup`
                    : o.reason === "max" ? `+${o.hours} jam — lewat batas 12 jam`
                    : `+${o.hours} jam — semua ruangan sekelas penuh`;
                  const ok = o.same_room || o.alt_room;
                  return `<option value="${o.hours}" data-cost="${o.cost}" ${o.alt_room ? `data-alt="${escapeHtml(o.alt_room)}"` : ""} ${ok ? "" : "disabled"}>${escapeHtml(label)}</option>`;
                }).join("");
                const firstOk = opts.find((o) => o.same_room || o.alt_room);
                const blocked = opts.find((o) => o.blocked_at);
                const untilText = b.extend.until > end
                  ? `Bisa diperpanjang di ruangan ini sampai jam <strong>${fmtTime(b.extend.until)}</strong>.`
                  : blocked ? `Ruangan ini sudah dipesan orang lain mulai jam <strong>${fmtTime(blocked.blocked_at)}</strong>.` : "";
                extendHtml = `
                <div style="margin-top:8px">
                  ${untilText ? `<p class="extend-until">${untilText}${!firstOk?.same_room && firstOk?.alt_room ? ` Kamu tetap bisa lanjut main di ruangan sekelas yang kosong.` : ""}</p>` : ""}
                  <button class="btn-ghost extend-btn" data-bid="${b.id}" style="font-size:0.78rem;padding:4px 10px" ${firstOk ? "" : "disabled"}>+ Perpanjang</button>
                  <div id="extend-form-${b.id}" style="display:none;margin-top:8px">
                    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px">
                      <select id="extend-hours-${b.id}"
                        style="padding:4px 8px;background:#25201a;color:#fff;border:1px solid #ffe6bf44;border-radius:6px">${optHtml}</select>
                      <span id="extend-cost-${b.id}" style="color:#ffe6bf;font-size:0.82rem;font-weight:600">+${formatRup(firstOk?.cost || 0)}</span>
                      <button class="btn" data-extend="${b.id}" style="font-size:0.78rem;padding:4px 12px">Konfirmasi</button>
                      <button class="btn-ghost" data-extend-cancel="${b.id}" style="font-size:0.78rem;padding:4px 10px">Batal</button>
                    </div>
                  </div>
                </div>`;
              }

              // reservasi yg dipindah biar orang sebelumnya bisa tambah jam
              const relocatedHtml = b.relocated_from && !isCancelled && !isDone ? `
                <p class="relocated-note">Ruanganmu dipindah dari <strong>${escapeHtml(b.relocated_from)}</strong> ke <strong>${escapeHtml(b.room)}</strong>
                  (kelas, harga, dan jam sama).${b.missing_games?.length
                    ? ` Game yang tidak ada di ruangan baru: ${escapeHtml(b.missing_games.slice(0, 4).join(", "))}${b.missing_games.length > 4 ? ` +${b.missing_games.length - 4} lainnya` : ""}.`
                    : ""}</p>` : "";

              box.innerHTML += `
                <div class="booking-item">
                  <strong>${escapeHtml(b.room)}</strong>${b.with_nintendo ? ' <span class="small muted">+ Nintendo Switch</span>' : ''}${b.is_student ? ' <span class="small muted">· Harga pelajar</span>' : ''}<br>
                  <span class="small muted">${new Date(start * 1000).toLocaleDateString("id-ID", { timeZone: TZ, day: "numeric", month: "short" })} &bull; Jam: ${escapeHtml(b.time)} &bull; Durasi: ${escapeHtml(String(b.duration))} jam &bull; ${formatRup(b.total_price)}${b.discount && !isCancelled ? ` (potongan poin ${formatRup(b.discount)})` : ""}</span><br>
                  ${statusHtml}
                  ${metaHtml}
                  ${relocatedHtml}
                  ${checkinHtml}
                  ${reminderHtml}
                  ${selfHtml}
                  ${extendHtml}
                  ${cancelHtml}
                  ${reorderHtml}
                </div>
              `;
            });


            syncRedeemButtons();

            if (!window._notifiedSessions) window._notifiedSessions = new Set();

            function tick() {
              const nowTick = nowSec();
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

            // request lama bisa udah pasang interval
            if (window._bookingCountdownInterval) clearInterval(window._bookingCountdownInterval);
            tick();
            window._bookingCountdownInterval = setInterval(tick, 1000);
          })
          .catch(() => {
            box.innerHTML = '<p class="muted small">Gagal memuat riwayat booking.</p>';
          });
      }

      // pastiin isinya muncul
      const uname = document.getElementById("profileUsername");
      const sinceEl = document.getElementById("profileSince");

      fetch("profile_status.php")
        .then((r) => r.json())
        .then((d) => {
          if (d.error || !IS_LOGGED_IN) return;
          if (uname) uname.textContent = d.username || "User";
          if (sinceEl) sinceEl.textContent = d.since || "-";
          renderPoints(d.points || 0, d.points_per_hour || 10);
        })
        .catch(() => {
          if (uname) uname.textContent = "User";
        });
  }

  const profileDetailLink = document.getElementById("profileDetail");
  if (profileDetailLink) {
    profileDetailLink.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation(); // stop event global
      closeProfileDropdown();
      openProfileDetail();
    });
  }

  // ===== CARI RUANGAN KOSONG =====
  const finderForm = $("finderForm");
  function fillFinderOptions() {
    const dateSel = $("finderDate"), timeSel = $("finderTime");
    if (!dateSel) return;
    const prevDate = dateSel.value, prevTime = timeSel.value;
    dateSel.innerHTML = "";
    ["Hari ini", "Besok", "Lusa"].forEach((label, i) => {
      const noon = wibDayStart() + i * 86400 + 12 * 3600;
      const d = new Date(noon * 1000);
      dateSel.add(new Option(`${label} · ${d.toLocaleDateString("id-ID", { timeZone: TZ, day: "numeric", month: "short" })}`, wibISODate(noon)));
    });
    if ([...dateSel.options].some((o) => o.value === prevDate)) dateSel.value = prevDate;
    const dayStart = wibTs(dateSel.value);
    timeSel.innerHTML = "";
    for (let t = OPEN_SEC; t + 3600 <= CLOSE_SEC; t += SLOT_SEC) {
      if (dayStart + t <= nowSec()) continue;
      timeSel.add(new Option(secToHHMM(t), secToHHMM(t)));
    }
    if (!timeSel.options.length) timeSel.add(new Option("Tidak ada jam tersisa", ""));
    if ([...timeSel.options].some((o) => o.value === prevTime)) timeSel.value = prevTime;
  }
  fillFinderOptions();
  $("finderDate")?.addEventListener("change", fillFinderOptions);

  finderForm?.addEventListener("submit", (e) => {
    e.preventDefault();
    const out = $("finderResult");
    const q = {
      date: $("finderDate").value, time: $("finderTime").value, duration: $("finderDuration").value,
      players: $("finderPlayers").value, nintendo: $("finderNintendo").checked ? "1" : "",
    };
    if (!q.time) { out.innerHTML = `<p class="muted small">Pilih tanggal lain, jam hari ini sudah habis.</p>`; return; }
    out.innerHTML = `<p class="muted small">Mencari ruangan...</p>`;
    fetch(`available_rooms_api.php?${new URLSearchParams(q)}`)
      .then((r) => r.json())
      .then((r) => {
        if (r.status !== "ok") { out.innerHTML = `<p class="muted small">${escapeHtml(r.message || "Gagal mencari ruangan")}</p>`; return; }
        if (!r.rooms.length) {
          out.innerHTML = `<p class="muted small">Tidak ada ruangan kosong untuk jadwal ini. Coba jam lain atau ambil antrian "Main Sekarang".</p>`;
          return;
        }
        // ruangan sekelas & harga sama digabung 1 baris (cth 6 meja PS4 Slim)
        const groups = [];
        r.rooms.forEach((room) => {
          const key = [room.tier, room.console_model, room.with_nintendo, room.total, room.max_players, room.has_netflix].join("|");
          const g = groups.find((x) => x.key === key);
          if (g) g.rooms.push(room); else groups.push({ key, rooms: [room] });
        });
        const names = (list) => list.length > 3
          ? `${list.slice(0, 3).map((x) => x.title).join(", ")} +${list.length - 3} lainnya`
          : list.map((x) => x.title).join(", ");
        out.innerHTML = `<p class="small" style="margin-bottom:8px">${r.rooms.length} ruangan tersedia:</p>`
          + `<div class="finder-list">` + groups.map(({ rooms: list }) => {
            const room = list[0];
            return `
            <div class="finder-item">
              <div>
                <strong>${escapeHtml(room.tier === "Public" ? "Area Public" : room.tier)} · ${escapeHtml(room.console_model)}${room.with_nintendo ? " + Nintendo" : ""}</strong>
                <div class="muted small">${escapeHtml(names(list))} · maks ${Number(room.max_players)} pemain${room.has_netflix ? " · Netflix" : ""}</div>
              </div>
              <div class="finder-price">
                <strong>${formatRup(room.total)}</strong>
                <button type="button" class="btn" data-finder-book="${escapeHtml(JSON.stringify({ roomId: room.id, date: q.date, time: q.time, duration: Number(q.duration), nintendo: room.with_nintendo }))}">Pesan ${escapeHtml(room.title)}</button>
              </div>
            </div>`;
          }).join("") + `</div>`;
      })
      .catch(() => { out.innerHTML = `<p class="muted small">${escapeHtml(NETWORK_ERROR)}</p>`; });
  });

  document.addEventListener("click", (e) => {
    const findBtn = e.target.closest("[data-finder-book]");
    if (findBtn) prefillBooking(JSON.parse(findBtn.dataset.finderBook));

    // saran hemat paket: naik 1 jam
    const upBtn = e.target.closest("[data-upsell]");
    if (upBtn) {
      $("duration").value = upBtn.dataset.upsell;
      $("duration").dispatchEvent(new Event("change", { bubbles: true }));
    }
  });

  // ===== UBAH JADWAL SENDIRI =====
  let resched = null;          // booking yg lagi diubah
  let reschedSlots = [];       // jadwal terpesan ruangan tujuan (tanpa booking ini)
  let reschedReq = 0;
  const rs = (id) => document.getElementById(id);

  function reschedStart() {
    return rs("rescheduleTime").value ? wibTs(rs("rescheduleDate").value, rs("rescheduleTime").value) : 0;
  }
  function reschedPrice() {
    const out = rs("reschedulePrice");
    const room = roomsData.find((r) => r.id === Number(rs("rescheduleRoom").value));
    const dur = Number(rs("rescheduleDuration").value) || 0;
    const start = reschedStart();
    if (!room || !dur || !start) { out.textContent = ""; return; }
    const withN = resched.nintendo && !!room.nintendoExtra;
    const gross = bookingPrice(room, dur, start, withN);
    const total = gross - (resched.discount ? Math.min(room.price, gross) : 0);
    let text = `Total baru: ${formatRup(total)}`;
    if (resched.paid > 0) {
      text += total > resched.paid ? ` · sudah dibayar ${formatRup(resched.paid)}, sisa ${formatRup(total - resched.paid)} dibayar di kasir`
        : total < resched.paid ? ` · kelebihan ${formatRup(resched.paid - total)} dikembalikan kasir saat datang` : " · sudah lunas";
    }
    if (resched.nintendo && !withN) text += ". Tambahan Nintendo tidak tersedia di ruangan ini.";
    if (resched.student) text += ". Harga pelajar dihitung ulang sistem (hanya Senin-Jumat).";
    out.textContent = text;
  }
  function reschedDurations() {
    const sel = rs("rescheduleDuration");
    const prev = Number(sel.value) || resched.duration;
    sel.innerHTML = "";
    const start = reschedStart();
    if (start) {
      const maxH = Math.min(12, Math.floor((wibDayStart(start) + CLOSE_SEC - start) / 3600));
      for (let d = 1; d <= maxH; d++) {
        if (reschedSlots.some((s) => start < s.end_time && start + d * 3600 > s.start_time)) break;
        sel.add(new Option(`${d} jam`, d));
      }
    }
    if (!sel.options.length) sel.add(new Option("Pilih jam lain", ""));
    if ([...sel.options].some((o) => Number(o.value) === prev)) sel.value = String(prev);
    reschedPrice();
  }
  function reschedTimes() {
    const sel = rs("rescheduleTime");
    const prev = sel.value || resched.time;
    const dayStart = wibTs(rs("rescheduleDate").value);
    sel.innerHTML = "";
    for (let t = OPEN_SEC; t + 3600 <= CLOSE_SEC; t += SLOT_SEC) {
      const start = dayStart + t;
      if (start <= nowSec()) continue;
      const busy = reschedSlots.some((s) => start < s.end_time && start + 3600 > s.start_time);
      const opt = new Option(busy ? `${secToHHMM(t)} — terpesan` : secToHHMM(t), secToHHMM(t));
      opt.disabled = busy;
      sel.add(opt);
    }
    if (!sel.options.length) sel.add(new Option("Tidak ada jam tersisa", ""));
    const keep = [...sel.options].find((o) => o.value === prev && !o.disabled) || [...sel.options].find((o) => !o.disabled);
    if (keep) sel.value = keep.value;
    reschedDurations();
  }
  function reschedLoadSlots() {
    const req = ++reschedReq;
    reschedSlots = [];
    reschedTimes();
    fetch(`booked_slots_api.php?room_id=${encodeURIComponent(rs("rescheduleRoom").value)}&date=${rs("rescheduleDate").value}&exclude=${resched.id}`)
      .then((r) => r.json())
      .then((slots) => { if (req === reschedReq) { reschedSlots = slots; reschedTimes(); } })
      .catch(() => {});
  }

  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-reschedule]");
    if (btn) {
      resched = JSON.parse(btn.dataset.reschedule);
      resched.time = new Date(resched.start * 1000).toLocaleTimeString("id-ID", { timeZone: TZ, hour: "2-digit", minute: "2-digit", hour12: false }).replace(".", ":");
      rs("rescheduleBookingId").value = resched.id;
      rs("rescheduleInfo").textContent = `Jadwal sekarang: ${resched.room}, ${new Date(resched.start * 1000).toLocaleString("id-ID", { timeZone: TZ, weekday: "long", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}, ${resched.duration} jam.`;
      const roomSel = rs("rescheduleRoom");
      roomSel.innerHTML = "";
      roomsData.filter((r) => r.currentStatus !== "in_service" || r.id === resched.room_id).forEach((r) =>
        roomSel.add(new Option(`${r.title} · ${r.tier === "Public" ? "Public" : r.tier} ${r.consoleModel}`, r.id)));
      roomSel.value = String(resched.room_id);
      const dateSel = rs("rescheduleDate");
      dateSel.innerHTML = "";
      ["Hari ini", "Besok", "Lusa"].forEach((label, i) => {
        const noon = wibDayStart() + i * 86400 + 12 * 3600;
        dateSel.add(new Option(`${label} · ${new Date(noon * 1000).toLocaleDateString("id-ID", { timeZone: TZ, day: "numeric", month: "short" })}`, wibISODate(noon)));
      });
      const curDate = wibISODate(resched.start);
      if ([...dateSel.options].some((o) => o.value === curDate)) dateSel.value = curDate;
      rs("rescheduleTime").innerHTML = "";
      rs("rescheduleDuration").innerHTML = "";
      reschedLoadSlots();
      rs("rescheduleModal").classList.add("show");
    }

    // tukar / batal tukar poin
    const rd = e.target.closest("[data-redeem]");
    if (rd) {
      const undo = !!rd.dataset.undo;
      if (!confirm(undo ? "Batalkan penukaran poin? Harga kembali normal dan 10 poin dikembalikan."
                        : "Pakai 10 poin untuk gratis 1 jam di booking ini? Total tagihan langsung berkurang.")) return;
      rd.disabled = true;
      fetch("redeem_points.php", { method: "POST", body: new URLSearchParams({ booking_id: rd.dataset.redeem }) })
        .then((r) => r.json())
        .then((r) => {
          if (r.status !== "ok") { alert(r.message || "Gagal memproses poin"); return; }
          alert(r.redeemed ? "Poin terpakai: tagihan booking ini sudah dipotong 1 jam." : "Penukaran dibatalkan, poin dikembalikan.");
          openProfileDetail(true);
        })
        .catch(() => alert(NETWORK_ERROR))
        .finally(() => { rd.disabled = false; });
    }
  });
  rs("rescheduleRoom")?.addEventListener("change", reschedLoadSlots);
  rs("rescheduleDate")?.addEventListener("change", reschedLoadSlots);
  rs("rescheduleTime")?.addEventListener("change", reschedDurations);
  rs("rescheduleDuration")?.addEventListener("change", reschedPrice);
  const closeReschedule = () => rs("rescheduleModal").classList.remove("show");
  rs("closeReschedule")?.addEventListener("click", closeReschedule);
  document.querySelector("#rescheduleModal .modal-overlay")?.addEventListener("click", closeReschedule);
  rs("rescheduleSubmit")?.addEventListener("click", () => {
    if (!rs("rescheduleTime").value || !rs("rescheduleDuration").value) { alert("Pilih jam dan durasi yang masih tersedia."); return; }
    const btn = rs("rescheduleSubmit");
    btn.disabled = true;
    fetch("reschedule_booking.php", {
      method: "POST",
      body: new URLSearchParams({
        booking_id: resched.id, room_id: rs("rescheduleRoom").value, date: rs("rescheduleDate").value,
        time: rs("rescheduleTime").value, duration: rs("rescheduleDuration").value,
      }),
    })
      .then((r) => r.json())
      .then((r) => {
        if (r.status !== "ok") { alert(r.message || "Gagal mengubah jadwal"); return; }
        const fmt = (u) => new Date(u * 1000).toLocaleString("id-ID", { timeZone: TZ, weekday: "long", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
        let msg = `Jadwal berhasil diubah: ${r.room}, ${fmt(r.start_time)} (${r.duration} jam). Total ${formatRup(r.total_price)}.`;
        if (r.due > 0 && r.refund === 0 && resched.paid > 0) msg += ` Sisa ${formatRup(r.due)} dibayar di kasir.`;
        if (r.refund > 0) msg += ` Kelebihan ${formatRup(r.refund)} dikembalikan kasir saat kamu datang.`;
        if (r.nintendo_dropped) msg += " Tambahan Nintendo dilepas karena tidak tersedia di ruangan baru.";
        if (r.student_dropped) msg += " Harga pelajar dilepas karena jadwal baru jatuh di akhir pekan.";
        alert(msg);
        closeReschedule();
        openProfileDetail(true);
        window.loadUpcomingBooking?.();
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { btn.disabled = false; });
  });

  // ===== ULASAN =====
  let reviewRating = 0;
  const setStars = (n) => {
    reviewRating = n;
    document.querySelectorAll("#reviewModal .star-btn").forEach((b) => {
      const on = Number(b.dataset.star) <= n;
      b.classList.toggle("on", on);
      b.setAttribute("aria-checked", Number(b.dataset.star) === n ? "true" : "false");
    });
  };
  document.addEventListener("click", (e) => {
    const rBtn = e.target.closest("[data-review]");
    if (rBtn) {
      $("reviewBookingId").value = rBtn.dataset.review;
      $("reviewRoom").textContent = rBtn.dataset.room;
      $("reviewComment").value = "";
      setStars(0);
      $("reviewModal").classList.add("show");
    }
    const star = e.target.closest("#reviewModal .star-btn");
    if (star) setStars(Number(star.dataset.star));
  });
  const closeReview = () => $("reviewModal").classList.remove("show");
  $("closeReview")?.addEventListener("click", closeReview);
  document.querySelector("#reviewModal .modal-overlay")?.addEventListener("click", closeReview);
  $("reviewSubmit")?.addEventListener("click", () => {
    if (!reviewRating) { alert("Pilih jumlah bintang dulu."); return; }
    const btn = $("reviewSubmit");
    btn.disabled = true;
    fetch("review_api.php", {
      method: "POST",
      body: new URLSearchParams({ booking_id: $("reviewBookingId").value, rating: reviewRating, comment: $("reviewComment").value.trim() }),
    })
      .then((r) => r.json())
      .then((r) => {
        if (r.status !== "ok") { alert(r.message || "Gagal mengirim ulasan"); return; }
        closeReview();
        openProfileDetail(true);
        loadRoomsFromDB();
        const maps = document.body.dataset.maps;
        if (reviewRating >= 4 && maps && confirm("Terima kasih atas ulasannya! Mau bantu kami juga dengan memberi rating di Google?")) {
          window.open(maps, "_blank", "noopener");
        } else if (reviewRating < 4) {
          alert("Terima kasih atas ulasannya! Masukanmu membantu kami jadi lebih baik.");
        }
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { btn.disabled = false; });
  });

  showView("home");
});

// ===== POIN LOYALITAS =====
// tombol Pakai 10 Poin cuma kalo saldo cukup
let userPoints = 0, pointsPerHour = 10;
function syncRedeemButtons() {
  document.querySelectorAll("[data-need-points]").forEach((b) => { b.hidden = userPoints < pointsPerHour; });
}
function renderPoints(points, perHour) {
  userPoints = points;
  pointsPerHour = perHour;
  syncRedeemButtons();
  const el = document.getElementById("profilePoints");
  if (!el) return;
  el.textContent = `${points} poin`;
  const progress = points % perHour;
  document.getElementById("profilePointsBar").style.width = `${(progress / perHour) * 100}%`;
  const free = Math.floor(points / perHour);
  document.getElementById("profilePointsHint").textContent = free > 0
    ? `Kamu bisa tukar ${free} jam gratis. Bilang ke kasir sebelum membayar. (${perHour - progress} poin lagi untuk jam gratis berikutnya)`
    : `${perHour - progress} poin lagi untuk gratis 1 jam. Dapat 1 poin setiap 1 jam main yang lunas.`;
}

// jam mulai tiap 30 menit dari buka sampai 1 jam sebelum tutup
const timeSelect = document.getElementById("timeStart");
const durationSelect = document.getElementById("duration");

// tanggal hari ini WIB, bukan tanggal device
function getTodayISO() {
  return wibISODate();
}

function generateDateOptions() {
  const sel = document.getElementById("bookingDate");
  if (!sel) return;
  sel.innerHTML = "";
  const labels = ["Hari ini", "Besok", "Lusa"];
  for (let i = 0; i < 3; i++) {
    // jam 12:00 WIB hari ke-i biar aman dari geser zona
    const noon = wibDayStart() + i * 86400 + 12 * 3600;
    const d = new Date(noon * 1000);
    const iso = wibISODate(noon);
    const opt = document.createElement("option");
    opt.value = iso;
    opt.textContent = `${labels[i]} · ${d.toLocaleDateString("id-ID", { timeZone: TZ, day: "numeric", month: "short" })}`;
    sel.appendChild(opt);
  }
}

function generateTimeOptions() {
  const dateSel = document.getElementById("bookingDate");
  const selectedDate = dateSel?.value || getTodayISO();
  const dayStart = wibTs(selectedDate);

  timeSelect.innerHTML = "";
  let firstAvailable = null;
  for (let t = OPEN_SEC; t + 3600 <= CLOSE_SEC; t += SLOT_SEC) {
    // jam yg udah lewat gak ditawarin (server juga nolak)
    if (dayStart + t <= nowSec()) continue;
    const hhmm = secToHHMM(t);
    const opt = document.createElement("option");
    opt.value = hhmm;
    opt.textContent = hhmm;
    timeSelect.appendChild(opt);
    if (!firstAvailable) firstAvailable = hhmm;
  }

  if (firstAvailable) {
    timeSelect.value = firstAvailable;
    timeSelect.disabled = false;
  } else {
    // udah lewat jam operasional buat tanggal ini
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

  const maxDuration = 12; // sesuai batas server
  const start = slotStartTs(timeSelect.value);
  // sesi harus selesai sebelum tutup
  const closeTs = wibDayStart(start) + CLOSE_SEC;
  const remaining = Math.min(Math.floor((closeTs - start) / 3600), maxDuration);

  for (let i = 1; i <= remaining; i++) {
    // durasi yg nabrak booking berikutnya gak ditawarin
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
  // ringkasan diupdate listener change di DOMContentLoaded
});

// halaman kebuka lama: tanggal basi lewat tengah malam & jam lewat masih bisa dipilih, cek tiap menit
function refreshStaleDateOptions() {
  const sel = document.getElementById("bookingDate");
  if (!sel) return;
  const prevDate = sel.value;
  const dateStale = sel.options[0]?.value !== getTodayISO();
  const timeStale = prevDate === getTodayISO() && timeSelect.value
    && slotStartTs(timeSelect.value) <= nowSec();
  if (!dateStale && !timeStale) return;
  generateDateOptions();
  if ([...sel.options].some((o) => o.value === prevDate)) sel.value = prevDate;
  sel.dispatchEvent(new Event("change"));
}
setInterval(refreshStaleDateOptions, 60000);

// ===== SLOT TERPESAN =====

// nomor request terakhir, respons lama diabaikan
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
      applyPendingPrefill();
      if (infoEl) {
        if (data.length === 0) {
          infoEl.textContent = "";
        } else {
          const ranges = data.map((s) => {
            const fmt = (unix) =>
              new Date(unix * 1000).toLocaleTimeString("id-ID", { timeZone: TZ,
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

// timestamp jam HH:MM di tanggal booking
function slotStartTs(timeVal) {
  const date = document.getElementById("bookingDate")?.value || getTodayISO();
  return wibTs(date, timeVal);
}

// sama kayak cek bentrok server: start < endLain && end > startLain
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
    // jam berubah: trigger change biar durasi & ringkasan ikut update
    timeSelect.dispatchEvent(new Event("change", { bubbles: true }));
  } else {
    // jam sama: hitung ulang durasi, pilihan user dipertahanin kalo masih valid
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

function showView(v, keepScroll = false) {
  Object.values(views).forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.style.display = "none";
  });

  const target = document.getElementById(views[v]);
  if (target) target.style.display = "block";

  if (v === "booking") refreshQueue();
  if (v === "consoles" || v === "home") loadCardRundowns();
  setActiveNav(v);

  // reset scroll (kecuali reload halaman yg sama)
  if (!keepScroll) window.scrollTo({ top: 0, behavior: "smooth" });
}

function setActiveNav(view) {
  document.querySelectorAll(".nav a").forEach((a) => {
    a.classList.toggle("active", a.dataset.view === view);
  });
}

// ===== ANTRIAN (MAIN SEKARANG) =====
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
    syncQueueNintendo();
    // tadinya nunggu terus hilang tanpa dibatalin sendiri: kasih tau user
    if (wasWaiting) {
      statusBox.style.display = "";
      cancelBtn.style.display = "none";
      text.innerHTML = `${icon('alert')} Antrianmu sudah tidak aktif (dibatalkan kasir atau melewati jam tutup). Silakan ambil antrian lagi bila perlu.`;
    } else {
      statusBox.style.display = "none";
    }
    return;
  }
  const fmt = (unix) => new Date(unix * 1000).toLocaleTimeString("id-ID", { timeZone: TZ, hour: "2-digit", minute: "2-digit" });
  const ended = entry.state === "expired" || entry.state === "cancelled";
  // giliran udah batal, boleh antri lagi
  joinBox.style.display = ended ? "" : "none";
  syncQueueNintendo();
  statusBox.style.display = "";
  // giliran yg udah didapat tapi belum lapor juga boleh dibatalin
  const canCancelTurn = entry.state === "assigned" && !!entry.checkin_until && entry.payment_status !== "paid";
  cancelBtn.style.display = entry.state === "waiting" || canCancelTurn ? "" : "none";
  cancelBtn.textContent = canCancelTurn ? "Batalkan Giliran" : "Batalkan Antrian";

  if (entry.state === "waiting") {
    text.innerHTML = `Antrian <strong>${escapeHtml(entry.room_class)}</strong>${entry.with_nintendo ? " + Nintendo" : ""} · ${Number(entry.duration)} jam — posisi kamu: <strong>#${Number(entry.position)}</strong>`
      + (entry.estimate
          ? `<br><span class="small muted">${entry.estimate <= nowSec() + 60
              ? "Perkiraan: ruangan segera tersedia"
              : `Perkiraan giliran sekitar jam <strong>${new Date(entry.estimate * 1000).toLocaleTimeString("id-ID", { timeZone: TZ, hour: "2-digit", minute: "2-digit" })}</strong> (bisa lebih cepat atau lambat)`}</span>`
          : "");
  } else if (entry.state === "expired") {
    text.innerHTML = `${icon('alert')} Giliranmu di <strong>${escapeHtml(entry.room)}</strong> dibatalkan karena tidak datang dalam 15 menit.`;
  } else if (entry.state === "cancelled") {
    text.innerHTML = `${icon('alert')} Booking dari antrian di <strong>${escapeHtml(entry.room)}</strong> dibatalkan kasir.`;
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
    .then((res) => { if (IS_LOGGED_IN && res.status === "ok") renderQueueEntry(res.entry); })
    .catch(() => {});
}

function postQueue(params) {
  return fetch("queue_api.php", { method: "POST", body: new URLSearchParams(params) })
    .then((r) => r.json());
}

// opsi Nintendo cuma buat kelas yg ada Nintendo & pas form antrian keliatan
function syncQueueNintendo() {
  const field = $("queueNintendoField");
  const opt = $("queueClass")?.selectedOptions[0];
  if (!field || !opt) return;
  const extra = Number(opt.dataset.nintendo) || 0;
  const visible = !!extra && $("queueJoinBox").style.display !== "none";
  field.style.display = visible ? "" : "none";
  if (!extra) $("queueNintendo").checked = false;
  else $("queueNintendoExtra").textContent = `(+Rp ${extra.toLocaleString("id-ID")}/jam)`;
}

(function initQueueForm() {
  const durSel = $("queueDuration");
  $("queueClass")?.addEventListener("change", syncQueueNintendo);
  syncQueueNintendo();
  // durasi maks ikut sisa waktu sampai tutup (sama kayak server)
  const fillDurations = () => {
    if (!durSel) return;
    const maxH = Math.min(12, Math.floor((wibDayStart() + CLOSE_SEC - nowSec()) / 3600));
    const prev = Number(durSel.value) || 1;
    durSel.innerHTML = "";
    if (maxH < 1) {
      durSel.add(new Option("Sudah mendekati jam tutup", ""));
      durSel.disabled = true;
      return;
    }
    durSel.disabled = false;
    for (let i = 1; i <= maxH; i++) durSel.add(new Option(`${i} jam`, i));
    durSel.value = String(Math.min(prev, maxH));
  };
  fillDurations();
  durSel?.addEventListener("focus", fillDurations);

  $("queueJoinBtn")?.addEventListener("click", () => {
    if (!IS_LOGGED_IN) {
      requireLogin("Silakan login terlebih dahulu untuk mengambil antrian.", "booking");
      return;
    }
    const btn = $("queueJoinBtn");
    btn.disabled = true;
    postQueue({ action: "join", room_class: $("queueClass").value, duration: durSel.value,
                nintendo: $("queueNintendo")?.checked ? "1" : "" })
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
        else queueWaiting = false; // dibatalin sendiri, jangan tampil dibatalkan kasir
        refreshQueue();
        window.loadUpcomingBooking?.();
      })
      .catch(() => alert(NETWORK_ERROR))
      .finally(() => { btn.disabled = false; });
  });

  // polling: pas di halaman booking atau selama nunggu giliran
  setInterval(() => {
    const onBooking = $("view-booking")?.style.display === "block";
    if (IS_LOGGED_IN && (onBooking || queueWaiting)) refreshQueue();
  }, 15000);

  document.addEventListener("DOMContentLoaded", refreshQueue);
})();

// ===== RUNDOWN DI KARTU RUANGAN =====
// jadwal hari ini per ruangan dari schedule_api.php (publik), kiri jam terisi, kanan status
let rundownCache = null;

function rdTime(ts) {
  return new Date(ts * 1000).toLocaleTimeString("id-ID", { timeZone: TZ, hour: "2-digit", minute: "2-digit" });
}

function loadCardRundowns() {
  fetch("schedule_api.php", { cache: "no-store" })
    .then((r) => r.json())
    .then((r) => {
      if (r.status !== "ok") return;
      syncServerClock(r.now);
      rundownCache = r;
      document.querySelectorAll("[data-rd-card]").forEach(fillCardRundown);
    })
    .catch(() => {});
}

function fillCardRundown(el) {
  const d = rundownCache;
  if (!d || !el) return;
  const id = Number(el.dataset.rdCard);
  const room = d.rooms.find((r) => r.id === id);
  const slots = d.slots.filter((s) => s.room_id === id);
  const now = d.now;
  const cur = slots.find((s) => s.start <= now && s.end > now);
  const later = slots.filter((s) => s.start > now);
  let time = "--:--", status = "Kosong", cls = "rr-free";

  if (room?.maintenance) { status = "Perawatan"; cls = "rr-off"; }
  else if (now >= d.close) { status = "Tutup"; cls = "rr-off"; }
  else if (now < d.open) {
    // sebelum jam buka: tampilin jadwal pertama hari ini (kalo ada)
    status = "Belum buka"; cls = "rr-off";
    if (later.length) time = `${rdTime(later[0].start)}–${rdTime(later[0].end)}`;
  }
  else if (cur) {
    time = `${rdTime(cur.start)}–${rdTime(cur.end)}`;
    status = cur.playing ? "Dipakai" : "Dipesan";
    cls = cur.playing ? "rr-playing" : "rr-booked";
  } else if (later.length) {
    // kosong sekarang, tampilin jadwal terdekat
    time = `${rdTime(later[0].start)}–${rdTime(later[0].end)}`;
  }
  const more = (cur ? later.length : later.length - 1);
  el.querySelector(".rr-time").textContent = time + (more > 0 ? ` +${more}` : "");
  el.querySelector(".rr-time").title = slots.length
    ? "Jadwal hari ini: " + slots.map((s) => `${rdTime(s.start)}–${rdTime(s.end)}`).join(", ")
    : "Belum ada jadwal hari ini";
  const st = el.querySelector(".rr-status");
  st.textContent = status;
  st.className = `rr-status ${cls}`;
}

document.addEventListener("DOMContentLoaded", loadCardRundowns);
// update tiap menit selama Beranda / Ruangan kebuka
setInterval(() => {
  const open = ["view-home", "view-consoles"].some((v) => $(v)?.style.display !== "none");
  if (open && !document.hidden) loadCardRundowns();
}, 60000);
