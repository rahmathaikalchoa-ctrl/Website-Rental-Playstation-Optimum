function hapusBooking(id) {
  if (!confirm("Hapus booking?")) return;

  fetch("admin_api.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "action=delete_booking&id=" + id,
  })
    .then((res) => res.json())
    .then((res) => {
      if (res.status === "ok") {
        location.reload();
      } else {
        alert("Gagal menghapus booking");
      }
    })
    .catch(() => alert("Server error"));
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
// DELETE GAME
// =====================
function deleteGame(id, title) {
  if (!confirm(`Hapus game "${title}"?`)) return;
  fetch("admin_api.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "action=delete_game&id=" + id,
  })
    .then(() => location.reload())
    .catch(() => alert("Server error"));
}

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
// MARK ORDER DONE
// =====================
function markOrderDone(id) {
  if (!confirm("Tandai pesanan ini sudah sampai ke user?")) return;
  fetch("admin_api.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "action=mark_order_done&id=" + id,
  })
    .then(() => location.reload())
    .catch(() => alert("Server error"));
}

// =====================
// TOGGLE MENU ITEM
// =====================
function toggleMenuItem(id) {
  fetch("admin_api.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "action=toggle_menu_item&id=" + id,
  })
    .then(() => location.reload())
    .catch(() => alert("Server error"));
}

// =====================
// DELETE MENU ITEM
// =====================
function deleteMenuItem(id, name) {
  if (!confirm(`Hapus item "${name}"?`)) return;
  fetch("admin_api.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "action=delete_menu_item&id=" + id,
  })
    .then(() => location.reload())
    .catch(() => alert("Server error"));
}

// =====================
// TOGGLE ROLE
// =====================
function toggleRole(id, username) {
  if (!confirm(`Ubah role akun "${username}"?`)) return;
  fetch("admin_api.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "action=toggle_role&id=" + id,
  })
    .then(() => location.reload())
    .catch(() => alert("Server error"));
}

// =====================
// TOGGLE ROOM
// =====================
function toggleRoom(id) {
  fetch("admin_api.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "action=toggle_room&id=" + id,
  })
    .then(() => location.reload())
    .catch(() => alert("Server error"));
}

// =====================
// FIX OVERLAY SAAT LOGIN ADMIN
// =====================
document.addEventListener("DOMContentLoaded", () => {
  const overlays = document.querySelectorAll(
    ".modal, .overlay, .notifModal, #notifModal"
  );

  overlays.forEach((el) => {
    el.style.display = "none";
    el.classList.remove("show", "active");
  });

  document.body.style.overflow = "auto";

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
            const imgMsg = res.image_saved === false ? "\n⚠️ Gambar gagal tersimpan (cek format/ukuran file)." : "";
            alert("Game berhasil ditambahkan!" + imgMsg);
            location.reload();
          } else {
            alert("Gagal menambahkan game:\n" + (res.message || res.msg));
          }
        })
        .catch(() => alert("Server error"));
    });
});
