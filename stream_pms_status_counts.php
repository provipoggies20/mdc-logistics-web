<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

require 'db_connect.php';

function sendEvent($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

while (true) {
    try {
        $today = new DateTime(); // Use current server time
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

                if ($daysRemaining <= 7) { // DUE (≤0) or NEARING (≤7)
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

                if ($daysRemaining <= 7) { // DUE (≤0) or NEARING (≤7)
                    $count++;
                }
            }
        } else {
            error_log("Komtrax PMS query failed: " . $conn->error);
        }

        sendEvent(['count' => $count]);
    } catch (Exception $e) {
        error_log("Error in stream_pms_status_counts: " . $e->getMessage());
        sendEvent(['count' => 0, 'error' => 'Server error']);
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