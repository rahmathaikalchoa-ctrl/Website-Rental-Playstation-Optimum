<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/queue_lib.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(["status" => "error", "message" => "Belum login"]);
  exit;
}

$userId = intval($_SESSION['user_id']);
$action = $_POST['action'] ?? $_GET['action'] ?? 'status';

function myLatestEntry($conn, $userId) {
  $stmt = $conn->prepare("
    SELECT q.*, r.title AS room_title, b.end_time
    FROM booking_queue q
    LEFT JOIN bookings b ON b.id = q.booking_id
    LEFT JOIN rooms r    ON r.id = b.room_id
    WHERE q.user_id = ? AND q.status IN ('waiting', 'assigned')
    ORDER BY q.id DESC LIMIT 1
  ");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row;
}

if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $console  = $_POST['console_type'] ?? '';
  $duration = intval($_POST['duration'] ?? 0);

  if (!in_array($console, QUEUE_CONSOLES, true) || $duration < 1 || $duration > 12) {
    echo json_encode(["status" => "error", "message" => "Data antrian tidak valid"]);
    exit;
  }
  $now = time();
  if (intval(date('G', $now)) < QUEUE_OPEN_HOUR) {
    echo json_encode(["status" => "error", "message" => "Antrian dibuka mulai jam 11:00"]);
    exit;
  }
  if ($now + $duration * 3600 > queueCloseLimit($now)) {
    echo json_encode(["status" => "error", "message" => "Durasi melewati jam tutup (tengah malam)"]);
    exit;
  }

  $stmt = $conn->prepare("SELECT id FROM booking_queue WHERE user_id = ? AND status = 'waiting' LIMIT 1");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $exists = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($exists) {
    echo json_encode(["status" => "error", "message" => "Kamu sudah ada di antrian"]);
    exit;
  }

  $name = $_SESSION['username'] ?? 'User';
  $stmt = $conn->prepare("INSERT INTO booking_queue (source, user_id, customer_name, console_type, duration) VALUES ('online', ?, ?, ?, ?)");
  $stmt->bind_param("issi", $userId, $name, $console, $duration);
  $stmt->execute();
  $stmt->close();
  $action = 'status';
}

if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $conn->prepare("UPDATE booking_queue SET status = 'cancelled' WHERE user_id = ? AND status = 'waiting'");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "ok"]);
  exit;
}

if ($action === 'status') {
  processQueue($conn);
  $e = myLatestEntry($conn, $userId);

  // Entry "assigned" hanya relevan selama sesinya belum selesai
  if (!$e || ($e['status'] === 'assigned' && intval($e['end_time']) <= time())) {
    echo json_encode(["status" => "ok", "entry" => null]);
    exit;
  }

  $out = [
    "id"           => intval($e['id']),
    "state"        => $e['status'],
    "console_type" => $e['console_type'],
    "duration"     => intval($e['duration']),
  ];
  if ($e['status'] === 'waiting') {
    $out['position'] = queuePosition($conn, $e);
  } else {
    $out['room']     = $e['room_title'];
    $out['end_time'] = intval($e['end_time']);
  }
  echo json_encode(["status" => "ok", "entry" => $out]);
  exit;
}

echo json_encode(["status" => "error", "message" => "Action tidak dikenal"]);
