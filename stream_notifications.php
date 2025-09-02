<?php
session_start();
require 'db_connect.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Ensure the user is a Main Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Main Admin') {
    echo "data: []\n\n";
    flush();
    exit();
}

$user_id = $_SESSION['user_id'];
$last_id = 0; // Track the last notification ID sent

while (true) {
    // Query for new notifications since last_id
    $sql = "
        SELECT n.id, n.message, n.target_name, n.equipment_type, 
               COALESCE(nv.viewed_at, 0) AS read_status
        FROM notifications n
        LEFT JOIN notification_views nv ON n.id = nv.notification_id AND nv.user_id = ?
        WHERE n.id > ?
        ORDER BY n.id DESC
        LIMIT 20
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $user_id, $last_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = [
            'id' => $row['id'],
            'message' => $row['message'],
            'target_name' => $row['target_name'],
            'equipment_type' => $row['equipment_type'],
            'read_status' => $row['read_status'] == 0 ? 0 : 1
        ];
        $last_id = max($last_id, $row['id']);
    }
    $stmt->close();

    // Send notifications as SSE data
    if (!empty($notifications)) {
        echo "data: " . json_encode($notifications) . "\n\n";
        flush();
    } else {
        // Send heartbeat to keep connection alive
        echo ": heartbeat\n\n";
        flush();
    }

    // Sleep for a short period to avoid overloading the server
    sleep(1);

    // Optional: Break the loop after a long period to prevent infinite loops
    // Reconnection will be handled by the client
    if (connection_aborted()) {
        break;
    }
}
$conn->close();
?>