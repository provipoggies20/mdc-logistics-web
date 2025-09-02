<?php
header('Content-Type: application/json');
require 'db_connect.php';

// Check database connection
if (!$conn) {
    $response = ['success' => false, 'error' => 'Database connection failed'];
    error_log("Database connection failed: " . mysqli_connect_error());
    echo json_encode($response);
    exit;
}

// File-based caching
$cacheFile = sys_get_temp_dir() . '/vehicle_stats.json';
$cacheTTL = 30; // Cache for 30 seconds
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    echo file_get_contents($cacheFile);
    exit;
}

try {
    $startTime = microtime(true);
    // Optimized query
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

    $executionTime = microtime(true) - $startTime;
    error_log("fetch_vehicle_stats query took $executionTime seconds");

    $jsonResponse = json_encode($response);
    file_put_contents($cacheFile, $jsonResponse);
    echo $jsonResponse;
} catch (Exception $e) {
    $response = ['success' => false, 'error' => 'Server error: ' . $e->getMessage()];
    error_log("Exception in fetch_vehicle_stats: " . $e->getMessage());
    echo json_encode($response);
}

$conn->close();
?>