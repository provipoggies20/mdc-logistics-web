<?php
require 'db_connect.php';
session_start();

// Ensure notification uniqueness logic based on vehicle ID
function insertNotification($conn, $vehicleId, $message) {
    $stmt = $conn->prepare("SELECT 1 FROM notifications WHERE vehicle_id = ?");
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        $insert = $conn->prepare("INSERT INTO notifications (vehicle_id, message) VALUES (?, ?)");
        $insert->bind_param("is", $vehicleId, $message);
        $insert->execute();
        $insert->close();
    }

    $stmt->close();
}

// Fetch vehicles with overdue contracts (including breakdown vehicles)
$overdueQuery = "SELECT id, target_name, equipment_type, days_lapses, physical_status 
                 FROM devices 
                 WHERE days_lapses >= 1";
$overdueResult = $conn->query($overdueQuery);

$overdueVehicles = [];
while ($row = $overdueResult->fetch_assoc()) {
    $overdueVehicles[$row['id']] = $row;
}

// Fetch vehicles with maintenance issues (only breakdown vehicles)
$maintenanceQuery = "SELECT id, target_name, equipment_type 
                     FROM devices 
                     WHERE physical_status = 'Breakdown'";
$maintenanceResult = $conn->query($maintenanceQuery);

$maintenanceVehicles = [];
while ($row = $maintenanceResult->fetch_assoc()) {
    $maintenanceVehicles[$row['id']] = $row;
}

// Combine notifications for vehicles with both overdue and maintenance issues
foreach ($overdueVehicles as $id => $vehicle) {
    if (isset($maintenanceVehicles[$id])) {
        $msg = "🚨🛠 Vehicle <b>{$vehicle['target_name']}</b> ({$vehicle['equipment_type']}) has an overdue contract and requires maintenance.";
        insertNotification($conn, $id, $msg);
        unset($maintenanceVehicles[$id]);
    } else {
        $msg = "🚨 Vehicle <b>{$vehicle['target_name']}</b> ({$vehicle['equipment_type']}) has an overdue contract.";
        insertNotification($conn, $id, $msg);
    }
}

// Add remaining vehicles with only maintenance issues
foreach ($maintenanceVehicles as $id => $vehicle) {
    $msg = "🛠 Vehicle <b>{$vehicle['target_name']}</b> ({$vehicle['equipment_type']}) requires maintenance.";
    insertNotification($conn, $id, $msg);
}

// Fetch all notifications with read status for the current Main Admin
$notifications = [];
if ($_SESSION['role'] === 'Main Admin') {
    $userId = $_SESSION['user_id'];
    $notifQuery = "
        SELECT n.id, n.message, d.target_name, d.equipment_type, d.physical_status,
               CASE WHEN nv.notification_id IS NULL THEN 0 ELSE 1 END as read_status
        FROM notifications n
        JOIN devices d ON n.vehicle_id = d.id
        LEFT JOIN notification_views nv ON n.id = nv.notification_id AND nv.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 20
    ";
    $stmt = $conn->prepare($notifQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = [
            'id' => $row['id'],
            'message' => $row['message'],
            'target_name' => $row['target_name'],
            'equipment_type' => $row['equipment_type'],
            'physical_status' => $row['physical_status'],
            'read_status' => $row['read_status']
        ];
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($notifications);

$conn->close();
?>