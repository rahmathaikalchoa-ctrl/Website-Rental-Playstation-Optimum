<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['error' => 'not_logged_in']);
  exit;
}

$id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT username, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
  echo json_encode(['error' => 'not_found']);
  exit;
}

$bulan = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$ts = $user['created_at'] ? strtotime($user['created_at']) : null;

echo json_encode([
  'username' => $user['username'],
  'since'    => $ts ? date('j', $ts) . ' ' . $bulan[intval(date('n', $ts)) - 1] . ' ' . date('Y', $ts) : '-',
]);
