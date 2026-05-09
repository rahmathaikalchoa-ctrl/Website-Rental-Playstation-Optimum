<?php
date_default_timezone_set('Asia/Jakarta');

$conn = new mysqli("localhost", "root", "", "gamezone");

if ($conn->connect_error) {
  error_log("DB connection failed: " . $conn->connect_error);
  die("Database connection error");
}

function updateUserActivity($conn, $userId) {
  $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $stmt->close();
}
