<?php
require 'db_connect.php';

$query = "SELECT id, license_plate_no, days_elapsed, days_contract FROM devices WHERE days_elapsed > days_contract ORDER BY days_elapsed DESC";
$result = $conn->query($query);

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => $row['id'],
        'license_plate_no' => $row['license_plate_no'],
        'days_elapsed' => $row['days_elapsed'],
        'days_contract' => $row['days_contract']
    ];
}

header('Content-Type: application/json');
echo json_encode($notifications);
?>
