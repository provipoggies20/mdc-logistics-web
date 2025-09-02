<?php
// notification_storage.php

include 'config.php';
header('Content-Type: application/json');

// Store notification (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $message = $_POST['message'] ?? '';
    $timestamp = date('Y-m-d H:i:s');

    if ($type && $message) {
        $stmt = $conn->prepare("INSERT INTO notifications (type, message, created_at) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $type, $message, $timestamp);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data.']);
    }
    exit;
}

// Fetch notifications (GET)
$result = $conn->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 50");
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
echo json_encode($notifications);
?>
