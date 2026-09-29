<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header("Content-Type: application/json");
require __DIR__ . "/db.php";

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($username === '' || $password === '') {
  echo json_encode(["status" => "error", "message" => "Username dan password wajib diisi"]);
  exit;
}

if (!checkRateLimit('login_attempts', 5, 300, $username)) {
  echo json_encode(["status" => "error", "message" => "Terlalu banyak percobaan login. Coba lagi dalam beberapa menit."]);
  exit;
}

try {
  $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || !password_verify($password, $user['password'])) {
    echo json_encode(["status" => "error", "message" => "Username atau password salah"]);
    exit;
  }

  clearRateLimit('login_attempts', $username);
  session_regenerate_id(true);
  $_SESSION['user_id'] = $user['id'];
  $_SESSION['username'] = $user['username'];

  updateUserActivity($conn, $user['id']);

  session_write_close();

  echo json_encode(["status" => "success", "username" => $user['username']]);
} catch (\Throwable $e) {
  error_log("Login failed: " . $e->getMessage());
  echo json_encode(["status" => "error", "message" => "Terjadi kesalahan pada server"]);
}
