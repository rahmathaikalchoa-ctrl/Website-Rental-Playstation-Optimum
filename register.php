<?php
header("Content-Type: application/json");
require __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  echo json_encode(["status" => "error", "message" => "Invalid request"]);
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

if ($stmt->execute()) {
  echo json_encode(["status" => "success"]);
} else {
  error_log("Registration failed: " . $stmt->error);
  echo json_encode(["status" => "error", "message" => "Gagal membuat akun"]);
}
$stmt->close();
exit;
