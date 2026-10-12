// =====================
// AKSI ADMIN
// =====================
// true selama ada request aksi berjalan, supaya auto-refresh tidak memotongnya
let adminBusy = false;

// Semua POST ke admin_api.php membawa token CSRF dari <meta name="csrf-token">
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || "";
function adminFetch(opts) {
  opts.body.append("csrf", CSRF_TOKEN);
  return fetch("admin_api.php", opts);
}

function postAdmin(params) {
  return adminFetch({
    method: "POST",
    body: new URLSearchParams(params),
  }).then((r) => r.json());
}

// Kirim aksi; reload kalau sukses, tampilkan pesan dari server kalau gagal
function adminAction(params, confirmMsg) {
  if (confirmMsg && !confirm(confirmMsg)) return;
  adminBusy = true;
  postAdmin(params)
    .then((r) => {
      if (r.status === "ok") {
        location.reload();
      } else if (r.status === "unauthorized") {
        alert(r.message || "Sesi admin habis, silakan login ulang");
        location.reload();
      } else {
        alert("Gagal: " + (r.message || "Terjadi kesalahan"));
      }
    })
    .catch(() => alert("Server error"))
    .finally(() => { adminBusy = false; });
}

function cancelBooking(id, name, amountPaid = 0) {
  const refund = amountPaid > 0
    ? `\n\nPERHATIAN: konsumen sudah membayar ${rupiah(amountPaid)}. Uang perlu dikembalikan manual.`
    : "";
  adminAction({ action: "cancel_booking", id },
    `Batalkan booking "${name}"? Data tetap tersimpan di riwayat.${refund}`);
}

function finishBooking(id, due = 0) {
  const unpaid = due > 0
    ? `\n\nPERHATIAN: tagihan ${rupiah(due)} belum dibayar. Tagih dulu lalu klik Lunas.`
    : "";
  adminAction({ action: "finish_booking", id },
    `Selesaikan sesi ini sekarang? Ruangan akan diberikan ke konsumen berikutnya di daftar tunggu.${unpaid}`);
}

function releaseNoShow(id, name) {
  adminAction({ action: "release_noshow", id },
    `"${name}" tidak datang sampai batas check-in. Lepas ruangan sekarang?\n\nPembayaran tetap tercatat dan booking ditandai tidak hadir.`);
}

function checkinBooking(id, name) {
  adminAction({ action: "checkin_booking", id }, `Konfirmasi "${name}" sudah datang?`);
}

function cancelQueue(id, name) {
  adminAction({ action: "queue_cancel", id }, `Hapus "${name}" dari daftar tunggu?`);
}

function deleteGame(id, title) {
  adminAction({ action: "delete_game", id }, `Hapus game "${title}"?`);
}

function markOrderDone(id) {
  adminAction({ action: "mark_order_done", id }, "Tandai pesanan ini sudah sampai ke user?");
}

function cancelMenuOrder(id, itemName) {
  adminAction({ action: "cancel_menu_order", id }, `Batalkan pesanan "${itemName}"? Pesanan tidak akan diantar.`);
}

// Buat password sementara untuk konsumen yang lupa password lama
function resetUserPassword(id, username) {
  if (!confirm(`Reset password akun "${username}"? Password lama tidak berlaku lagi dan akun keluar dari semua perangkat.`)) return;
  adminBusy = true;
  postAdmin({ action: "reset_password", id })
    .then((r) => {
      if (r.status === "ok") {
        // prompt() supaya password mudah disalin
        prompt(`Password sementara untuk "${username}". Berikan ke konsumen, lalu minta ganti lewat menu Lupa Password:`, r.temp_password);
      } else {
        alert("Gagal: " + (r.message || "Terjadi kesalahan"));
        if (r.status === "unauthorized") location.reload();
      }
    })
    .catch(() => alert("Server error"))
    .finally(() => { adminBusy = false; });
}

function toggleMenuItem(id) {
  adminAction({ action: "toggle_menu_item", id });
}

function deleteMenuItem(id, name) {
  adminAction({ action: "delete_menu_item", id }, `Hapus item "${name}"?`);
}

function toggleRole(id, username) {
  adminAction({ action: "toggle_role", id }, `Ubah role akun "${username}"?`);
}

function toggleRoom(id, name, active, upcoming) {
  const msg = active
    ? `Set "${name}" ke perawatan? Ruangan tidak bisa dibooking sampai diaktifkan lagi.` +
      (upcoming > 0 ? `\n\nAda ${upcoming} booking mendatang di ruangan ini. Hubungi konsumennya atau pindahkan/batalkan dari Riwayat Booking.` : "")
    : `Aktifkan kembali "${name}"?`;
  adminAction({ action: "toggle_room", id }, msg);
}

// ===== MODAL EDIT RUANGAN & MENU =====
function openEditModal(id) {
  const m = document.getElementById(id);
  if (m) m.style.display = "flex";
}
function closeEditModal(id) {
  const m = document.getElementById(id);
  if (m) m.style.display = "none";
}

