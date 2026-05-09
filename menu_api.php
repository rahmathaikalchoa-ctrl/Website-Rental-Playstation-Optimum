<?php
require 'db.php';
header('Content-Type: application/json');

$result = $conn->query("SELECT * FROM menu_items ORDER BY category ASC, name ASC");
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
echo json_encode($items);
