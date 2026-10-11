<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header("Content-Type: application/json");
require __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
  updateUserActivity($conn, (int) $_SESSION['user_id']);

  echo json_encode([
    "logged_in" => true,
    "username"  => $_SESSION['username'],
    "server_time" => time(),
  ]);
} else {
  echo json_encode(["logged_in" => false, "server_time" => time()]);
}

session_write_close();