// room = null untuk tambah ruangan baru
function openEditRoom(room) {
  const f = document.getElementById("editRoomForm");
  if (!f) return;
  f.reset();
  f.elements.id.value = room ? room.id : "";
  document.querySelector("[data-room-modal-title]").textContent = room ? "Edit Ruangan" : "Tambah Ruangan";
  if (room) {
    f.elements.title.value = room.title;
    f.elements.category.value = room.category;
    f.elements.tier.value = room.tier;
    f.elements.console.value = room.console_type;
    f.elements.console_model.value = room.console_model;
    f.elements.price.value = room.price;
    f.elements.weekend_extra.value = room.weekend_extra;
    f.elements.nintendo_extra.value = room.nintendo_extra;
    f.elements.max_players.value = room.max_players;
    f.elements.has_netflix.checked = room.has_netflix;
    f.elements.description.value = room.description || "";
  }
  openEditModal("editRoomModal");
}

// ===== GAME PER RUANGAN =====
// Daftar checkbox berisi game yang bisa dimainkan di konsol ruangan
function openRoomGames(room) {
  const f = document.getElementById("roomGamesForm");
  if (!f) return;
  const games = JSON.parse(f.dataset.games || "[]").filter((g) => g.consoles.includes(room.console_type));
  f.elements.id.value = room.id;
  f.querySelector("[data-room-games-title]").textContent = `Game di ${room.title}`;
  f.querySelector("[data-room-games-search]").value = "";
  const list = f.querySelector("[data-room-games-list]");
  list.innerHTML = "";
  games.forEach((g) => {
    const label = document.createElement("label");
    label.className = "check-opt";
    label.dataset.title = g.title.toLowerCase();
    const box = document.createElement("input");
    box.type = "checkbox";
    box.name = "game_ids[]";
    box.value = g.id;
    box.checked = room.game_ids.includes(g.id);
    label.append(box, " " + g.title);
    list.appendChild(label);
  });
  updateRoomGamesCount();
  openEditModal("roomGamesModal");
}

function updateRoomGamesCount() {
  const f = document.getElementById("roomGamesForm");
  const n = f.querySelectorAll("[data-room-games-list] input:checked").length;
  f.querySelector("[data-room-games-count]").textContent = n
    ? `${n} game dipilih`
    : "Belum ada yang dipilih: website menampilkan semua game konsol ini.";
}

(function initRoomGamesModal() {
  const f = document.getElementById("roomGamesForm");
  if (!f) return;
  const visibleBoxes = () => [...f.querySelectorAll("[data-room-games-list] label:not([hidden]) input")];
  f.querySelector("[data-room-games-search]").addEventListener("input", (e) => {
    const q = e.target.value.trim().toLowerCase();
    f.querySelectorAll("[data-room-games-list] label").forEach((l) => { l.hidden = !!q && !l.dataset.title.includes(q); });
  });
  // "Pilih semua" / "Kosongkan" hanya untuk game yang sedang tampil (hasil pencarian)
  f.querySelector("[data-room-games-all]").addEventListener("click", () => {
    visibleBoxes().forEach((b) => { b.checked = true; });
    updateRoomGamesCount();
  });
  f.querySelector("[data-room-games-none]").addEventListener("click", () => {
    visibleBoxes().forEach((b) => { b.checked = false; });
    updateRoomGamesCount();
  });
  f.addEventListener("change", updateRoomGamesCount);
  f.addEventListener("submit", function (e) {
    e.preventDefault();
    submitEditForm(this, "update_room_games", "roomGamesModal");
  });
})();

function openEditMenu(item) {
  const f = document.getElementById("editMenuForm");
  if (!f) return;
  f.reset();
  f.elements.id.value = item.id;
  f.elements.name.value = item.name;
  f.elements.category.value = item.category;
  f.elements.price.value = item.price;
  f.elements.description.value = item.description || "";
  openEditModal("editMenuModal");
}

// Kirim form edit (FormData agar file gambar ikut terkirim)
function submitEditForm(form, action, modalId) {
  const fd = new FormData(form);
  fd.append("action", action);
  const btn = form.querySelector("[type=submit]");
  btn.disabled = true;
  adminFetch({ method: "POST", body: fd })
    .then((r) => r.json())
    .then((r) => {
      if (r.status === "ok") {
        if (r.image_saved === false) alert("Data tersimpan, tetapi gambar gagal diunggah (harus JPG/PNG/WEBP, maks 2 MB).");
        closeEditModal(modalId);
        location.reload();
      } else {
        alert("Gagal: " + (r.message || "Terjadi kesalahan"));
        if (r.status === "unauthorized") location.reload();
      }
    })
    .catch(() => alert("Server error"))
    .finally(() => { btn.disabled = false; });
}

document.getElementById("editRoomForm")?.addEventListener("submit", function (e) {
  e.preventDefault();
  submitEditForm(this, this.elements.id.value ? "update_room" : "add_room", "editRoomModal");
});
document.getElementById("editMenuForm")?.addEventListener("submit", function (e) {
  e.preventDefault();
  submitEditForm(this, "update_menu_item", "editMenuModal");
});
document.querySelectorAll(".modal-backdrop").forEach((m) =>
  m.addEventListener("click", (e) => { if (e.target === m) m.style.display = "none"; }));

