<?php
require 'db_connect.php';
session_start();

if ($_SESSION['role'] === 'Main Admin') {
    $userId = $_SESSION['user_id'];
    $query = "INSERT INTO notification_views (user_id, notification_id) 
              SELECT ?, n.id FROM notifications n 
              LEFT JOIN notification_views nv ON n.id = nv.notification_id AND nv.user_id = ?
              WHERE nv.notification_id IS NULL";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
}
$conn->close();
?>