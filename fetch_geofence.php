<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

require 'db_connect.php';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$itemsPerPage = 10;
$offset = ($page - 1) * $itemsPerPage;

// Build the query
$query = "SELECT g.id, g.target_name, g.assignment, g.status, g.timestamp, d.equipment_type 
          FROM geofence g 
          LEFT JOIN devices d ON g.target_name = d.target_name 
          WHERE 1=1";

$params = [];
$types = "";

if ($filter) {
    $query .= " AND d.equipment_type = ?";
    $params[] = $filter;
    $types .= "s";
}

if ($search) {
    $query .= " AND g.target_name LIKE ?";
    $params[] = "%" . $search . "%";
    $types .= "s";
}

$query .= " ORDER BY g.timestamp DESC LIMIT ? OFFSET ?";
$params[] = $itemsPerPage;
$params[] = $offset;
$types .= "ii";

// Prepare and execute the query
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$geofenceLogs = [];
while ($row = $result->fetch_assoc()) {
    $geofenceLogs[] = $row;
}

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total 
               FROM geofence g 
               LEFT JOIN devices d ON g.target_name = d.target_name 
               WHERE 1=1";

$countParams = [];
$countTypes = "";

if ($filter) {
    $countQuery .= " AND d.equipment_type = ?";
    $countParams[] = $filter;
    $countTypes .= "s";
}

if ($search) {
    $countQuery .= " AND g.target_name LIKE ?";
    $countParams[] = "%" . $search . "%";
    $countTypes .= "s";
}

$countStmt = $conn->prepare($countQuery);

if (!empty($countParams)) {
    $countStmt->bind_param($countTypes, ...$countParams);
}

$countStmt->execute();
$totalCount = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalCount / $itemsPerPage);

// Prepare response
$response = [
    'geofenceLogs' => $geofenceLogs,
    'totalCount' => $totalCount,
    'totalPages' => $totalPages
];

header('Content-Type: application/json');
echo json_encode($response);

$stmt->close();
$countStmt->close();
$conn->close();
?>