// ===== TOOLTIP GRAFIK DASHBOARD =====
(function initChartTip() {
  const tip = document.getElementById("chartTip");
  if (!tip) return;
  const show = (el, x, y) => {
    tip.textContent = el.dataset.tip;
    tip.hidden = false;
    const w = tip.offsetWidth;
    tip.style.left = Math.min(window.innerWidth - w - 8, Math.max(8, x - w / 2)) + "px";
    tip.style.top = (y - tip.offsetHeight - 12) + "px";
  };
  document.querySelectorAll("[data-tip]").forEach((el) => {
    el.addEventListener("mousemove", (e) => show(el, e.clientX, e.clientY));
    el.addEventListener("mouseleave", () => { tip.hidden = true; });
    el.addEventListener("focus", () => {
      const r = el.getBoundingClientRect();
      show(el, r.left + r.width / 2, r.top);
    });
    el.addEventListener("blur", () => { tip.hidden = true; });
  });
})();


// =====================
// EDIT CONSOLE GAME
// =====================
function openEditGame(id, title, currentConsoles) {
  document.getElementById("editGameId").value = id;
  document.getElementById("editGameTitle").textContent = title;

  const checks = { SWITCH: "editSWITCH", PS4: "editPS4", PS5: "editPS5" };
  const labels = { SWITCH: "editLabelSWITCH", PS4: "editLabelPS4", PS5: "editLabelPS5" };
  const colors = {
    SWITCH: { checked: "linear-gradient(135deg,#ff6b6b,#ff3b3b)", text: "#000", shadow: "rgba(255,107,107,0.5)" },
    PS4: { checked: "linear-gradient(135deg,#4da6ff,#1e90ff)", text: "#000", shadow: "rgba(77,166,255,0.5)" },
    PS5: { checked: "linear-gradient(135deg,#b26bff,#8a2be2)",  text: "#000", shadow: "rgba(178,107,255,0.5)" },
  };

  ["SWITCH","PS4","PS5"].forEach(ps => {
    const chk = document.getElementById(checks[ps]);
    const lbl = document.getElementById(labels[ps]);
    chk.checked = currentConsoles.includes(ps);
    applyCheckStyle(ps, lbl, chk.checked, colors[ps]);

    chk.onchange = () => applyCheckStyle(ps, lbl, chk.checked, colors[ps]);
  });

  const modal = document.getElementById("editGameModal");
  modal.style.display = "flex";
}

function applyCheckStyle(ps, lbl, checked, color) {
  const borderColors = { SWITCH: "rgba(255,107,107,0.5)", PS4: "rgba(77,166,255,0.5)", PS5: "rgba(178,107,255,0.5)" };
  const textColors   = { SWITCH: "#ff6b6b", PS4: "#4da6ff", PS5: "#b26bff" };
  if (checked) {
    lbl.style.background  = color.checked;
    lbl.style.color       = color.text;
    lbl.style.boxShadow   = `0 0 12px ${color.shadow}`;
    lbl.style.borderColor = "transparent";
  } else {
    lbl.style.background  = "transparent";
    lbl.style.color       = textColors[ps];
    lbl.style.boxShadow   = "none";
    lbl.style.borderColor = borderColors[ps];
  }
}

function closeEditGame() {
  document.getElementById("editGameModal").style.display = "none";
}

document.getElementById("saveEditGame")?.addEventListener("click", () => {
  const id = document.getElementById("editGameId").value;
  const consoles = ["SWITCH","PS4","PS5"].filter(ps =>
    document.getElementById("edit" + ps).checked
  );

  if (consoles.length === 0) {
    alert("Pilih minimal 1 console.");
    return;
  }

  const saveBtn = document.getElementById("saveEditGame");
  if (saveBtn.disabled) return;
  saveBtn.disabled = true;

  const body = new URLSearchParams({ action: "update_game_consoles", id });
  consoles.forEach(c => body.append("consoles[]", c));

  adminFetch({ method: "POST", body })
    .then(r => r.json())
    .then(r => {
      if (r.status === "ok") { closeEditGame(); location.reload(); }
      else {
        alert("Gagal: " + (r.message || "Error"));
        if (r.status === "unauthorized") location.reload();
      }
    })
    .catch(() => alert("Server error"))
    .finally(() => { saveBtn.disabled = false; });
});

// Tutup modal klik di luar
document.getElementById("editGameModal")?.addEventListener("click", function(e) {
  if (e.target === this) closeEditGame();
});

// =====================
// BOOKING OPERATOR (KONSUMEN OFFLINE)
// =====================
const offlinePanel = document.getElementById("offlinePanel");
const walkinForm   = document.getElementById("walkinForm");
const reserveForm  = document.getElementById("reserveForm");
const roomOptions  = JSON.parse(offlinePanel?.dataset.rooms || "[]");
// Info per kelas ruangan: tarif termurah, tambahan weekend, tambahan Nintendo (0 = tidak ada)
const classPrices  = JSON.parse(offlinePanel?.dataset.classes || "{}");

const rupiah = (n) => "Rp " + Number(n).toLocaleString("id-ID");

