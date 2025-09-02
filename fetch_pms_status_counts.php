<?php
header('Content-Type: application/json');
require 'db_connect.php';

try {
    $today = new DateTime(); // Use current server time (PST set in dashboard.php)
    $count = 0;

    // Query for devices table
    $queryDevices = "
        SELECT last_pms_date, next_pms_date
        FROM devices
        WHERE last_pms_date IS NOT NULL AND next_pms_date IS NOT NULL
        AND next_pms_date != '0000-00-00'";
    $resultDevices = $conn->query($queryDevices);

    if ($resultDevices) {
        while ($row = $resultDevices->fetch_assoc()) {
            $nextPmsDate = new DateTime($row['next_pms_date']);
            $interval = $today->diff($nextPmsDate);
            $daysRemaining = $interval->days * ($interval->invert ? -1 : 1);

            if ($daysRemaining <= 0) { // Only count DUE (≤0)
                $count++;
            }
        }
    } else {
        error_log("Devices PMS query failed: " . $conn->error);
    }

    // Query for komtrax table
    $queryKomtrax = "
        SELECT last_pms_date, next_pms_date
        FROM komtrax
        WHERE last_pms_date IS NOT NULL AND next_pms_date IS NOT NULL
        AND next_pms_date != '0000-00-00'";
    $resultKomtrax = $conn->query($queryKomtrax);

    if ($resultKomtrax) {
        while ($row = $resultKomtrax->fetch_assoc()) {
            $nextPmsDate = new DateTime($row['next_pms_date']);
            $interval = $today->diff($nextPmsDate);
            $daysRemaining = $interval->days * ($interval->invert ? -1 : 1);

            if ($daysRemaining <= 0) { // Only count DUE (≤0)
                $count++;
            }
        }
    } else {
        error_log("Komtrax PMS query failed: " . $conn->error);
    }

    echo json_encode(['count' => $count]);
} catch (Exception $e) {
    error_log("Error in fetch_pms_status_counts: " . $e->getMessage());
    echo json_encode(['count' => 0, 'error' => 'Server error']);
}

$conn->close();
?>