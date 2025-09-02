<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1); // Temporarily enable for debugging; consider disabling in production
require 'db_connect.php';

header('Content-Type: application/json');

$page1 = isset($_GET['page1']) ? intval($_GET['page1']) : 1; // Page for Table 1
$page2 = isset($_GET['page2']) ? intval($_GET['page2']) : 1; // Page for Table 2
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$limit = 10;
$offset1 = ($page1 - 1) * $limit;
$offset2 = ($page2 - 1) * $limit;

$filterClause = "";
$searchClause = "";
$filterParams = [];
$filterTypes = "";

if (!empty($filter)) {
    $filterClause = " AND equipment_type = ?";
    $filterParams[] = $filter;
    $filterTypes .= "s";
}

if (!empty($search)) {
    $searchClause = " AND target_name LIKE ?";
    $filterParams[] = "%" . $search . "%";
    $filterTypes .= "s";
}

// Contract query (Table 1)
$contractQuery = "
    SELECT target_name, equipment_type, physical_status, assignment, date_transferred, date_ended, last_updated,
        latitude, longitude, -- Added latitude and longitude
        DATEDIFF(CURDATE(), date_transferred) AS days_elapsed,
        CASE 
            WHEN CURDATE() < date_ended THEN 0 
            ELSE DATEDIFF(CURDATE(), date_ended) 
        END AS days_lapses,
        DATEDIFF(date_ended, date_transferred) AS days_contract,
        UNIX_TIMESTAMP(last_updated) AS last_updated_timestamp,
        CONCAT('Vehicle should be on QSY in ', DATE_FORMAT(DATE_ADD(date_ended, INTERVAL 1 DAY), '%Y-%m-%d')) AS remarks,
        source
    FROM (
        SELECT 
            target_name, equipment_type, physical_status, assignment, 
            date_transferred, date_ended, last_updated,
            latitude, longitude, -- Added latitude and longitude
            'devices' AS source
        FROM devices
        WHERE equipment_type IS NOT NULL
        UNION
        SELECT 
            target_name, equipment_type, physical_status, assignment, 
            date_transferred, date_ended, last_updated,
            latitude, longitude, -- Added latitude and longitude
            'komtrax' AS source
        FROM komtrax
        WHERE equipment_type IS NOT NULL
    ) AS combined_vehicles
    WHERE (physical_status != 'Breakdown' OR (physical_status = 'Breakdown' AND CURDATE() > date_ended))
    $filterClause
    $searchClause
    ORDER BY COALESCE(days_lapses, -1) DESC, COALESCE(date_ended, '9999-12-31') ASC
    LIMIT ? OFFSET ?";

// Maintenance query (Table 2)
$maintenanceQuery = "
    SELECT target_name, equipment_type, physical_status, assignment, date_transferred, date_ended, last_updated,
        UNIX_TIMESTAMP(last_updated) AS last_updated_timestamp,
        DATEDIFF(CURDATE(), date_transferred) AS days_lapses,
        CONCAT('Vehicle should be on QSY in ', DATE_FORMAT(DATE_ADD(date_ended, INTERVAL 1 DAY), '%Y-%m-%d')) AS remarks,
        source
    FROM (
        SELECT 
            target_name, equipment_type, physical_status, assignment, 
            date_transferred, date_ended, last_updated,
            'devices' AS source
        FROM devices
        WHERE equipment_type IS NOT NULL
        UNION
        SELECT 
            target_name, equipment_type, physical_status, assignment, 
            date_transferred, date_ended, last_updated,
            'komtrax' AS source
        FROM komtrax
        WHERE equipment_type IS NOT NULL
    ) AS combined_vehicles
    WHERE physical_status IN ('Breakdown', 'Operational')
    $filterClause
    $searchClause
    ORDER BY 
        CASE 
            WHEN physical_status = 'Breakdown' THEN 1
            ELSE 2
        END,
        CASE 
            WHEN physical_status = 'Breakdown' THEN last_updated
            ELSE NULL
        END DESC,
        COALESCE(days_lapses, -1) DESC,
        COALESCE(date_ended, '9999-12-31') ASC
    LIMIT ? OFFSET ?";