// Estimasi harga, rumus sama dengan bookingPrice() di booking_lib.php
const PRICING = (() => {
  try { return JSON.parse(document.body.dataset.pricing || "{}"); } catch (e) { return {}; }
})();
const isWeekendTs = (ts) => [0, 6].includes(new Date((ts + 7 * 3600) * 1000).getUTCDay());
function estimatePrice(room, hours, startTs, withNintendo, isStudent) {
  const rate = room.price + (withNintendo && room.nintendoExtra ? room.nintendoExtra : 0);
  const student = PRICING.student?.[rate];
  if (isStudent && student && !isWeekendTs(startTs)) {
    return Math.floor(hours / 3) * student[1] + (hours % 3) * student[0];
  }
  const pkg = PRICING.packages?.[rate];
  let total = pkg ? (hours <= 5 ? pkg[hours - 1] : pkg[4] + (hours - 5) * rate) : rate * hours;
  if (isWeekendTs(startTs)) total += room.weekendExtra * hours;
  return total;
}
// Pilihan Nintendo hanya tampil untuk ruangan yang menyediakannya
function syncNintendoOpt(form, room) {
  const opt = form.querySelector("[data-nintendo]");
  if (!opt) return;
  opt.hidden = !room?.nintendoExtra;
  if (opt.hidden) opt.querySelector("input").checked = false;
}

// Pasang/lepas harga Paket Pelajar (kasir sudah cek kartu pelajar)
function toggleStudent(id, isStudent) {
  adminAction({ action: "toggle_student", id },
    isStudent ? "Kembalikan ke harga biasa?" : "Pakai harga pelajar? Pastikan konsumen menunjukkan kartu pelajar.");
}

// due = sisa yang harus dibayar (total dikurangi yang sudah diterima sebelumnya)
function markPaid(id, name, due) {
  adminAction({ action: "mark_paid", id },
    `Tandai booking "${name}" lunas? Pastikan ${rupiah(due)} sudah diterima.`);
}

function adminExtend(id) {
  const hours = document.getElementById(`extendSel${id}`)?.value || 1;
  if (!confirm(`Tambah ${hours} jam untuk sesi ini?`)) return;
  adminBusy = true;
  postAdmin({ action: "admin_extend", id, hours })
    .then((r) => {
      if (r.status === "ok") {
        alert(`Sesi ditambah ${hours} jam. Tagih tambahan ${rupiah(r.extra_cost)} ke konsumen, lalu tekan "Lunas".`);
        location.reload();
      } else {
        alert("Gagal: " + (r.message || "Terjadi kesalahan"));
        if (r.status === "unauthorized") location.reload();
      }
    })
    .catch(() => alert("Server error"))
    .finally(() => { adminBusy = false; });
}

// ----- Tab form: Main Sekarang / Reservasi Jam Tertentu -----
function showFormTab(id) {
  document.querySelectorAll(".form-tab").forEach((t) => t.classList.toggle("active", t.dataset.tab === id));
  document.querySelectorAll(".tab-form").forEach((f) => { f.hidden = f.id !== id; });
  try { sessionStorage.setItem("adminFormTab", id); } catch (e) {}
}
document.querySelectorAll(".form-tab").forEach((t) =>
  t.addEventListener("click", () => showFormTab(t.dataset.tab)));
try {
  const saved = sessionStorage.getItem("adminFormTab");
  if (saved && document.getElementById(saved)) showFormTab(saved);
} catch (e) {}

// ----- Main Sekarang -----
function updateWalkinEstimate() {
  if (!walkinForm) return;
  const out = walkinForm.querySelector("[data-estimate]");
  const roomId = Number(walkinForm.elements.room_id.value);
  const dur = Number(walkinForm.elements.duration.value) || 0;
  const room = roomOptions.find((o) => o.id === roomId);
  const f = walkinForm.elements;
  // Ruangan Otomatis: Nintendo & estimasi mengikuti kelas (tarif termurah di kelas itu)
  const cls = classPrices[f.room_class.value];
  syncNintendoOpt(walkinForm, room || cls);
  const now = Math.floor(Date.now() / 1000);
  if (room) {
    out.textContent = rupiah(estimatePrice(room, dur, now, f.nintendo.checked, f.student.checked));
  } else {
    out.textContent = cls
      ? "mulai " + rupiah(estimatePrice(cls, dur, now, f.nintendo.checked, f.student.checked))
      : "Tidak ada ruangan aktif";
  }
}

walkinForm?.addEventListener("change", (e) => {
  const f = walkinForm.elements;
  // Pilih ruangan: kelas ikut ruangan. Ganti kelas: ruangan kembali ke Otomatis bila beda kelas.
  if (e.target.name === "room_id" && Number(f.room_id.value) > 0) {
    const room = roomOptions.find((o) => o.id === Number(f.room_id.value));
    if (room) f.room_class.value = room.class;
  }
  if (e.target.name === "room_class") {
    const room = roomOptions.find((o) => o.id === Number(f.room_id.value));
    if (room && room.class !== f.room_class.value) f.room_id.value = "0";
  }
  updateWalkinEstimate();
});
updateWalkinEstimate();

