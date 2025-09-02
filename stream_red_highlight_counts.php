<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

require 'db_connect.php';

// Set timezone to PST for consistency
date_default_timezone_set('America/Los_Angeles');

function sendEvent($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

while (true) {
    try {
        // Initialize counts
        $counts = [
            'maintenance' => 0,
            'monitoring' => 0,
            'geofence' => 0,
            'pending_edits' => 0
        ];

        // Pending edits count (use fetch_pending_count.php for consistency)
        ob_start();
        include 'fetch_pending_count.php';
        $pendingOutput = ob_get_clean();
        $pendingData = json_decode($pendingOutput, true);
        $counts['pending_edits'] = isset($pendingData['count']) ? (int)$pendingData['count'] : 0;
        if (!isset($pendingData['count']) || (isset($pendingData['error']) && $pendingData['error'])) {
            error_log("Stream: Failed to fetch pending count or error: " . ($pendingData['error'] ?? 'Invalid response'));
        }

        // Maintenance count (DUE PMS vehicles)
        $today = new DateTime();
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
                if ($daysRemaining <= 0) {
                    $counts['maintenance']++;
                }
            }
        } else {
            error_log("Devices PMS query failed in stream_red_highlight_counts: " . $conn->error);
        }

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
                if ($daysRemaining <= 0) {
                    $counts['maintenance']++;
                }
            }
        } else {
            error_log("Komtrax PMS query failed in stream_red_highlight_counts: " . $conn->error);
        }

        // Monitoring count (Overdue vehicles in contract monitoring)
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
                    $counts['monitoring']++;
                }
            }
        } else {
            error_log("Monitoring query failed in stream_red_highlight_counts: " . $conn->error);
        }

        // Geofence count
        $geofenceQuery = "SELECT COUNT(*) as count FROM geofence";
        $geofenceResult = $conn->query($geofenceQuery);
        $counts['geofence'] = $geofenceResult ? (int)$geofenceResult->fetch_assoc()['count'] : 0;

        // Send counts as SSE event
        sendEvent($counts);
    } catch (Exception $e) {
        error_log("Error in stream_red_highlight_counts.php: " . $e->getMessage());
        sendEvent(['maintenance' => 0, 'monitoring' => 0, 'geofence' => 0, 'pending_edits' => 0, 'error' => 'Server error']);
    }

    // Sleep for 5 seconds before the next update
    sleep(5);

    // Check if the connection is still alive
    if (connection_aborted()) {
        break;
    }
}

$conn->close();
?>