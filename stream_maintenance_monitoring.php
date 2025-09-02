<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

require 'db_connect.php';

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    echo "data: " . json_encode(['success' => false, 'error' => 'Database connection failed']) . "\n\n";
    flush();
    exit;
}

while (true) {
    $maintenanceVehicles = [];
    $maintenanceQuery = "
        SELECT target_name, equipment_type, physical_status, source
        FROM (
            SELECT target_name, equipment_type, physical_status, 'devices' as source
            FROM devices 
            WHERE equipment_type IS NOT NULL AND LOWER(physical_status) = 'breakdown'
            UNION
            SELECT target_name, equipment_type, physical_status, 'komtrax' as source
            FROM komtrax 
            WHERE equipment_type IS NOT NULL AND LOWER(physical_status) = 'breakdown'
        ) AS combined_vehicles";
    $maintenanceResult = $conn->query($maintenanceQuery);
    if ($maintenanceResult) {
        while ($row = $maintenanceResult->fetch_assoc()) {
            $maintenanceVehicles[] = [
                'target_name' => $row['target_name'],
                'equipment_type' => $row['equipment_type'],
                'physical_status' => $row['physical_status'] ?? 'N/A',
                'source' => $row['source']
            ];
        }
        $maintenanceResult->free();
    } else {
        error_log("Maintenance query failed: " . $conn->error);
        echo "data: " . json_encode(['success' => false, 'error' => 'Maintenance query failed']) . "\n\n";
        flush();
        break;
    }

    echo "data: " . json_encode(['success' => true, 'maintenanceVehicles' => $maintenanceVehicles]) . "\n\n";
    flush();

    sleep(5); // Update every 5 seconds
}

$conn->close();
?>