walkinForm?.addEventListener("submit", function (e) {
  e.preventDefault();
  const params = Object.fromEntries(new FormData(this));
  params.action = "walkin_join";
  const btn = this.querySelector("button[type=submit]");
  btn.disabled = true;
  adminBusy = true;
  postAdmin(params)
    .then((r) => {
      if (r.status !== "ok") {
        alert("Gagal: " + (r.message || "Terjadi kesalahan"));
        if (r.status === "unauthorized") location.reload();
        return;
      }
      if (r.assigned) {
        alert(`Konsumen dapat ${r.room}. Sesi dimulai sekarang dan ditandai lunas.`);
      } else {
        alert(`Belum ada ruangan ${params.room_class} yang bisa dipakai sekarang. ` +
          `Konsumen masuk daftar tunggu nomor ${r.position}.` + (r.hint ? `\n\n${r.hint}` : ""));
      }
      location.reload();
    })
    .catch(() => alert("Server error"))
    .finally(() => { btn.disabled = false; adminBusy = false; });
});

// ----- Reservasi Jam Tertentu -----
let reserveSlots = [];
let reserveReq = 0;
// Jam yang dipilih lewat halaman Jadwal (?time=HH:MM), dipakai sampai admin mengubah pilihan
let reservePrefillTime = "";

// Jam operasional dari booking_lib.php (atribut <body>): buka 10:00, tutup 22:00,
// jam mulai reservasi kelipatan 30 menit
const OPEN_SEC  = Number(document.body.dataset.openSec) || 36000;
const CLOSE_SEC = Number(document.body.dataset.closeSec) || 79200;
const SLOT_SEC  = 1800;
const secToHHMM = (sec) =>
  `${String(Math.floor(sec / 3600)).padStart(2, "0")}:${String(Math.floor(sec % 3600 / 60)).padStart(2, "0")}`;

// Timestamp jam "HH:MM" WIB pada tanggal dateVal (tidak bergantung zona waktu perangkat)
function slotTs(dateVal, hhmm) {
  return Math.floor(new Date(`${dateVal}T${hhmm}:00+07:00`).getTime() / 1000);
}
const overlaps = (start, end) => reserveSlots.some((s) => start < s.end_time && end > s.start_time);

// Opsi default ditandai defaultSelected agar deteksi "form diubah" tetap akurat
function fillSelect(sel, items) {
  sel.innerHTML = "";
  const def = Math.max(0, items.findIndex((it) => !it.disabled));
  items.forEach((it, i) => {
    const opt = new Option(it.label, it.value, i === def, i === def);
    opt.disabled = !!it.disabled;
    sel.add(opt);
  });
}

function renderReserveTimes() {
  const f = reserveForm.elements;
  const now = Date.now() / 1000;
  const items = [];
  for (let t = OPEN_SEC; t + 3600 <= CLOSE_SEC; t += SLOT_SEC) {
    const hhmm = secToHHMM(t);
    const start = slotTs(f.date.value, hhmm);
    if (start < now) continue;
    const booked = overlaps(start, start + 3600);
    items.push({ value: hhmm, label: `${hhmm}${booked ? " — terpesan" : ""}`, disabled: booked });
  }
  fillSelect(f.time, items.length ? items : [{ value: "", label: "Jam habis", disabled: true }]);
  // Jam dari klik slot di halaman Jadwal dipilih bila masih tersedia
  const picked = [...f.time.options].find((o) => o.value === reservePrefillTime && !o.disabled);
  if (picked) f.time.value = picked.value;
  renderReserveDurations();
}

function renderReserveDurations() {
  const f = reserveForm.elements;
  const items = [];
  if (f.time.value) {
    const start = slotTs(f.date.value, f.time.value);
    const maxH = Math.floor((slotTs(f.date.value, "00:00") + CLOSE_SEC - start) / 3600);
    for (let d = 1; d <= Math.min(12, maxH); d++) {
      if (overlaps(start, start + d * 3600)) break;
      items.push({ value: d, label: `${d} jam` });
    }
  }
  fillSelect(f.duration, items.length ? items : [{ value: "", label: "Pilih jam lain", disabled: true }]);
  updateReserveEstimate();
}

function updateReserveEstimate() {
  const f = reserveForm.elements;
  const room = roomOptions.find((o) => o.id === Number(f.room_id.value));
  const dur = Number(f.duration.value) || 0;
  syncNintendoOpt(reserveForm, room);
  const start = f.time.value ? slotTs(f.date.value, f.time.value) : 0;
  reserveForm.querySelector("[data-estimate]").textContent = room && dur
    ? rupiah(estimatePrice(room, dur, start, f.nintendo.checked, f.student.checked)) : "-";
}

