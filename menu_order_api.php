<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require 'db.php';
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

// GET — daftar pesanan milik user yg sedang login
if ($action === 'my_orders') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([]);
        exit;
    }
    $uid = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("
        SELECT mo.id, mo.quantity, mo.note, mo.status,
               mo.created_at,
               mi.name, mi.category, mi.price, mi.image
        FROM menu_orders mo
        JOIN menu_items mi ON mi.id = mo.item_id
        WHERE mo.user_id = ?
        ORDER BY mo.created_at DESC
        LIMIT 30
    ");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    exit;
}

// POST — buat pesanan baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'order') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Belum login"]);
        exit;
    }

    $uid      = intval($_SESSION['user_id']);
    $itemId   = intval($_POST['item_id'] ?? 0);
    $quantity = max(1, min(20, intval($_POST['quantity'] ?? 1)));
    $note     = trim($_POST['note'] ?? '');

    // pastikan item ada dan tersedia
    $chk = $conn->prepare("SELECT id FROM menu_items WHERE id = ? AND is_available = 1");
    $chk->bind_param("i", $itemId);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        echo json_encode(["status" => "error", "message" => "Item tidak tersedia"]);
        $chk->close();
        exit;
    }
    $chk->close();

    // Kalau user sedang punya booking aktif, tautkan pesanan ke booking itu
    // supaya nanti bisa ditagih jadi satu (ruangan + jajanan).
    $now = time();
    $bk = $conn->prepare("
        SELECT id FROM bookings
        WHERE user_id = ? AND start_time <= ? AND end_time > ?
        ORDER BY start_time DESC LIMIT 1
    ");
    $bk->bind_param("iii", $uid, $now, $now);
    $bk->execute();
    $activeBooking = $bk->get_result()->fetch_assoc();
    $bk->close();
    $bookingId = $activeBooking ? intval($activeBooking['id']) : null;

    $stmt = $conn->prepare("INSERT INTO menu_orders (user_id, item_id, booking_id, quantity, note) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiis", $uid, $itemId, $bookingId, $quantity, $note);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["status" => "ok"]);
    exit;
}

echo json_encode(["status" => "error", "message" => "Invalid action"]);
