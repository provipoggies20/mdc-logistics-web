<?php
require 'db_connect.php';
header('Content-Type: application/json');

// Store notification (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $message = $_POST['message'] ?? '';
    $target_name = $_POST['target_name'] ?? '';
    $timestamp = date('Y-m-d H:i:s');

    if ($type && $message && $target_name) {
        $stmt = $conn->prepare("INSERT INTO geofence_notifications (type, message, target_name, created_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $type, $message, $target_name, $timestamp);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data.']);
    }
    exit;
}

// Fetch notifications (GET)
$result = $conn->query("SELECT * FROM geofence_notifications ORDER BY created_at DESC LIMIT 50");
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
echo json_encode($notifications);

$conn->close();
?>