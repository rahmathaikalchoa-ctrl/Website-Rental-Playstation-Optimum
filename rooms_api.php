<?php
require 'db.php';

$sql = "
  SELECT
    r.id, r.title, r.console_type, r.price, r.image, r.description, r.status,
    CASE
      WHEN r.status = 'unavailable' THEN 'in_service'
      WHEN EXISTS (
        SELECT 1 FROM bookings b
        WHERE b.room_id = r.id
          AND UNIX_TIMESTAMP() >= b.start_time AND UNIX_TIMESTAMP() < b.end_time
      ) THEN 'occupied'
      ELSE 'available'
    END AS current_status
  FROM rooms r
  ORDER BY r.id ASC
";

$result = mysqli_query($conn, $sql);

$rooms = [];

while ($row = mysqli_fetch_assoc($result)) {
  $rooms[] = $row;
}

header('Content-Type: application/json');
echo json_encode($rooms);
