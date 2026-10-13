<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}

// ===== GANTI PASSWORD (pakai password lama, bukan email/OTP) =====
$username    = trim($_POST['username'] ?? '');
// di-trim kayak login/register biar hash sama
$oldPassword = trim($_POST['old_password'] ?? '');
$newPassword = trim($_POST['new_password'] ?? '');

// bcrypt cuma pakai 72 byte pertama
if ($username === '' || $oldPassword === '' || strlen($newPassword) < 6 || strlen($newPassword) > 72) {
  echo json_encode(["status" => "error", "message" => "Lengkapi semua kolom. Password baru 6-72 karakter."]);
  exit;
}

// maks 3x per 15 menit per IP + username
if (!checkRateLimit('forgot_password', 3, 900, $username)) {
  echo json_encode(["status" => "error", "message" => "Terlalu banyak percobaan. Coba lagi dalam 15 menit."]);
  exit;
}

$stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// pesan sama buat username gak ada & password salah biar gak bisa nebak username
if (!$user || !password_verify($oldPassword, $user['password'])) {
  echo json_encode(["status" => "error", "message" => "Username atau password lama salah"]);
  exit;
}

// password sama gak diganti
if (password_verify($newPassword, $user['password'])) {
  echo json_encode(["status" => "error", "message" => "Password baru harus berbeda dari password lama"]);
  exit;
}

$hashed = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $hashed, $user['id']);
$stmt->execute();
$ok = $stmt->affected_rows > 0;
$stmt->close();

if (!$ok) {
  echo json_encode(["status" => "error", "message" => "Gagal mengganti password. Coba lagi."]);
  exit;
}

// sesi lain akun ini keluar, sesi yg dipakai ganti password tetap login
if (intval($_SESSION['user_id'] ?? 0) === intval($user['id'])) {
  $_SESSION['pw_sig'] = passwordSig($hashed);
}
if (intval($_SESSION['admin_id'] ?? 0) === intval($user['id'])) {
  $_SESSION['admin_pw_sig'] = passwordSig($hashed);
}

clearRateLimit('forgot_password', $username);
echo json_encode(["status" => "ok"]);
