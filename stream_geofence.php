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
    $geofenceVehicles = [];
    $geofenceQuery = "
        SELECT target_name, assignment, status, source
        FROM (
            SELECT target_name, assignment, status, 'devices' as source
            FROM geofence 
            WHERE target_name IN (SELECT target_name FROM devices WHERE equipment_type IS NOT NULL)
            UNION
            SELECT target_name, assignment, status, 'komtrax' as source
            FROM geofence 
            WHERE target_name IN (SELECT target_name FROM komtrax WHERE equipment_type IS NOT NULL)
        ) AS combined_vehicles";
    $geofenceResult = $conn->query($geofenceQuery);
    if ($geofenceResult) {
        while ($row = $geofenceResult->fetch_assoc()) {
            $geofenceVehicles[] = [
                'target_name' => $row['target_name'],
                'assignment' => $row['assignment'],
                'status' => $row['status'],
                'source' => $row['source'],
                'is_inside' => stripos($row['status'], 'inside') !== false
            ];
        }
        $geofenceResult->free();
    } else {
        error_log("Geofence query failed: " . $conn->error);
        echo "data: " . json_encode(['success' => false, 'error' => 'Geofence query failed']) . "\n\n";
        flush();
        break;
    }

    echo "data: " . json_encode(['success' => true, 'geofenceVehicles' => $geofenceVehicles]) . "\n\n";
    flush();

    sleep(5); // Update every 5 seconds
}

$conn->close();
?>