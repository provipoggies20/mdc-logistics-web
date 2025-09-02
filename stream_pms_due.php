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
    $dueVehicles = [];
    $pmsQuery = "
        SELECT target_name, equipment_type, 
            CASE 
                WHEN next_pms_date <= CURDATE() THEN 'Due'
                WHEN next_pms_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Nearing'
            END AS status
        FROM (
            SELECT target_name, equipment_type, next_pms_date
            FROM devices 
            WHERE equipment_type IS NOT NULL AND next_pms_date IS NOT NULL AND next_pms_date != '0000-00-00'
            UNION
            SELECT target_name, equipment_type, next_pms_date
            FROM komtrax 
            WHERE equipment_type IS NOT NULL AND next_pms_date IS NOT NULL AND next_pms_date != '0000-00-00'
        ) AS combined_vehicles
        WHERE next_pms_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    $pmsResult = $conn->query($pmsQuery);
    if ($pmsResult) {
        while ($row = $pmsResult->fetch_assoc()) {
            $dueVehicles[] = [
                'target_name' => $row['target_name'],
                'equipment_type' => $row['equipment_type'],
                'status' => $row['status']
            ];
        }
        $pmsResult->free();
    } else {
        error_log("PMS query failed: " . $conn->error);
        echo "data: " . json_encode(['success' => false, 'error' => 'PMS query failed']) . "\n\n";
        flush();
        break;
    }

    echo "data: " . json_encode(['success' => true, 'dueVehicles' => $dueVehicles]) . "\n\n";
    flush();

    sleep(5); // Update every 5 seconds
}

$conn->close();
?>