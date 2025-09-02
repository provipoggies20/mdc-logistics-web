<?php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

// Set timezone to PST for consistency
date_default_timezone_set('America/Los_Angeles');

try {
    // Fetch pending count (edits + vehicles) from fetch_pending_count.php
    ob_start();
    include 'fetch_pending_count.php';
    $pendingOutput = ob_get_clean();
    $pendingData = json_decode($pendingOutput, true);
    $pendingCount = isset($pendingData['count']) ? (int)$pendingData['count'] : 0;
    // Maintenance count (DUE PMS vehicles)
    $maintenanceCount = 0;
    $today = new DateTime();

    // Query for devices table (maintenance)
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
                $maintenanceCount++;
            }
        }
    } else {
        error_log("Devices PMS query failed: " . $conn->error);
    }

    // Query for komtrax table (maintenance)
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
                $maintenanceCount++;
            }
        }
    } else {
        error_log("Komtrax PMS query failed: " . $conn->error);
    }

    // Monitoring count (Overdue vehicles in contract monitoring)
    $monitoringCount = 0;
    $monitoringQuery = "
        SELECT date_transferred, date_ended
        FROM (
            SELECT date_transferred, date_ended, physical_status
            FROM devices
            WHERE equipment_type IS NOT NULL
            AND date_transferred IS NOT NULL
            AND date_ended IS NOT NULL
            UNION
            SELECT date_transferred, date_ended, physical_status
            FROM komtrax
            WHERE equipment_type IS NOT NULL
            AND date_transferred IS NOT NULL
            AND date_ended IS NOT NULL
        ) AS combined_vehicles
        WHERE physical_status != 'Breakdown' OR (physical_status = 'Breakdown' AND CURDATE() > date_ended)";
    $monitoringResult = $conn->query($monitoringQuery);

    if ($monitoringResult) {
        while ($row = $monitoringResult->fetch_assoc()) {
            $dateTransferred = new DateTime($row['date_transferred']);
            $dateEnded = new DateTime($row['date_ended']);
            $daysContract = $dateTransferred->diff($dateEnded)->days;
            $daysElapsed = $dateTransferred->diff($today)->days;

            if ($daysElapsed > $daysContract && $daysContract > 0) {
                $monitoringCount++;
            }
        }
    } else {
        error_log("Monitoring query failed: " . $conn->error);
    }

    // Geofence count
    $geofenceQuery = "SELECT COUNT(*) as count FROM geofence";
    $geofenceResult = $conn->query($geofenceQuery);
    $geofenceCount = $geofenceResult ? (int)$geofenceResult->fetch_assoc()['count'] : 0;

    // Return counts as JSON
    echo json_encode([
        'maintenance' => $maintenanceCount,
        'monitoring' => $monitoringCount,
        'geofence' => $geofenceCount,
        'pending_edits' => $pendingCount
    ]);
} catch (Exception $e) {
    error_log("Error in fetch_red_highlight_counts.php: " . $e->getMessage());
    echo json_encode(['maintenance' => 0, 'monitoring' => 0, 'geofence' => 0, 'error' => 'Server error']);
}

$conn->close();
?>