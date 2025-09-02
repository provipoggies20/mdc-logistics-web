<?php
require 'db_connect.php';
header('Content-Type: application/json');

// Get query parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$filter = isset($_GET['filter']) ? $conn->real_escape_string($_GET['filter']) : '';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$itemsPerPage = 10;
$offset = ($page - 1) * $itemsPerPage;

// Build WHERE clause
$whereClauses = [];
if ($filter) {
    $whereClauses[] = "equipment_type = '$filter'";
}
if ($search) {
    $whereClauses[] = "target_name LIKE '%$search%'";
}
$where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Query to fetch vehicles
$query = "
    SELECT target_name, equipment_type, physical_status, assignment, 
           last_pms_date, next_pms_date, pms_interval, days_contract, days_elapsed
    FROM (
        SELECT target_name, equipment_type, physical_status, assignment, 
               last_pms_date, next_pms_date, pms_interval, days_contract, days_elapsed
        FROM devices
        WHERE equipment_type IS NOT NULL
        UNION
        SELECT target_name, equipment_type, physical_status, assignment, 
               last_pms_date, next_pms_date, pms_interval, days_contract, days_elapsed
        FROM komtrax
        WHERE equipment_type IS NOT NULL
    ) AS combined
    $where
    ORDER BY target_name
    LIMIT $itemsPerPage OFFSET $offset";

$result = $conn->query($query);
$vehicles = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Handle null or invalid dates
        $row['last_pms_date'] = ($row['last_pms_date'] === '0000-00-00' || !$row['last_pms_date']) ? 'N/A' : $row['last_pms_date'];
        $row['next_pms_date'] = ($row['next_pms_date'] === '0000-00-00' || !$row['next_pms_date']) ? 'N/A' : $row['next_pms_date'];
        $vehicles[] = $row;
    }
} else {
    error_log("Vehicle query failed: " . $conn->error);
}

// Count total vehicles for pagination
$countQuery = "
    SELECT COUNT(*) as total
    FROM (
        SELECT target_name FROM devices WHERE equipment_type IS NOT NULL
        UNION
        SELECT target_name FROM komtrax WHERE equipment_type IS NOT NULL
    ) AS combined
    $where";

$countResult = $conn->query($countQuery);
$totalCount = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalCount / $itemsPerPage);

// Output JSON
echo json_encode([
    'vehicles' => $vehicles,
    'totalPages' => $totalPages,
    'totalCount' => $totalCount
], JSON_PRETTY_PRINT);

$conn->close();
?>