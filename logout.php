<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header("Content-Type: application/json");

// Hanya POST: link GET dari situs lain tidak bisa memaksa logout
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Permintaan tidak valid"]);
  exit;
}

// Hanya keluarkan akun user; sesi admin di browser yang sama tetap jalan
unset($_SESSION['user_id'], $_SESSION['username']);
session_regenerate_id(true);

echo json_encode(["status" => "success"]);
