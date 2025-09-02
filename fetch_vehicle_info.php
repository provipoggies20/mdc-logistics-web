<?php
require 'db_connect.php';

header('Content-Type: application/json');

$target_name = isset($_GET['target_name']) ? $_GET['target_name'] : '';

if (empty($target_name)) {
    echo json_encode(['error' => 'Target name is required']);
    exit;
}

// Query to fetch from devices table
$devicesQuery = "SELECT * FROM devices WHERE target_name = ?";
$stmt = $conn->prepare($devicesQuery);
$stmt->bind_param("s", $target_name);
$stmt->execute();
$result = $stmt->get_result();
$vehicle = $result->fetch_assoc();

if ($vehicle) {
    $vehicle['source'] = 'devices';
    echo json_encode($vehicle);
    $stmt->close();
    $conn->close();
    exit;
}

// If not found in devices, check komtrax table
$komtraxQuery = "SELECT * FROM komtrax WHERE target_name = ?";
$stmt = $conn->prepare($komtraxQuery);
$stmt->bind_param("s", $target_name);
$stmt->execute();
$result = $stmt->get_result();
$vehicle = $result->fetch_assoc();

if ($vehicle) {
    $vehicle['source'] = 'komtrax';
    echo json_encode($vehicle);
} else {
    echo json_encode(['error' => 'Vehicle not found']);
}

$stmt->close();
$conn->close();
?>