function loadReserveSlots() {
  const f = reserveForm.elements;
  const info = reserveForm.querySelector("[data-booked]");
  const reqId = ++reserveReq;
  // Tampilkan jam untuk tanggal/ruangan baru segera (jam lampau langsung hilang),
  // slot terpesan menyusul setelah data dari server datang.
  reserveSlots = [];
  info.textContent = "";
  renderReserveTimes();
  if (!f.room_id.value) return;
  fetch(`booked_slots_api.php?room_id=${encodeURIComponent(f.room_id.value)}&date=${f.date.value}`)
    .then((r) => r.json())
    .then((slots) => {
      if (reqId !== reserveReq) return;
      reserveSlots = slots;
      const fmt = (u) => new Date(u * 1000).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
      info.textContent = slots.length ? "Terpesan: " + slots.map((s) => `${fmt(s.start_time)}–${fmt(s.end_time)}`).join(", ") : "";
      renderReserveTimes();
    })
    .catch(() => {
      if (reqId === reserveReq) info.textContent = "Gagal memuat jadwal ruangan. Jam terpesan belum ditandai.";
    });
}

if (reserveForm) {
  // Datang dari klik slot kosong di halaman Jadwal: isi ruangan, tanggal, dan jam otomatis
  const pre = new URLSearchParams(location.search);
  if (pre.get("tab") === "reserveForm") {
    showFormTab("reserveForm");
    const f = reserveForm.elements;
    if ([...f.room_id.options].some((o) => o.value === pre.get("room"))) f.room_id.value = pre.get("room");
    if ([...f.date.options].some((o) => o.value === pre.get("date"))) f.date.value = pre.get("date");
    reservePrefillTime = pre.get("time") || "";
    // Bersihkan parameter supaya auto-refresh/reload tidak mengisi ulang form
    history.replaceState(null, "", "?page=booking");
    setTimeout(() => f.name.focus(), 0);
  }
  reserveForm.addEventListener("change", (e) => {
    if (["room_id", "date", "time"].includes(e.target.name)) reservePrefillTime = "";
    if (e.target.name === "room_id" || e.target.name === "date") loadReserveSlots();
    else if (e.target.name === "time") renderReserveDurations();
    else updateReserveEstimate();
  });
  loadReserveSlots();

  reserveForm.addEventListener("submit", function (e) {
    e.preventDefault();
    const params = Object.fromEntries(new FormData(this));
    if (!params.time || !params.duration) {
      alert("Pilih jam dan durasi yang masih tersedia.");
      return;
    }
    params.action = "admin_reserve";
    const btn = this.querySelector("button[type=submit]");
    btn.disabled = true;
    adminBusy = true;
    postAdmin(params)
      .then((r) => {
        if (r.status !== "ok") {
          alert("Gagal: " + (r.message || "Terjadi kesalahan"));
          if (r.status === "unauthorized") location.reload();
          else loadReserveSlots();
          return;
        }
        const d = new Date(r.start_time * 1000);
        alert(`Reservasi tersimpan (${r.order_code}).\n${r.room}, ${d.toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long" })} ` +
          `jam ${d.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}, ${r.duration} jam — ${rupiah(r.total_price)}.\n` +
          `Konsumen bayar di kasir saat datang, paling lambat 15 menit setelah jam mulai.`);
        location.reload();
      })
      .catch(() => alert("Server error"))
      .finally(() => { btn.disabled = false; adminBusy = false; });
  });
}

// Form berubah dari nilai awal
function isFormDirty(form) {
  return Array.from(form.elements).some((el) => {
    if (el.type === "radio" || el.type === "checkbox") return el.checked !== el.defaultChecked;
    if (el.tagName === "SELECT") {
      // Select tanpa atribut "selected" default-nya opsi aktif pertama (dipilih otomatis browser)
      const opts = Array.from(el.options);
      let def = opts.findIndex((o) => o.defaultSelected);
      if (def < 0) def = opts.findIndex((o) => !o.disabled);
      return el.selectedIndex !== def;
    }
    if ("defaultValue" in el) return el.value !== el.defaultValue;
    return false;
  });
}

// Refresh berkala supaya status ruangan & daftar tunggu terbaru terlihat,
// kecuali admin sedang mengisi form, memilih "+jam", atau ada aksi yang berjalan.
if (offlinePanel) {
  setInterval(() => {
    const active = document.activeElement;
    const editing = active?.matches("input, select, textarea")
      && (offlinePanel.contains(active) || active.closest(".room-tile"));
    const forms = [walkinForm, reserveForm].filter(Boolean);
    // Pilihan "+jam" yang sudah diubah tapi belum diklik Tambah jangan sampai tereset
    const extendPicked = [...document.querySelectorAll(".extend-inline select")].some((s) => s.selectedIndex !== 0);
    if (!adminBusy && !editing && !extendPicked && !forms.some(isFormDirty)) location.reload();
  }, 20000);
}

// Halaman Menu: refresh berkala supaya pesanan baru langsung terlihat kasir,
// kecuali admin sedang mengisi form tambah item atau modal edit sedang terbuka.
const addMenuForm = document.getElementById("addMenuForm");
if (addMenuForm) {
  setInterval(() => {
    const modalOpen = [...document.querySelectorAll(".modal-backdrop")].some((m) => m.style.display === "flex");
    const active = document.activeElement;
    const editing = active?.matches("input, select, textarea");
    if (!adminBusy && !modalOpen && !editing && !isFormDirty(addMenuForm)) location.reload();
  }, 30000);
}

