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
// Samakan zona waktu NOW()/TIMESTAMP MySQL dengan PHP (WIB)
$conn->query("SET time_zone = '+07:00'");

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
  // Buang sisa percobaan lama dari IP/aksi lain supaya tabel tidak tumbuh terus
  $conn->query("DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)");

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

// Sesi admin valid hanya jika akunnya masih ber-role admin di DB
// (admin yang sudah diturunkan langsung kehilangan akses).
function isAdminSession($conn) {
  if (empty($_SESSION['admin']) || empty($_SESSION['admin_id'])) return false;
  $id = intval($_SESSION['admin_id']);
  $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin'");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $ok = (bool) $stmt->get_result()->fetch_row();
  $stmt->close();
  if (!$ok) unset($_SESSION['admin'], $_SESSION['admin_id'], $_SESSION['admin_username']);
  return $ok;
}

function clearRateLimit($action) {
  global $conn;
  $key = $action . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  $stmt = $conn->prepare("DELETE FROM rate_limits WHERE rl_key = ?");
  $stmt->bind_param("s", $key);
  $stmt->execute();
  $stmt->close();
}
