<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error"]);
  exit;
}

$action = $_POST['action'] ?? '';

// ===== CEK USERNAME =====
if ($action === 'check') {
  $username = trim($_POST['username'] ?? '');
  if ($username === '') {
    echo json_encode(["status" => "error", "message" => "Username tidak boleh kosong"]);
    exit;
  }
  $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $found = $stmt->get_result()->num_rows > 0;
  $stmt->close();
  echo json_encode($found
    ? ["status" => "ok"]
    : ["status" => "error", "message" => "Username tidak ditemukan"]
  );
  exit;
}

// ===== RESET PASSWORD =====
if ($action === 'reset') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username === '' || strlen($password) < 6) {
    echo json_encode(["status" => "error", "message" => "Password minimal 6 karakter"]);
    exit;
  }

  $hashed = password_hash($password, PASSWORD_DEFAULT);
  $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
  $stmt->bind_param("ss", $hashed, $username);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();

  echo json_encode($ok
    ? ["status" => "ok"]
    : ["status" => "error", "message" => "Gagal mereset password"]
  );
  exit;
}

echo json_encode(["status" => "error", "message" => "Action tidak dikenal"]);
