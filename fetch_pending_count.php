<?php
require 'db_connect.php';

$editQuery = "SELECT COUNT(*) as count FROM pending_edits WHERE status = 'pending'";
$vehicleQuery = "SELECT COUNT(*) as count FROM pending_vehicles WHERE pending_status = 'Pending'";

$count = 0;

if ($result = $conn->query($editQuery)) {
    $count += $result->fetch_assoc()['count'];
}
if ($result = $conn->query($vehicleQuery)) {
    $count += $result->fetch_assoc()['count'];
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo json_encode(['count' => $count]);
?>