<?php
// Set JSON content type
header('Content-Type: application/json');

// Include database connection
require 'db_connect.php';

// Check database connection
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Check if target_name is provided
if (!isset($_GET['target_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Target name is required']);
    exit;
}

// Sanitize input
$target_name = $conn->real_escape_string($_GET['target_name']);

// Prepare SQL query with UNION to fetch from devices or komtrax
$query = "
    SELECT last_updated, latitude, longitude
    FROM (
        SELECT last_updated, latitude, longitude, 'devices' AS source
        FROM devices
        WHERE target_name = ?
        UNION
        SELECT last_updated, latitude, longitude, 'komtrax' AS source
        FROM komtrax
        WHERE target_name = ?
    ) AS combined
    LIMIT 1";

$stmt = $conn->prepare($query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query preparation failed']);
    exit;
}

// Bind parameters and execute
$stmt->bind_param('ss', $target_name, $target_name);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Handle last_updated formatting
    $last_updated = is_numeric($row['last_updated']) 
        ? date('Y-m-d H:i:s', $row['last_updated']) 
        : ($row['last_updated'] ?? 'Not Available');
    
    echo json_encode([
        'success' => true,
        'last_updated' => $last_updated,
        'latitude' => $row['latitude'] ?? 'Not Available',
        'longitude' => $row['longitude'] ?? 'Not Available'
    ]);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Vehicle not found']);
}

// Clean up
$stmt->close();
$conn->close();
?>