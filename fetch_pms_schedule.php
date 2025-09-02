<?php
// Strict error reporting
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
require 'db_connect.php';

try {
    // Validate database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $filter = trim($_GET['filter'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $itemsPerPage = 10;
    $offset = ($page - 1) * $itemsPerPage;

    // Log received parameters for debugging
    error_log("fetch_pms_schedule.php - Received parameters: page=$page, filter='$filter', search='$search'");

    // Base query combining devices and komtrax
    $query = "
    SELECT 
        target_name,
        equipment_type,
        COALESCE(last_pms_date, '1970-01-01') AS last_pms_date,
        pms_interval,
        CASE 
            WHEN pms_interval > 0 THEN DATE_ADD(
                COALESCE(last_pms_date, CURDATE()), 
                INTERVAL pms_interval DAY
            )
            ELSE NULL
        END AS next_pms_date,
        latitude,
        longitude,
        source
    FROM (
        SELECT 
            target_name,
            TRIM(equipment_type) AS equipment_type,
            last_pms_date,
            pms_interval,
            latitude,
            longitude,
            'devices' AS source
        FROM devices
        WHERE equipment_type IS NOT NULL
        UNION
        SELECT 
            target_name,
            TRIM(equipment_type) AS equipment_type,
            last_pms_date,
            pms_interval,
            latitude,
            longitude,
            'komtrax' AS source
        FROM komtrax
        WHERE equipment_type IS NOT NULL
    ) AS combined
    WHERE 1=1";

    $params = [];
    $types = '';

    // Apply filter and search on the combined result
    if (!empty($filter)) {
        $query .= " AND LOWER(TRIM(equipment_type)) = LOWER(?)";
        $params[] = $filter;
        $types .= 's';
    }

    if (!empty($search)) {
        $query .= " AND LOWER(target_name) LIKE LOWER(?)";
        $params[] = "%$search%";
        $types .= 's';
    }

    // Count query
    $countQuery = "SELECT COUNT(*) as total FROM ($query) AS subquery";
    $stmt = $conn->prepare($countQuery);
    if (!$stmt) {
        throw new Exception("Count query preparation failed: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception("Count query execution failed: " . $stmt->error);
    }

    $countResult = $stmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'] ?? 0;
    $totalPages = max(1, ceil($totalCount / $itemsPerPage));
    $stmt->close();

    // Log the total count
    error_log("fetch_pms_schedule.php - Total count after filters: $totalCount");

    // Main data query
    $query .= " ORDER BY 
        CASE 
            WHEN next_pms_date IS NULL THEN 999999 
            ELSE DATEDIFF(next_pms_date, CURDATE()) 
        END ASC, 
        target_name ASC 
        LIMIT ? OFFSET ?";
    $params[] = $itemsPerPage;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Main query preparation failed: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception("Main query execution failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $vehicles = [];
    while ($row = $result->fetch_assoc()) {
        $row['last_pms_date'] = $row['last_pms_date'] === '1970-01-01' ? null : $row['last_pms_date'];
        $row['next_pms_date'] = $row['next_pms_date'] === null ? null : $row['next_pms_date'];
        $vehicles[] = $row;
    }
    // Log fetched vehicles for debugging
$debug_vehicles = array_map(function($vehicle) {
    return [
        'target_name' => $vehicle['target_name'],
        'latitude' => $vehicle['latitude'],
        'longitude' => $vehicle['longitude'],
        'source' => $vehicle['source']
    ];
}, $vehicles);
error_log("fetch_pms_schedule.php - Fetched vehicles: " . json_encode($debug_vehicles));

    $stmt->close();

    // Log the number of vehicles fetched
    error_log("fetch_pms_schedule.php - Fetched " . count($vehicles) . " vehicles");

    // Log sample equipment types for debugging
    $sampleQuery = "SELECT DISTINCT TRIM(equipment_type) AS equipment_type FROM ($query) AS sample LIMIT 5";
    $sampleStmt = $conn->prepare($sampleQuery);
    if ($sampleStmt) {
        if (!empty($params)) {
            $sampleStmt->bind_param($types, ...$params);
        }
        if ($sampleStmt->execute()) {
            $sampleResult = $sampleStmt->get_result();
            $sampleTypes = [];
            while ($row = $sampleResult->fetch_assoc()) {
                $sampleTypes[] = $row['equipment_type'];
            }
            error_log("fetch_pms_schedule.php - Sample equipment types: " . json_encode($sampleTypes));
        }
        $sampleStmt->close();
    }

    echo json_encode([
        'success' => true,
        'vehicles' => $vehicles,
        'totalCount' => (int)$totalCount,
        'totalPages' => (int)$totalPages
    ], JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    error_log("PMS Schedule Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error occurred',
        'debug' => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() : null
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>