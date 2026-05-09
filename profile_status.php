<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['error' => 'not_logged_in']);
  exit;
}

$id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT username, last_activity FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
  echo json_encode(['error' => 'not_found']);
  exit;
}

$online = !empty($user['last_activity']) && (time() - strtotime($user['last_activity'])) <= 300;

echo json_encode([
  'username' => $user['username'],
  'status'   => $online ? 'Online' : 'Offline'
]);
