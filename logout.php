<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header("Content-Type: application/json");

// POST aja biar link dari situs lain gak bisa maksa logout
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}

// cuma akun user yg keluar, sesi admin tetap
unset($_SESSION['user_id'], $_SESSION['username']);
session_regenerate_id(true);

echo json_encode(["status" => "success"]);
