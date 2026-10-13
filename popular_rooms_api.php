<?php
header('Content-Type: application/json');
require 'db.php';
require_once __DIR__ . '/booking_lib.php';
// batalin yg lewat batas check-in dulu biar status bener
releaseNoShows($conn);

$sql = "
  SELECT
    r.id, r.title, r.category, r.tier, r.console_type, r.console_model, r.price, r.weekend_extra,
    r.nintendo_extra, r.max_players, r.has_netflix, r.image, r.description, r.status,
    -- Daftar game khusus ruangan ini (kosong = pakai semua game konsolnya)
    (SELECT GROUP_CONCAT(rg.game_id) FROM room_games rg WHERE rg.room_id = r.id) AS game_ids,
    (SELECT ROUND(AVG(rv.rating), 1) FROM room_reviews rv WHERE rv.room_id = r.id) AS avg_rating,
    (SELECT COUNT(*) FROM room_reviews rv WHERE rv.room_id = r.id) AS review_count,
    COUNT(b.id) AS booking_count,
    CASE
      WHEN EXISTS (
        SELECT 1 FROM bookings b2
        WHERE b2.room_id = r.id
          AND b2.payment_status <> 'cancelled'
          AND UNIX_TIMESTAMP() >= b2.start_time AND UNIX_TIMESTAMP() < b2.end_time
      ) THEN 'occupied'
      ELSE 'available'
    END AS current_status
  FROM rooms r
  LEFT JOIN bookings b ON b.room_id = r.id AND b.payment_status <> 'cancelled'
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
