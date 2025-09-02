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
    $contractVehicles = [];
    $contractQuery = "
        SELECT target_name, assignment, date_transferred, days_contract, date_ended, source
        FROM (
            SELECT target_name, assignment, date_transferred, days_contract, date_ended, 'devices' as source
            FROM devices 
            WHERE equipment_type IS NOT NULL AND date_ended IS NOT NULL
            UNION
            SELECT target_name, assignment, date_transferred, days_contract, date_ended, 'komtrax' as source
            FROM komtrax 
            WHERE equipment_type IS NOT NULL AND date_ended IS NOT NULL
        ) AS combined_vehicles";
    $contractResult = $conn->query($contractQuery);
    if ($contractResult) {
        while ($row = $contractResult->fetch_assoc()) {
            $days_elapsed = 0;
            $days_lapses = 0;
            $date_transferred = $row['date_transferred'] ? new DateTime($row['date_transferred']) : null;
            $date_ended = $row['date_ended'] ? new DateTime($row['date_ended']) : null;
            $current = new DateTime();

            if ($date_transferred) {
                $interval = $current->diff($date_transferred);
                $days_elapsed = $interval->days;
            }
            if ($date_ended && $date_ended <= $current) {
                $interval = $current->diff($date_ended);
                $days_lapses = $interval->days;
            }
            $days_contract = (int)($row['days_contract'] ?? 0);
            $is_overdue = $days_elapsed > $days_contract && $days_contract > 0;

            if ($is_overdue && $days_lapses > 0 && $days_elapsed < 5000) {
                $contractVehicles[] = [
                    'target_name' => $row['target_name'],
                    'assignment' => $row['assignment'],
                    'days_elapsed' => $days_elapsed,
                    'days_lapses' => $days_lapses,
                    'source' => $row['source'],
                    'is_overdue' => $is_overdue
                ];
            }
        }
        $contractResult->free();
    } else {
        error_log("Contract query failed: " . $conn->error);
        echo "data: " . json_encode(['success' => false, 'error' => 'Contract query failed']) . "\n\n";
        flush();
        break;
    }

    echo "data: " . json_encode(['success' => true, 'contractVehicles' => $contractVehicles]) . "\n\n";
    flush();

    sleep(5); // Update every 5 seconds
}

$conn->close();
?>