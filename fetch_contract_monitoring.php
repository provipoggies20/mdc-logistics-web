<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

require 'db_connect.php';
header('Content-Type: application/json');

try {
    // Validate database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $current_date = '2025-05-22 21:21:00'; // Fixed date for consistency

    // Query to fetch contract monitoring data
    $query = "
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

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Query preparation failed: " . $conn->error);
    }

    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $contractVehicles = [];
    while ($row = $result->fetch_assoc()) {
        $days_elapsed = 0;
        $days_lapses = 0;
        $date_transferred = $row['date_transferred'] ? new DateTime($row['date_transferred']) : null;
        $date_ended = $row['date_ended'] ? new DateTime($row['date_ended']) : null;
        $current = new DateTime($current_date);

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

    echo json_encode([
        'success' => true,
        'contractVehicles' => $contractVehicles
    ]);

    $stmt->close();
} catch (Exception $e) {
    error_log("fetch_contract_monitoring Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error occurred'
    ]);
}

$conn->close();
?>