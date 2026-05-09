<?php
// All admin actions are handled by admin_api.php
// This file is kept for backward compatibility but does nothing
require __DIR__ . '/db.php';
header('Content-Type: application/json');
echo json_encode(["status" => "error", "message" => "Use admin_api.php"]);
exit;
