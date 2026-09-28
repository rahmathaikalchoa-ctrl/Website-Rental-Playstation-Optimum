<?php
date_default_timezone_set('Asia/Jakarta');

// Tangkap exception/error DB yang tidak sengaja lolos (mis. mysqli_sql_exception)
// supaya detail internal (path file, query) tidak pernah tampil ke browser.
set_exception_handler(function ($e) {
  error_log("Unhandled exception: " . $e->getMessage());
  if (!headers_sent()) {
    $isJson = false;
    foreach (headers_list() as $h) {
      if (stripos($h, 'Content-Type: application/json') !== false) { $isJson = true; break; }
    }
    http_response_code(500);
    if ($isJson) {
      echo json_encode(["status" => "error", "message" => "Terjadi kesalahan pada server"]);
    } else {
      echo "Terjadi kesalahan pada server.";
    }
  }
  exit;
});

$conn = new mysqli("localhost", "root", "", "gamezone");

if ($conn->connect_error) {
  error_log("DB connection failed: " . $conn->connect_error);
  die("Database connection error");
}

function updateUserActivity($conn, $userId) {
  $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $stmt->close();
}

// Rate limit per IP, disimpan di DB (bukan session) supaya tidak bisa
// dilewati dengan membuang cookie session di tiap request.
function checkRateLimit($action, $maxAttempts = 5, $windowSec = 300) {
  global $conn;
  $key = $action . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

  $stmt = $conn->prepare("DELETE FROM rate_limits WHERE rl_key = ? AND created_at < (NOW() - INTERVAL ? SECOND)");
  $stmt->bind_param("si", $key, $windowSec);
  $stmt->execute();
  $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM rate_limits WHERE rl_key = ?");
  $stmt->bind_param("s", $key);
  $stmt->execute();
  $count = intval($stmt->get_result()->fetch_assoc()['c']);
  $stmt->close();

  if ($count >= $maxAttempts) {
    return false;
  }

  $stmt = $conn->prepare("INSERT INTO rate_limits (rl_key) VALUES (?)");
  $stmt->bind_param("s", $key);
  $stmt->execute();
  $stmt->close();
  return true;
}

function clearRateLimit($action) {
  global $conn;
  $key = $action . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  $stmt = $conn->prepare("DELETE FROM rate_limits WHERE rl_key = ?");
  $stmt->bind_param("s", $key);
  $stmt->execute();
  $stmt->close();
}
