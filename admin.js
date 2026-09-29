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
// BOOKING OPERATOR (KONSUMEN DATANG LANGSUNG)
// =====================
const walkinForm = document.getElementById("walkinForm");

// Estimasi pakai harga ruangan termurah untuk konsol terpilih
function updateWalkinEstimate() {
  const out = document.getElementById("walkinEstimate");
  if (!walkinForm || !out) return;
  const prices = JSON.parse(walkinForm.dataset.prices || "{}");
  const ct = walkinForm.elements.console_type.value;
  const dur = Number(walkinForm.elements.duration.value) || 0;
  out.textContent = prices[ct]
    ? "Rp " + (prices[ct] * dur).toLocaleString("id-ID")
    : "Tidak ada ruangan aktif";
}
walkinForm?.addEventListener("change", updateWalkinEstimate);
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
      alert(r.assigned
        ? `Ruangan tersedia: ${r.room}. Sesi dimulai sekarang (lunas).`
        : `Semua ruangan ${params.console_type} penuh. Konsumen masuk daftar tunggu nomor ${r.position}.`);
      location.reload();
    })
    .catch(() => alert("Server error"))
    .finally(() => { btn.disabled = false; adminBusy = false; });
});

// Form berubah dari nilai awal (nama, HP, konsol, atau durasi)
function isFormDirty(form) {
  return Array.from(form.elements).some((el) => {
    if (el.type === "radio" || el.type === "checkbox") return el.checked !== el.defaultChecked;
    if (el.tagName === "SELECT") return Array.from(el.options).some((o) => o.selected !== o.defaultSelected);
    if ("defaultValue" in el) return el.value !== el.defaultValue;
    return false;
  });
}

// Refresh berkala supaya status ruangan & daftar tunggu terbaru terlihat,
// kecuali admin sedang mengisi form atau ada aksi yang berjalan.
if (walkinForm) {
  setInterval(() => {
    const busy = adminBusy || walkinForm.contains(document.activeElement) || isFormDirty(walkinForm);
    if (!busy) location.reload();
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
