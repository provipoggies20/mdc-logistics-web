<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

require 'db_connect.php';

if (!$conn) {
    echo "data: " . json_encode(['success' => false, 'error' => 'Database connection failed']) . "\n\n";
    error_log("Database connection failed: " . mysqli_connect_error());
    ob_flush();
    flush();
    exit;
}

$cacheFile = sys_get_temp_dir() . '/vehicle_stats.json';
$cacheTTL = 30; // Cache for 30 seconds
$lastHash = null;

while (true) {
    if (connection_aborted()) {
        $conn->close();
        exit;
    }

    $startTime = microtime(true);
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $response = json_decode(file_get_contents($cacheFile), true);
    } else {
        try {
            $devicesQuery = "
                SELECT 
                    COUNT(*) as total,
                    SUM(physical_status = 'operational') as active,
                    SUM(physical_status = 'inactive') as inactive,
                    SUM(physical_status = 'breakdown') as breakdown
                FROM devices 
                WHERE equipment_type IS NOT NULL";
            $komtraxQuery = "
                SELECT 
                    COUNT(*) as total,
                    SUM(physical_status = 'operational') as active,
                    SUM(physical_status = 'inactive') as inactive,
                    SUM(physical_status = 'breakdown') as breakdown
                FROM komtrax 
                WHERE equipment_type IS NOT NULL";

            $devicesResult = $conn->query($devicesQuery);
            $komtraxResult = $conn->query($komtraxQuery);

            if ($devicesResult && $komtraxResult) {
                $devicesData = $devicesResult->fetch_assoc();
                $komtraxData = $komtraxResult->fetch_assoc();
                $response = [
                    'success' => true,
                    'total' => (int)$devicesData['total'] + (int)$komtraxData['total'],
                    'active' => (int)$devicesData['active'] + (int)$komtraxData['active'],
                    'inactive' => (int)$devicesData['inactive'] + (int)$komtraxData['inactive'],
                    'breakdown' => (int)$devicesData['breakdown'] + (int)$komtraxData['breakdown']
                ];
            } else {
                $response = ['success' => false, 'error' => 'Query execution failed'];
                error_log("Query failed: " . $conn->error);
            }

            file_put_contents($cacheFile, json_encode($response));
        } catch (Exception $e) {
            $response = ['success' => false, 'error' => 'Server error: ' . $e->getMessage()];
            error_log("Exception in stream_vehicle_stats: " . $e->getMessage());
        }
    }

    $currentHash = md5(json_encode($response));
    if ($currentHash !== $lastHash) {
        echo "data: " . json_encode($response) . "\n\n";
        ob_flush();
        flush();
        $lastHash = $currentHash;
    }

    $executionTime = microtime(true) - $startTime;
    error_log("stream_vehicle_stats iteration took $executionTime seconds");

    sleep(10); // Increased interval
}

// phpcs:ignore PSR1.Files.SideEffects -- Intentional unreachable code for SSE
$conn->close();
?>