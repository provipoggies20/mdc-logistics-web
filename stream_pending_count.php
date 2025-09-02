<?php
require 'db_connect.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Function to get total pending count (edits + vehicles)
function getPendingCount($conn) {
    $editQuery = "SELECT COUNT(*) as count FROM pending_edits WHERE status = 'pending'";
    $vehicleQuery = "SELECT COUNT(*) as count FROM pending_vehicles WHERE pending_status = 'Pending'";
    
    $editCount = 0;
    $vehicleCount = 0;
    
    if ($result = $conn->query($editQuery)) {
        $editCount = $result->fetch_assoc()['count'];
    }
    if ($result = $conn->query($vehicleQuery)) {
        $vehicleCount = $result->fetch_assoc()['count'];
    }
    
    return $editCount + $vehicleCount;
}

$lastCount = getPendingCount($conn);

// Polling loop to check for changes
while (true) {
    $currentCount = getPendingCount($conn);
    
    if ($currentCount !== $lastCount) {
        echo "data: " . json_encode(['count' => $currentCount]) . "\n\n";
        ob_flush();
        flush();
        $lastCount = $currentCount;
    }
    
    // Sleep for 1 second to avoid overloading the server
    sleep(1);
    
    // Check connection status
    if (connection_aborted()) {
        break;
    }
}

$conn->close();
?>