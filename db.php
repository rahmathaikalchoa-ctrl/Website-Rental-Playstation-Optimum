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

// Rate limit sederhana berbasis session (per key, misal "login_attempts").
// Panggil di awal endpoint; jika return false, langsung tolak requestnya.
function checkRateLimit($key, $maxAttempts = 5, $windowSec = 300) {
  $_SESSION[$key] = array_values(array_filter(
    $_SESSION[$key] ?? [],
    fn($t) => time() - $t < $windowSec
  ));
  if (count($_SESSION[$key]) >= $maxAttempts) {
    return false;
  }
  $_SESSION[$key][] = time();
  return true;
}
