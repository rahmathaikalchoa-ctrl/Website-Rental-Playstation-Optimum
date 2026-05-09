<?php
require 'db.php';
header('Content-Type: application/json');

$sql = "
  SELECT
    g.id,
    g.title,
    g.genre,
    g.image,
    GROUP_CONCAT(gc.console_type) AS consoles
  FROM games g
  LEFT JOIN game_consoles gc ON g.id = gc.game_id
  WHERE g.is_active = 1
  GROUP BY g.id
  ORDER BY g.title ASC
";

$res = $conn->query($sql);
$data = [];

while ($row = $res->fetch_assoc()) {
  $data[] = [
    "id" => (int)$row["id"],
    "title" => $row["title"],
    "genre" => $row["genre"],
    "image" => $row["image"],
    "platforms" => $row["consoles"] ? explode(",", $row["consoles"]) : []
  ];
}

echo json_encode($data);