document.addEventListener("DOMContentLoaded", () => {
  // =====================
  // CUSTOM FILE UPLOAD ZONES
  // =====================
  document.querySelectorAll(".file-upload-zone").forEach((zone) => {
    const input   = zone.querySelector("input[type='file']");
    const nameEl  = zone.querySelector(".file-upload-name");
    const preview = zone.querySelector(".file-upload-preview");

    function applyFile(file) {
      if (!file) return;
      nameEl.textContent = file.name;
      zone.classList.add("has-file");

      const reader = new FileReader();
      reader.onload = (e) => {
        preview.src = e.target.result;
        preview.style.display = "block";
      };
      reader.readAsDataURL(file);
    }

    input?.addEventListener("change", () => applyFile(input.files[0]));

    zone.addEventListener("dragover", (e) => {
      e.preventDefault();
      zone.classList.add("dragover");
    });
    zone.addEventListener("dragleave", () => zone.classList.remove("dragover"));
    zone.addEventListener("drop", (e) => {
      e.preventDefault();
      zone.classList.remove("dragover");
      const file = e.dataTransfer.files[0];
      if (file && input) {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        applyFile(file);
      }
    });
  });

  // =====================
  // ADD MENU ITEM
  // =====================
  document.getElementById("addMenuForm")?.addEventListener("submit", function (e) {
    e.preventDefault();
    const btn = this.querySelector("[type=submit]");
    if (btn?.disabled) return;
    if (btn) btn.disabled = true;
    adminBusy = true;
    const formData = new FormData(this);
    formData.append("action", "add_menu_item");
    adminFetch({ method: "POST", body: formData })
      .then((res) => res.json())
      .then((res) => {
        if (res.status === "ok") {
          alert("Item berhasil ditambahkan" +
            (res.image_saved === false ? "\nCatatan: gambar gagal tersimpan (harus JPG/PNG/WEBP, maks 2 MB)." : ""));
          location.reload();
        } else {
          alert("Gagal: " + (res.message || "Error"));
          if (res.status === "unauthorized") location.reload();
        }
      })
      .catch(() => alert("Server error"))
      .finally(() => { if (btn) btn.disabled = false; adminBusy = false; });
  });

  // =====================
  // CUSTOM GENRE TOGGLE
  // =====================
  const genreSelect       = document.getElementById("genreSelect");
  const customGenreWrap   = document.getElementById("customGenreWrap");
  const customGenreInput  = document.getElementById("customGenreInput");
  const cancelCustomGenre = document.getElementById("cancelCustomGenre");

  genreSelect?.addEventListener("change", () => {
    if (genreSelect.value === "__custom__") {
      customGenreWrap.style.display = "flex";
      customGenreInput.name     = "genre";
      customGenreInput.required = true;
      genreSelect.name          = "";
      genreSelect.required      = false;
      customGenreInput.focus();
    } else {
      customGenreWrap.style.display = "none";
      customGenreInput.name     = "";
      customGenreInput.required = false;
      genreSelect.name          = "genre";
      genreSelect.required      = true;
    }
  });

  cancelCustomGenre?.addEventListener("click", () => {
    genreSelect.value         = "";
    customGenreWrap.style.display = "none";
    customGenreInput.name     = "";
    customGenreInput.value    = "";
    customGenreInput.required = false;
    genreSelect.name          = "genre";
    genreSelect.required      = true;
  });

  // =====================
  // ADD GAME
  // =====================
  document
    .getElementById("addGameForm")
    ?.addEventListener("submit", function (e) {
      e.preventDefault();
      const btn = this.querySelector("[type=submit]");
      if (btn?.disabled) return;
      if (btn) btn.disabled = true;

      const formData = new FormData(this);
      formData.append("action", "add_game");

      adminFetch({
        method: "POST",
        body: formData,
      })
        .then((res) => res.json())
        .then((res) => {
          if (res.status === "ok") {
            const imgMsg = res.image_saved === false ? "\nCatatan: gambar gagal tersimpan (cek format/ukuran file)." : "";
            // Ruangan dengan daftar game khusus tidak otomatis memuat game baru
            alert("Game berhasil ditambahkan!" + imgMsg +
              "\n\nTambahkan game ini ke ruangan lewat menu Room > Atur Game supaya tampil di detail ruangan.");
            location.reload();
          } else {
            alert("Gagal menambahkan game:\n" + (res.message || res.msg));
            if (res.status === "unauthorized") location.reload();
          }
        })
        .catch(() => alert("Server error"))
        .finally(() => { if (btn) btn.disabled = false; });
    });
});

