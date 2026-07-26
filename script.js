// view handler
const $ = (id) => document.getElementById(id);

// Escape data sebelum dipasang lewat innerHTML, supaya judul/deskripsi/catatan
// yang berisi karakter HTML tidak bisa mengeksekusi script (XSS).
function escapeHtml(str) {
  return String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  }[c]));
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
}

window.addEventListener("pageshow", clearNoFillFields);
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
      IS_LOGGED_IN = false;
      setLoggedOut();
    });

  // rooms data (images provided via example URLs; ganti jika perlu)
  let roomsData = [];

  // games data
  let gamesData = [];

  function formatRup(n) {
    return (
      "Rp " + (Number(n) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".")
    );
  }

  // create room card (with image + buttons; "Detail" is grayish/green as requested earlier)
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
    media.style.backgroundImage = `url("${room.img}")`;

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
              <img src="${cover}" alt="${escapeHtml(g.title)}" onerror="this.src='assets/images/games/default.jpg'">
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
      <img src="${room.img}" alt="${escapeHtml(room.title)}" style="width:100%;height:300px;object-fit:cover;border-radius:10px;margin-bottom:12px">
      <p class="muted">${escapeHtml(room.desc)}</p>
      <p style="margin-top:6px"><strong>${formatRup(room.price)}/jam</strong></p>
      <div style="margin-top:12px;display:flex;gap:8px">
        ${bookBtnHtml}
        <button class="btn-ghost" data-action="back-to-rooms">Kembali ke list</button>
      </div>
      <div style="margin-top:24px">
        <h3 style="color:var(--neon);margin-bottom:8px">Ketersediaan Hari Ini</h3>
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
        for (let h = 11; h <= 23; h++) {
          const booked = slots.some((s) => {
            const sh = new Date(s.start_time * 1000).getHours();
            const eh = new Date(s.end_time   * 1000).getHours();
            return h >= sh && h < eh;
          });
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

  // bookings storage (with countdown endTime)

  function populateSelect() {
    const sel = $("roomSelect");
    sel.innerHTML = '<option value="" disabled selected>-- Pilih Ruangan --</option>';
    roomsData.forEach((r) => {
      const op = document.createElement("option");
      op.value = r.id;
      const st = statusConfig[r.currentStatus] || statusConfig.available;
      op.textContent = r.currentStatus === "occupied"
        ? `${r.title} — ${r.consoleType} [Sedang Terisi]`
        : `${r.title} — ${r.consoleType} [${st.label}]`;
      if (r.currentStatus === "in_service") {
        op.disabled = true;
        op.style.color = "#555e6b";
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
      if (welcome) welcome.textContent = `Halo, ${window.AUTH.username}! Lengkapi detail booking di bawah.`;
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

  // Update summary setiap kali room/durasi/waktu berubah
  document.addEventListener("change", (e) => {
    if (e.target.id === "roomSelect" || e.target.id === "duration" || e.target.id === "timeStart") {
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
        .map((p) => `<span class="badge badge-${p.toLowerCase()}">${p}</span>`)
        .join(" ");

      const cover = g.image ? `assets/images/games/${g.image}` : `assets/images/games/default.jpg`;
      const coverHtml = `<img class="game-cover-img" src="${cover}" alt="${escapeHtml(g.title)}" onerror="this.src='assets/images/games/default.jpg'">`;

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
    // 🔥 1. DETAIL KAMAR (PRIORITAS UTAMA)
    const detailBtn = e.target.closest("[data-detail]");
    if (detailBtn) {
      e.preventDefault();
      showDetail(Number(detailBtn.dataset.detail));
      return;
    }

    // 🔥 2. BOOKING DARI DETAIL / CARD
    const bookBtn = e.target.closest("[data-book]");
    if (bookBtn) {
      e.preventDefault();

      if (!IS_LOGGED_IN) {
        alert("Silakan login terlebih dahulu untuk booking.");
        document.getElementById("loginModal").classList.add("show");
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

    // ⛔ JANGAN TUTUP DROPDOWN SAAT KLIK DI DALAMNYA
    if (e.target.closest("#profileToggle")) {
      return;
    }

    // 🔥 1. JANGAN SENTUH KLIK DI DALAM MODAL (LOGIN / REGISTER)
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
          alert("Silakan login terlebih dahulu untuk melakukan booking.");
          document.getElementById("loginModal").classList.add("show");
          return;
        }

        populateSelect();
        generateDateOptions();
        generateTimeOptions();
        updateDurationOptions();
        prefillBookingUser();
        updateBookingSummary();
        const selVal = $("roomSelect")?.value;
        if (selVal) fetchBookedSlots(selVal);
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
    bookedSlots = [];
    const infoEl = document.getElementById("bookedSlotsInfo");
    if (infoEl) infoEl.textContent = "";
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

    // 🔒 BLOKIR PALING AWAL (SEBELUM KODE LAIN JALAN)
    if (!IS_LOGGED_IN) {
      alert("Anda harus login untuk melakukan booking.");
      document.getElementById("loginModal").classList.add("show");
      return; // ⛔ HENTIKAN FUNGSI DI SINI
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
      alert("Lengkapi data!");
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
      }),
    })
      .then((r) => r.json())
      .then((r) => {
        if (r.status === "success") {
          alert("Booking BERHASIL!");
          loadUpcomingBooking();
          $("bookingForm").reset();
          generateDateOptions();
          generateTimeOptions();
          updateDurationOptions();
          updateBookingSummary();
        } else {
          alert("ERROR: " + r.message);
        }
      })
      .catch(() => alert("SERVER ERROR"))
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
      showView("game");
      return;
    }
    alert("Tidak ditemukan hasil untuk: " + q);
  });

  // initial
  renderPreview();
  populateSelect();
  renderGames();

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

  function formatRupMenu(n) {
    return "Rp " + (Number(n) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
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
        ? `<button class="btn menu-order-btn" data-id="${item.id}" data-name="${escapeHtml(item.name)}" data-price="${item.price}">Pesan</button>`
        : `<button class="btn btn-disabled" disabled>Stok Habis</button>`;

      card.innerHTML = `
        <div class="menu-img-wrap">
          <img src="${imgSrc}" alt="${escapeHtml(item.name)}" onerror="this.src='assets/images/games/default.jpg'">
          ${stockBadge}
        </div>
        <div class="room-body">
          <h3 style="color:var(--neon)">${escapeHtml(item.name)}</h3>
          <p class="muted small">${escapeHtml(item.description || '')}</p>
          <div class="room-meta" style="margin-top:10px">
            <strong>${formatRupMenu(item.price)}</strong>
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
          const total = formatRupMenu(o.price * o.quantity);
          const statusCls = o.status === 'selesai' ? 'order-done' : 'order-pending';
          const statusLabel = o.status === 'selesai' ? '✓ Sudah Sampai' : '⏳ Diproses';
          const tgl = new Date(o.created_at).toLocaleString('id-ID', {
            day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
          });
          const note = o.note ? `<p class="muted small">Catatan: ${escapeHtml(o.note)}</p>` : '';
          return `
            <div class="menu-order-item" data-id="${o.id}" data-status="${o.status}">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                <div>
                  <strong>${escapeHtml(o.name)}</strong>
                  <p class="muted small" style="text-transform:capitalize">${escapeHtml(o.category)} · ${o.quantity}x · ${total}</p>
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
    notif.textContent = `🍜 "${itemName}" sudah sampai!`;
    document.body.appendChild(notif);
    requestAnimationFrame(() => notif.classList.add("show"));
    setTimeout(() => {
      notif.classList.remove("show");
      setTimeout(() => notif.remove(), 400);
    }, 4000);
  }

  function loadUpcomingBooking() {
    const banner = document.getElementById("upcomingBanner");
    const detail = document.getElementById("upcomingDetail");
    if (!banner || !IS_LOGGED_IN) return;

    fetch("upcoming_booking_api.php")
      .then((r) => r.json())
      .then((data) => {
        if (!data) { banner.style.display = "none"; return; }
        const time = new Date(data.start_time * 1000).toLocaleTimeString("id-ID", {
          hour: "2-digit", minute: "2-digit",
        });
        if (detail) detail.textContent = `${data.room} — Jam ${time} (${data.duration} jam)`;
        banner.style.display = "block";
      })
      .catch(() => { banner.style.display = "none"; });
  }

  function showSessionWarning(roomName, minutes) {
    const notif = document.createElement("div");
    notif.className = "order-notif session-warning";
    notif.innerHTML = `⚠️ Sesi <strong>${escapeHtml(roomName)}</strong> tersisa ${minutes} menit!`;
    document.body.appendChild(notif);
    requestAnimationFrame(() => notif.classList.add("show"));
    setTimeout(() => {
      notif.classList.remove("show");
      setTimeout(() => notif.remove(), 400);
    }, 8000);
  }

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
            document.getElementById("profileDetail")?.click();
          } else {
            alert("Gagal: " + res.message);
            confirmBtn.disabled = false;
            confirmBtn.textContent = "Konfirmasi";
          }
        })
        .catch(() => {
          alert("Server error");
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
          alert("Server error");
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
    $("orderItemPrice").textContent = formatRupMenu(btn.dataset.price) + " / item";
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
      .catch(() => alert("Server error"))
      .finally(() => { btn.disabled = false; });
  });

  // ===== LOGIN MODAL =====
  const loginLink = document.querySelector('[data-view="login"]');
  const loginModal = document.getElementById("loginModal");
  const closeLogin = document.getElementById("closeLogin");

  loginLink?.addEventListener("click", (e) => {
    e.preventDefault();
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
      .catch(() => alert("Terjadi kesalahan jaringan. Coba lagi."))
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
        } else if (res.status === "not_found") {
          alert(res.message);
          document.getElementById("loginModal").classList.remove("show");
          document.getElementById("registerModal").classList.add("show");
        } else {
          alert(res.message);
        }
      })
      .catch(() => alert("Server error"))
      .finally(() => { if (submitBtn) submitBtn.disabled = false; });
  });

  // ===== REGISTER (FIX FINAL) =====
  const registerForm = document.getElementById("registerForm");

  if (registerForm) {
    registerForm.addEventListener(
      "submit",
      (e) => {
        e.preventDefault();
        e.stopPropagation(); // ⛔ ini KUNCI

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
              alert("ERROR: " + r.message);
            }
          })
          .catch(() => alert("SERVER ERROR"))
          .finally(() => { if (submitBtn) submitBtn.disabled = false; });
      },
      true, // 🔥 INI WAJIB ADA
    );
  }

  /* ===== AUTH UI ===== */
  function setLoggedIn(username) {
    document.getElementById("authArea").style.display = "none";
    document.getElementById("profileMenu").style.display = "block";
    document.getElementById("profileName").textContent = username;
    document.body.classList.add("logged-in");
    const menuLink = document.getElementById("menuNavLink");
    if (menuLink) menuLink.style.display = "block";
    const userMenu = document.querySelector(".user-menu-name");
    if (userMenu) userMenu.textContent = username;
    loadUpcomingBooking();
  }

  function setLoggedOut() {
    document.getElementById("authArea").style.display = "block";
    document.getElementById("profileMenu").style.display = "none";
    document.body.classList.remove("logged-in");
    const menuLink = document.getElementById("menuNavLink");
    if (menuLink) menuLink.style.display = "none";

    window.AUTH = null;
    window._notifiedSessions = new Set();
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

    bookedSlots = [];
    const infoEl = document.getElementById("bookedSlotsInfo");
    if (infoEl) infoEl.textContent = "";

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
    const bookingsContainer = document.getElementById("bookingsContainer");
    if (bookingsContainer) bookingsContainer.innerHTML = "";
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

    showView("home");
    setActiveNav("home");
  }

  // 🔥 UPDATE ACTIVITY SEKETIKA
  fetch("update_activity.php");

  /* LOGOUT */
  function doLogout() {
    if (!confirm("Yakin mau logout?")) return;
    IS_LOGGED_IN = false;
    setLoggedOut();
    resetLoginForm();
    showView("home");
    setActiveNav("home");
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

  // SEHABIS LOGIN/LOGOUT KEMBALI HAL UTAMA
  function setActiveNav(view) {
    document.querySelectorAll(".nav a").forEach((a) => {
      a.classList.remove("active");
    });

    const activeLink = document.querySelector(`.nav a[data-view="${view}"]`);
    if (activeLink) activeLink.classList.add("active");
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
              .filter(b => parseInt(b.end_time) <= now)
              .reduce((sum, b) => sum + parseInt(b.duration), 0);
            const totalEl = document.getElementById("profileTotalHours");
            if (totalEl) totalEl.textContent = totalHours + " jam";

            data.forEach((b) => {
              const start = parseInt(b.start_time);
              const end   = parseInt(b.end_time);
              const isActive   = now >= start && now < end;
              const isUpcoming = now < start;
              const isDone     = now >= end;

              let statusHtml;
              if (isUpcoming) {
                statusHtml = `<span class="booking-status upcoming">Menunggu mulai</span>`;
              } else if (isDone) {
                statusHtml = `<span class="booking-status done">Selesai</span>`;
              } else {
                statusHtml = `<span class="booking-status active" id="status-${b.id}">Sisa: <span id="countdown-${b.id}">--:--:--</span></span>`;
              }

              const cancelHtml = isUpcoming ? `
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

              const extendHtml = isActive ? `
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
                  <span class="small muted">Jam: ${escapeHtml(b.time)} &bull; Durasi: ${escapeHtml(String(b.duration))} jam</span><br>
                  ${statusHtml}
                  ${extendHtml}
                  ${cancelHtml}
                  ${reorderHtml}
                </div>
              `;
            });

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
      e.stopPropagation(); // ⛔ STOP SEMUA EVENT GLOBAL
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
    opt.textContent = labels[i];
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

  for (let i = 1; i <= remaining; i++) {
    const opt = document.createElement("option");
    opt.value = i;
    opt.textContent = `${i} jam`;
    durationSelect.appendChild(opt);
  }

  // 🔥 WAJIB: pilih default durasi
  if (durationSelect.options.length > 0) {
    durationSelect.value = durationSelect.options[0].value;
  }
}

generateDateOptions();
generateTimeOptions();
updateDurationOptions();
timeSelect.addEventListener("change", updateDurationOptions);

document.getElementById("bookingDate")?.addEventListener("change", () => {
  generateTimeOptions();
  updateDurationOptions();
  const roomId = document.getElementById("roomSelect")?.value;
  if (roomId) fetchBookedSlots(roomId);
  updateBookingSummary();
});

// ===== BOOKED SLOTS =====
let bookedSlots = [];

function fetchBookedSlots(roomId) {
  const infoEl = document.getElementById("bookedSlotsInfo");
  const date = document.getElementById("bookingDate")?.value || getTodayISO();
  if (!roomId) {
    bookedSlots = [];
    applyBookedSlots();
    return;
  }
  fetch(`booked_slots_api.php?room_id=${roomId}&date=${date}`)
    .then((r) => r.json())
    .then((data) => {
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
      bookedSlots = [];
      applyBookedSlots();
    });
}

function isHourBlocked(hour) {
  return bookedSlots.some((s) => {
    const startH = new Date(s.start_time * 1000).getHours();
    const endH   = new Date(s.end_time   * 1000).getHours();
    return hour >= startH && hour < endH;
  });
}

function applyBookedSlots() {
  if (!timeSelect) return;
  const currentVal = timeSelect.value;
  Array.from(timeSelect.options).forEach((opt) => {
    const h = parseInt(opt.value.split(":")[0]);
    if (isHourBlocked(h)) {
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
  updateDurationOptions();
}

document.getElementById("roomSelect")?.addEventListener("change", (e) => {
  fetchBookedSlots(e.target.value);
});

// PROFILE DETAIL PAGE FUNCTION
function showView(v) {
  Object.values(views).forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.style.display = "none";
  });

  const target = document.getElementById(views[v]);
  if (target) target.style.display = "block";

  // 🔥 reset scroll & state
  window.scrollTo({ top: 0, behavior: "smooth" });
}

