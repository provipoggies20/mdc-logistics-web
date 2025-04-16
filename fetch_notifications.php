<?php
require 'db_connect.php';

// Query to get the count of overdue vehicles
$query = "SELECT COUNT(*) AS overdue_count FROM devices WHERE days_elapsed > days_contract";
$result = $conn->query($query);

$response = ['overdue_count' => 0];

if ($result) {
    $row = $result->fetch_assoc();
    $response['overdue_count'] = $row['overdue_count'];
}

$conn->close();

// Return response in JSON format
echo json_encode($response);
?>
