<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header("Content-Type: application/json");

// Hanya keluarkan akun user; sesi admin di browser yang sama tetap jalan
unset($_SESSION['user_id'], $_SESSION['username']);
session_regenerate_id(true);

echo json_encode(["status" => "success"]);
