// =====================
// AKSI ADMIN
// =====================
// true selama ada request aksi berjalan, supaya auto-refresh tidak memotongnya
let adminBusy = false;

function postAdmin(params) {
  return fetch("admin_api.php", {
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

function cancelBooking(id, name) {
  adminAction({ action: "cancel_booking", id },
    `Batalkan booking "${name}"? Data tetap tersimpan di riwayat.`);
}

function finishBooking(id) {
  adminAction({ action: "finish_booking", id },
    "Selesaikan sesi ini sekarang? Ruangan akan diberikan ke konsumen berikutnya di daftar tunggu.");
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

function toggleMenuItem(id) {
  adminAction({ action: "toggle_menu_item", id });
}

function deleteMenuItem(id, name) {
  adminAction({ action: "delete_menu_item", id }, `Hapus item "${name}"?`);
}

function toggleRole(id, username) {
  adminAction({ action: "toggle_role", id }, `Ubah role akun "${username}"?`);
}

function toggleRoom(id) {
  adminAction({ action: "toggle_room", id });
}

// =====================
// ADD ROOM
// =====================
document
  .getElementById("addRoomForm")
  ?.addEventListener("submit", function (e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append("action", "add_room");

    fetch("admin_api.php", {
      method: "POST",
      body: formData,
    })
      .then((res) => res.json())
      .then((res) => {
        if (res.status === "ok") {
          alert("Room berhasil ditambahkan");
          location.reload();
        } else {
          alert("Gagal menambah room:\n" + (res.message || res.msg));
        }
      })
      .catch(() => alert("Server error"));
  });

// =====================
// EDIT CONSOLE GAME
// =====================
function openEditGame(id, title, currentConsoles) {
  document.getElementById("editGameId").value = id;
  document.getElementById("editGameTitle").textContent = title;

  const checks = { PS3: "editPS3", PS4: "editPS4", PS5: "editPS5" };
  const labels = { PS3: "editLabelPS3", PS4: "editLabelPS4", PS5: "editLabelPS5" };
  const colors = {
    PS3: { checked: "linear-gradient(135deg,#ff6b6b,#ff3b3b)", text: "#000", shadow: "rgba(255,107,107,0.5)" },
    PS4: { checked: "linear-gradient(135deg,#4da6ff,#1e90ff)", text: "#000", shadow: "rgba(77,166,255,0.5)" },
    PS5: { checked: "linear-gradient(135deg,#b26bff,#8a2be2)",  text: "#000", shadow: "rgba(178,107,255,0.5)" },
  };

  ["PS3","PS4","PS5"].forEach(ps => {
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
  const borderColors = { PS3: "rgba(255,107,107,0.5)", PS4: "rgba(77,166,255,0.5)", PS5: "rgba(178,107,255,0.5)" };
  const textColors   = { PS3: "#ff6b6b", PS4: "#4da6ff", PS5: "#b26bff" };
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
  const consoles = ["PS3","PS4","PS5"].filter(ps =>
    document.getElementById("edit" + ps).checked
  );

  if (consoles.length === 0) {
    alert("Pilih minimal 1 console.");
    return;
  }

  const body = new URLSearchParams({ action: "update_game_consoles", id });
  consoles.forEach(c => body.append("consoles[]", c));

  fetch("admin_api.php", { method: "POST", body })
    .then(r => r.json())
    .then(r => {
      if (r.status === "ok") { closeEditGame(); location.reload(); }
      else alert("Gagal: " + (r.message || "Error"));
    })
    .catch(() => alert("Server error"));
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
const minPrices    = JSON.parse(offlinePanel?.dataset.prices || "{}");

const rupiah = (n) => "Rp " + Number(n).toLocaleString("id-ID");

function markPaid(id, name, total) {
  adminAction({ action: "mark_paid", id },
    `Tandai booking "${name}" lunas? Pastikan ${rupiah(total)} sudah diterima.`);
}

function adminExtend(id) {
  const hours = document.getElementById(`extendSel${id}`)?.value || 1;
  if (!confirm(`Tambah ${hours} jam untuk sesi ini?`)) return;
  adminBusy = true;
  postAdmin({ action: "admin_extend", id, hours })
    .then((r) => {
      if (r.status === "ok") {
        alert(`Sesi ditambah ${hours} jam. Tagih tambahan ${rupiah(r.extra_cost)} ke konsumen.`);
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
  const price = room ? room.price : minPrices[walkinForm.elements.console_type.value];
  out.textContent = price ? (room ? "" : "mulai ") + rupiah(price * dur) : "Tidak ada ruangan aktif";
}

walkinForm?.addEventListener("change", (e) => {
  const f = walkinForm.elements;
  // Pilih ruangan: konsol ikut ruangan. Ganti konsol: ruangan kembali ke Otomatis bila beda konsol.
  if (e.target.name === "room_id" && Number(f.room_id.value) > 0) {
    const room = roomOptions.find((o) => o.id === Number(f.room_id.value));
    if (room) f.console_type.value = room.console;
  }
  if (e.target.name === "console_type") {
    const room = roomOptions.find((o) => o.id === Number(f.room_id.value));
    if (room && room.console !== f.console_type.value) f.room_id.value = "0";
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
        alert(`Belum ada ruangan ${params.console_type} yang bisa dipakai sekarang. ` +
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

function slotTs(dateVal, hour) {
  return Math.floor(new Date(`${dateVal}T${String(hour).padStart(2, "0")}:00:00`).getTime() / 1000);
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
  for (let h = 11; h <= 23; h++) {
    const start = slotTs(f.date.value, h);
    if (start < now) continue;
    const booked = overlaps(start, start + 3600);
    items.push({ value: `${String(h).padStart(2, "0")}:00`, label: `${h}:00${booked ? " — terpesan" : ""}`, disabled: booked });
  }
  fillSelect(f.time, items.length ? items : [{ value: "", label: "Tidak ada jam tersisa", disabled: true }]);
  renderReserveDurations();
}

function renderReserveDurations() {
  const f = reserveForm.elements;
  const items = [];
  if (f.time.value) {
    const hour = Number(f.time.value.split(":")[0]);
    const start = slotTs(f.date.value, hour);
    for (let d = 1; d <= Math.min(12, 24 - hour); d++) {
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
  reserveForm.querySelector("[data-estimate]").textContent = room && dur ? rupiah(room.price * dur) : "-";
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
  reserveForm.addEventListener("change", (e) => {
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
    if (!adminBusy && !editing && !forms.some(isFormDirty)) location.reload();
  }, 20000);
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
    const formData = new FormData(this);
    formData.append("action", "add_menu_item");
    fetch("admin_api.php", { method: "POST", body: formData })
      .then((res) => res.json())
      .then((res) => {
        if (res.status === "ok") {
          alert("Item berhasil ditambahkan");
          location.reload();
        } else {
          alert("Gagal: " + (res.message || "Error"));
        }
      })
      .catch(() => alert("Server error"));
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

      const formData = new FormData(this);
      formData.append("action", "add_game");

      fetch("admin_api.php", {
        method: "POST",
        body: formData,
      })
        .then((res) => res.json())
        .then((res) => {
          if (res.status === "ok") {
            const imgMsg = res.image_saved === false ? "\nCatatan: gambar gagal tersimpan (cek format/ukuran file)." : "";
            alert("Game berhasil ditambahkan!" + imgMsg);
            location.reload();
          } else {
            alert("Gagal menambahkan game:\n" + (res.message || res.msg));
          }
        })
        .catch(() => alert("Server error"));
    });
});