// =====================
// JADWAL RUANGAN
// =====================
// Modal detail booking dari grid Jadwal. Aksi memakai fungsi yang sama dengan halaman Booking.
function openScheduleBooking(b) {
  const modal = document.getElementById("scheduleModal");
  if (!modal) return;
  const set = (key, text) => { modal.querySelector(`[data-sched="${key}"]`).textContent = text; };
  set("name", b.name);
  set("sub", `${b.code} · ${b.tags}`);
  set("room", b.room);
  set("time", b.time);
  set("phone", b.phone || "-");
  set("total", b.total);
  set("paid", b.paid);
  set("status", b.status);

  const actions = modal.querySelector('[data-sched="actions"]');
  actions.innerHTML = "";
  const addBtn = (label, cls, onClick) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = cls;
    btn.textContent = label;
    btn.addEventListener("click", onClick);
    actions.appendChild(btn);
  };
  if (b.canCheckin) addBtn("Hadir", "btn-checkin", () => checkinBooking(b.id, b.name));
  if (b.canPay) addBtn("Lunas", "btn-paid", () => markPaid(b.id, b.name, b.due));
  if (b.canStudent) addBtn(b.isStudent ? "Batal Harga Pelajar" : "Harga Pelajar", "btn-ghost-sm", () => toggleStudent(b.id, b.isStudent));
  if (b.canCancel) addBtn("Batalkan Booking", "btn-warn-sm", () => cancelBooking(b.id, b.name, b.amountPaid));
  addBtn("Tutup", "btn-ghost-sm", () => closeEditModal("scheduleModal"));
  openEditModal("scheduleModal");
}

// Jadwal diperbarui berkala, kecuali modal detail sedang terbuka atau ada aksi berjalan
if (document.querySelector(".sched-grid")) {
  setInterval(() => {
    const modalOpen = document.getElementById("scheduleModal")?.style.display === "flex";
    if (!adminBusy && !modalOpen) location.reload();
  }, 30000);
}

// =====================
// NOTIFIKASI BOOKING ONLINE BARU
// =====================
// Id terakhir yang sudah dilihat disimpan di browser kasir. Pertama kali dibuka langsung
// disamakan dengan data terbaru supaya tidak muncul notifikasi untuk booking lama.
(function initAdminNotify() {
  const nav = document.querySelector("[data-nav]");
  if (!nav) return; // halaman login admin
  const store = {
    get(key) { try { return Number(localStorage.getItem(key)) || 0; } catch (e) { return 0; } },
    set(key, val) { try { localStorage.setItem(key, String(val)); } catch (e) {} },
  };
  const KEY_B = "adminSeenBooking", KEY_Q = "adminSeenQueue";
  const KEY_NB = "adminNewBooking", KEY_NQ = "adminNewQueue";
  const page = new URLSearchParams(location.search).get("page") || "dashboard";
  const baseTitle = document.title;

  // Membuka halaman tujuan = notifikasinya sudah dilihat
  if (page === "jadwal") store.set(KEY_NB, 0);
  if (page === "booking") store.set(KEY_NQ, 0);

  const setBadge = (key, n) => {
    const link = document.querySelector(`[data-nav="${key}"]`);
    if (!link) return;
    let badge = link.querySelector(".notify-badge");
    if (n > 0) {
      if (!badge) {
        badge = document.createElement("span");
        badge.className = "nav-badge notify-badge";
        link.appendChild(badge);
      }
      badge.textContent = n;
      badge.title = key === "jadwal" ? "Reservasi online baru" : "Antrian online baru";
    } else if (badge) {
      badge.remove();
    }
  };
  const renderBadges = () => {
    const nb = store.get(KEY_NB), nq = store.get(KEY_NQ);
    setBadge("jadwal", nb);
    setBadge("booking", nq);
    document.title = nb + nq > 0 ? `(${nb + nq}) ${baseTitle}` : baseTitle;
  };

  const toastBox = document.createElement("div");
  toastBox.className = "notify-stack";
  toastBox.setAttribute("role", "status");
  toastBox.setAttribute("aria-live", "polite");
  document.body.appendChild(toastBox);
  const toast = (title, text, href) => {
    const el = document.createElement("a");
    el.className = "notify-toast";
    el.href = href;
    const strong = document.createElement("strong");
    strong.textContent = title;
    const span = document.createElement("span");
    span.textContent = text;
    el.append(strong, span);
    toastBox.prepend(el);
    setTimeout(() => el.remove(), 15000);
  };

  let first = !store.get(KEY_B) && !store.get(KEY_Q);
  const poll = () => {
    fetch(`admin_notify.php?after_booking=${store.get(KEY_B)}&after_queue=${store.get(KEY_Q)}`, { cache: "no-store" })
      .then((r) => r.json())
      .then((r) => {
        if (r.status !== "ok") return;
        if (!first) {
          r.bookings.slice().reverse().forEach((b) =>
            toast("Reservasi online baru", `${b.name} · ${b.room} · ${b.when}`, `?page=jadwal&d=${b.date}`));
          r.queue.slice().reverse().forEach((q) =>
            toast("Antrian online baru", `${q.name} · ${q.class} · ${q.hours} jam`, "?page=booking"));
          if (r.bookings.length && page !== "jadwal") store.set(KEY_NB, store.get(KEY_NB) + r.bookings.length);
          if (r.queue.length && page !== "booking") store.set(KEY_NQ, store.get(KEY_NQ) + r.queue.length);
        }
        first = false;
        store.set(KEY_B, Math.max(store.get(KEY_B), r.max_booking_id));
        store.set(KEY_Q, Math.max(store.get(KEY_Q), r.max_queue_id));
        renderBadges();
      })
      .catch(() => {});
  };
  renderBadges();
  poll();
  setInterval(poll, 20000);
})();
