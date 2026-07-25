<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header("Content-Type: application/json");
require __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  echo json_encode(["status" => "error", "message" => "Invalid request"]);
  exit;
}

if (!checkRateLimit('register_attempts', 5, 300)) {
  echo json_encode(["status" => "error", "message" => "Terlalu banyak percobaan. Coba lagi dalam beberapa menit."]);
  exit;
}

$username = trim($_POST["username"] ?? "");
$password = trim($_POST["password"] ?? "");

if ($username === "" || $password === "") {
  echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
  exit;
}

if (strlen($password) < 6) {
  echo json_encode(["status" => "error", "message" => "Password minimal 6 karakter"]);
  exit;
}

try {
  $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $exist = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($exist) {
    echo json_encode(["status" => "error", "message" => "Username sudah digunakan"]);
    exit;
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
  $stmt->bind_param("ss", $username, $hash);
  $stmt->execute();
  $stmt->close();

  echo json_encode(["status" => "success"]);
} catch (\Throwable $e) {
  error_log("Registration failed: " . $e->getMessage());
  echo json_encode(["status" => "error", "message" => "Gagal membuat akun"]);
}
exit;
