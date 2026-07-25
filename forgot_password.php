<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error"]);
  exit;
}

// ===== RATE LIMIT: max 3 percobaan per 15 menit =====
$window = 900;
$maxAttempts = 3;
$_SESSION['fp_attempts'] = array_values(array_filter(
  $_SESSION['fp_attempts'] ?? [],
  fn($t) => time() - $t < $window
));
if (count($_SESSION['fp_attempts']) >= $maxAttempts) {
  echo json_encode(["status" => "error", "message" => "Terlalu banyak percobaan. Coba lagi dalam 15 menit."]);
  exit;
}

// ===== GANTI PASSWORD (verifikasi via password lama, bukan email/OTP) =====
$username    = trim($_POST['username'] ?? '');
$oldPassword = $_POST['old_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';

if ($username === '' || $oldPassword === '' || strlen($newPassword) < 6) {
  echo json_encode(["status" => "error", "message" => "Lengkapi semua kolom. Password baru minimal 6 karakter."]);
  exit;
}

$_SESSION['fp_attempts'][] = time();

$stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Pesan generik untuk username tidak ditemukan maupun password lama salah,
// supaya tidak bisa dipakai untuk menebak username yang valid.
if (!$user || !password_verify($oldPassword, $user['password'])) {
  echo json_encode(["status" => "error", "message" => "Username atau password lama salah"]);
  exit;
}

$hashed = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $hashed, $user['id']);
$stmt->execute();
$ok = $stmt->affected_rows >= 0;
$stmt->close();

unset($_SESSION['fp_attempts']);
echo json_encode(["status" => "ok"]);
