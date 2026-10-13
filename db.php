<?php
date_default_timezone_set('Asia/Jakarta');

// tangkap exception DB yg lolos biar path/query gak tampil ke browser
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
// samain zona waktu MySQL sama PHP (WIB)
$conn->query("SET time_zone = '+07:00'");

// sidik password buat sesi, berubah kalo password diganti jadi device lain otomatis keluar
function passwordSig($hash) {
  return substr(hash('sha256', (string) $hash), 0, 16);
}

// keluarin sesi user kalo password udah diganti
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'], $_SESSION['pw_sig'])) {
  $sessUid = intval($_SESSION['user_id']);
  $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
  $stmt->bind_param("i", $sessUid);
  $stmt->execute();
  $sessRow = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$sessRow || !hash_equals($_SESSION['pw_sig'], passwordSig($sessRow['password']))) {
    unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['pw_sig']);
  }
  unset($sessUid, $sessRow);
}

function updateUserActivity($conn, $userId) {
  $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $stmt->close();
}

// key rate limit: per IP + username (kalo ada) biar 1 WiFi gak saling blokir
function rateLimitKey($action, $subject = '') {
  $key = $action . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  return $subject === '' ? $key : $key . ':' . mb_strtolower(mb_substr($subject, 0, 60));
}

// rate limit di DB biar gak bisa diakalin buang cookie
function checkRateLimit($action, $maxAttempts = 5, $windowSec = 300, $subject = '') {
  global $conn;
  $key = rateLimitKey($action, $subject);

  // bersih-bersih cuma pelengkap, kalo deadlock skip aja daripada request gagal
  try {
    $stmt = $conn->prepare("DELETE FROM rate_limits WHERE rl_key = ? AND created_at < (NOW() - INTERVAL ? SECOND)");
    $stmt->bind_param("si", $key, $windowSec);
    $stmt->execute();
    $stmt->close();
    // buang data lama sesekali aja, tiap request bikin saling ngunci
    if (mt_rand(1, 50) === 1) $conn->query("DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)");
  } catch (\mysqli_sql_exception $e) {
    error_log("rate limit cleanup skipped: " . $e->getMessage());
  }

  // catat dulu baru hitung biar request barengan saling keliatan
  for ($try = 1; ; $try++) {
    try {
      $stmt = $conn->prepare("INSERT INTO rate_limits (rl_key) VALUES (?)");
      $stmt->bind_param("s", $key);
      $stmt->execute();
      $stmt->close();
      break;
    } catch (\mysqli_sql_exception $e) {
      // 1213 deadlock, 1205 lock timeout: coba lagi
      if ($try >= 3 || !in_array($e->getCode(), [1213, 1205], true)) throw $e;
      usleep(20000 * $try);
    }
  }

  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM rate_limits WHERE rl_key = ?");
  $stmt->bind_param("s", $key);
  $stmt->execute();
  $count = intval($stmt->get_result()->fetch_assoc()['c']);
  $stmt->close();

  return $count <= $maxAttempts;
}

// sesi admin valid kalo akunnya masih admin di DB
function isAdminSession($conn) {
  if (empty($_SESSION['admin']) || empty($_SESSION['admin_id'])) return false;
  $id = intval($_SESSION['admin_id']);
  $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  // password admin diganti: sesi lama gak berlaku
  $ok = $row && (empty($_SESSION['admin_pw_sig']) || hash_equals($_SESSION['admin_pw_sig'], passwordSig($row['password'])));
  if (!$ok) unset($_SESSION['admin'], $_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_pw_sig']);
  return $ok;
}

function clearRateLimit($action, $subject = '') {
  global $conn;
  $key = rateLimitKey($action, $subject);
  $stmt = $conn->prepare("DELETE FROM rate_limits WHERE rl_key = ?");
  $stmt->bind_param("s", $key);
  $stmt->execute();
  $stmt->close();
}