// Fetcher function
function fetchData($conn, $query, $filterTypes, $filterParams, $limit, $offset) {
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Query preparation failed: " . $conn->error);
        return [];
    }

    $types = $filterTypes . "ii";
    $params = array_merge($filterParams, [$limit, $offset]);
    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("Query execution failed: " . $stmt->error);
        return [];
    }

    $result = $stmt->get_result();
    $data = [];

    while ($row = $result->fetch_assoc()) {
        $row['is_updated'] = ($row['last_updated_timestamp'] > (time() - 10));
        $row['is_overdue'] = isset($row['days_lapses']) ? ($row['days_lapses'] > 0) : false;
        $row['is_breakdown'] = ($row['physical_status'] == 'Breakdown');
        $row['is_days_lapses_null'] = is_null($row['days_lapses']);
        $data[] = $row;
    }

    $stmt->close();
    return $data;
}

// Fetch paginated data
$contractVehicles = fetchData($conn, $contractQuery, $filterTypes, $filterParams, $limit, $offset1);
$maintenanceVehicles = fetchData($conn, $maintenanceQuery, $filterTypes, $filterParams, $limit, $offset2);

// Get total counts
$countContractQuery = "
    SELECT COUNT(*) AS total 
    FROM (
        SELECT target_name, physical_status, date_ended, equipment_type 
        FROM devices 
        WHERE equipment_type IS NOT NULL
        UNION
        SELECT target_name, physical_status, date_ended, equipment_type 
        FROM komtrax 
        WHERE equipment_type IS NOT NULL
    ) AS combined_vehicles 
    WHERE (physical_status != 'Breakdown' OR (physical_status = 'Breakdown' AND CURDATE() > date_ended))
    $filterClause
    $searchClause";

$countMaintenanceQuery = "
    SELECT COUNT(*) AS total 
    FROM (
        SELECT target_name, physical_status, equipment_type 
        FROM devices 
        WHERE equipment_type IS NOT NULL
        UNION
        SELECT target_name, physical_status, equipment_type 
        FROM komtrax 
        WHERE equipment_type IS NOT NULL
    ) AS combined_vehicles 
    WHERE physical_status IN ('Breakdown', 'Operational')
    $filterClause
    $searchClause";

$totalContractStmt = $conn->prepare($countContractQuery);
$totalMaintenanceStmt = $conn->prepare($countMaintenanceQuery);

if (!$totalContractStmt || !$totalMaintenanceStmt) {
    error_log("Count query preparation failed: " . $conn->error);
    echo json_encode(['error' => 'Count query preparation failed']);
    exit;
}

if (!empty($filterParams)) {
    $totalContractStmt->bind_param($filterTypes, ...$filterParams);
    $totalMaintenanceStmt->bind_param($filterTypes, ...$filterParams);
}

if ($conn->more_results()) {
    $conn->next_result();
}

$totalContractStmt->execute();
$totalContractResult = $totalContractStmt->get_result()->fetch_assoc();

$totalMaintenanceStmt->execute();
$totalMaintenanceResult = $totalMaintenanceStmt->get_result()->fetch_assoc();

$totalContractCount = $totalContractResult['total'] ?? 0;
$totalMaintenanceCount = $totalMaintenanceResult['total'] ?? 0;

$totalContractStmt->close();
$totalMaintenanceStmt->close();

$response = [
    'contractVehicles' => $contractVehicles,
    'maintenanceVehicles' => $maintenanceVehicles,
    'totalContractCount' => $totalContractCount,
    'totalMaintenanceCount' => $totalMaintenanceCount,
    'serverTime' => time()
];

// Debug logging
file_put_contents('debug_vehicles.log', print_r($response, true));

echo json_encode($response);
$conn->close();
?>