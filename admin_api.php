<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin'])) {
  echo json_encode(["status" => "unauthorized"]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["status" => "error", "message" => "Invalid request"]);
  exit;
}

$action = $_POST['action'] ?? '';

// ================= DELETE BOOKING =================
if ($action === 'delete_booking') {
  $id = intval($_POST['id'] ?? 0);
  $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "ok"]);
  exit;
}

// ================= ADD ROOM =================
if ($action === 'add_room') {
  $title       = trim($_POST['title'] ?? '');
  $console     = trim($_POST['console'] ?? '');
  $price       = intval($_POST['price'] ?? 0);
  $description = trim($_POST['description'] ?? '');

  $allowedConsoles = ['PS3', 'PS4', 'PS5'];
  if ($title === '' || !in_array($console, $allowedConsoles, true) || $price <= 0) {
    echo json_encode(["status" => "error", "message" => "Data room tidak lengkap atau tidak valid"]);
    exit;
  }

  $stmt = $conn->prepare("
    INSERT INTO rooms (title, console_type, price, description, status)
    VALUES (?, ?, ?, ?, 'available')
  ");
  $stmt->bind_param("ssis", $title, $console, $price, $description);
  $stmt->execute();
  $stmt->close();

  echo json_encode(["status" => "ok"]);
  exit;
}

// ================= TOGGLE ROOM =================
if ($action === 'toggle_room') {
  $id = intval($_POST['id'] ?? 0);
  $stmt = $conn->prepare("
    UPDATE rooms SET status = IF(status='available','unavailable','available') WHERE id = ?
  ");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "ok"]);
  exit;
}

// ================= TOGGLE ROLE =================
if ($action === 'toggle_role') {
  $id = intval($_POST['id'] ?? 0);
  $stmt = $conn->prepare("UPDATE users SET role = IF(role='user','admin','user') WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  echo json_encode(["status" => "ok"]);
  exit;
}

// ================= ADD GAME =================
if ($action === 'add_game') {
  $title   = trim($_POST['title'] ?? '');
  $genre   = trim($_POST['genre'] ?? '');
  $consoles = array_filter($_POST['consoles'] ?? [], function($c) {
    return in_array($c, ['PS3', 'PS4', 'PS5'], true);
  });

  if ($title === '' || $genre === '' || empty($consoles)) {
    echo json_encode(["status" => "error", "message" => "Lengkapi data game & pilih console"]);
    exit;
  }

  $imageName = null;
  if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
      $tmpName = uniqid('game_') . '.' . $ext;
      $dir     = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'games';
      if (!is_dir($dir)) mkdir($dir, 0755, true);
      $dest = $dir . DIRECTORY_SEPARATOR . $tmpName;
      if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) {
        $imageName = $tmpName;
      }
    }
  }

  $conn->begin_transaction();

  try {
    $stmt = $conn->prepare("INSERT INTO games (title, genre, image, is_active) VALUES (?, ?, ?, 1)");
    $stmt->bind_param("sss", $title, $genre, $imageName);
    $stmt->execute();
    $gameId = $stmt->insert_id;
    $stmt->close();

    $stmt2 = $conn->prepare("INSERT INTO game_consoles (game_id, console_type) VALUES (?, ?)");
    foreach ($consoles as $c) {
      $stmt2->bind_param("is", $gameId, $c);
      $stmt2->execute();
    }
    $stmt2->close();

    $conn->commit();
    echo json_encode([
      "status"      => "ok",
      "image_saved" => $imageName !== null,
    ]);
    exit;

  } catch (Exception $e) {
    $conn->rollback();
    error_log("Add game failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Gagal menyimpan game"]);
    exit;
  }
}

// ================= DELETE GAME =================
if ($action === 'delete_game') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM games WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "ok"]);
    exit;
}

// ================= UPDATE GAME CONSOLES =================
if ($action === 'update_game_consoles') {
    $id = intval($_POST['id'] ?? 0);
    $consoles = array_filter($_POST['consoles'] ?? [], fn($c) => in_array($c, ['PS3','PS4','PS5'], true));

    if ($id <= 0 || empty($consoles)) {
        echo json_encode(["status" => "error", "message" => "Data tidak valid"]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM game_consoles WHERE game_id = ?");
        $del->bind_param("i", $id);
        $del->execute();
        $del->close();

        $ins = $conn->prepare("INSERT INTO game_consoles (game_id, console_type) VALUES (?, ?)");
        foreach ($consoles as $c) {
            $ins->bind_param("is", $id, $c);
            $ins->execute();
        }
        $ins->close();

        $conn->commit();
        echo json_encode(["status" => "ok"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => "Gagal update"]);
    }
    exit;
}

// ================= MARK ORDER DONE =================
if ($action === 'mark_order_done') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE menu_orders SET status = 'selesai' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "ok"]);
    exit;
}

// ================= ADD MENU ITEM =================
if ($action === 'add_menu_item') {
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $price       = intval($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($name === '' || !in_array($category, ['makanan', 'minuman'], true) || $price <= 0) {
        echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
        exit;
    }

    $imageName = null;
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['item_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $tmpName = uniqid('menu_') . '.' . $ext;
            $dir  = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'menu';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $dest = $dir . DIRECTORY_SEPARATOR . $tmpName;
            if (move_uploaded_file($_FILES['item_image']['tmp_name'], $dest)) {
                $imageName = $tmpName;
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO menu_items (name, category, price, description, image) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiss", $name, $category, $price, $description, $imageName);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["status" => "ok"]);
    exit;
}

// ================= TOGGLE MENU ITEM =================
if ($action === 'toggle_menu_item') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE menu_items SET is_available = IF(is_available=1,0,1) WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "ok"]);
    exit;
}

// ================= DELETE MENU ITEM =================
if ($action === 'delete_menu_item') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "ok"]);
    exit;
}

echo json_encode(["status" => "error", "message" => "Action tidak dikenal"]);
exit;
