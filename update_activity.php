<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['status' => 'no-session']);
  exit;
}

updateUserActivity($conn, (int) $_SESSION['user_id']);
echo json_encode(['status' => 'updated']);
