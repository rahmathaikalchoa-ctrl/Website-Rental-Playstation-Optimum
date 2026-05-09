<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
header("Content-Type: application/json");

if (isset($_SESSION['user_id'])) {
  updateUserActivity($conn, (int) $_SESSION['user_id']);

  echo json_encode([
    "logged_in" => true,
    "username"  => $_SESSION['username']
  ]);
} else {
  echo json_encode(["logged_in" => false]);
}

session_write_close();
