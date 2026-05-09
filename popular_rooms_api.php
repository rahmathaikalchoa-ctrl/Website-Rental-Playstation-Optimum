<?php
require 'db.php';
header('Content-Type: application/json');

$sql = "
  SELECT
    r.id, r.title, r.console_type, r.price, r.image, r.description, r.status,
    COUNT(b.id) AS booking_count,
    CASE
      WHEN r.status = 'unavailable' THEN 'in_service'
      WHEN EXISTS (
        SELECT 1 FROM bookings b2
        WHERE b2.room_id = r.id
          AND UNIX_TIMESTAMP() >= b2.start_time AND UNIX_TIMESTAMP() < b2.end_time
      ) THEN 'occupied'
      ELSE 'available'
    END AS current_status
  FROM rooms r
  LEFT JOIN bookings b ON b.room_id = r.id
  WHERE r.status = 'available'
  GROUP BY r.id
  ORDER BY booking_count DESC, r.id ASC
  LIMIT 3
";

$result = mysqli_query($conn, $sql);
$rooms  = [];

while ($row = mysqli_fetch_assoc($result)) {
  $rooms[] = $row;
}

echo json_encode($rooms);
