<?php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Main Admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$source_table = filter_input(INPUT_POST, 'source_table', FILTER_SANITIZE_STRING) ?? '';
if (!in_array($source_table, ['devices', 'komtrax'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid table']);
    exit();
}

$required_fields = ['target_name', 'equipment_type', 'physical_status', 'type', 'requested_by'];
foreach ($required_fields as $field) {
    if (empty(trim($_POST[$field] ?? ''))) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$fields = [
    'target_name', 'equipment_type', 'physical_status', 'assignment', 'date_transferred',
    'date_ended', 'last_pms_date', 'pms_interval', 'tag', 'specs', 'requested_by', 'type'
];
if ($source_table === 'komtrax') {
    $fields[] = 'remarks';
}

$columns = [];
$values = [];
$bindings = [];
$types = '';

foreach ($fields as $field) {
    if (isset($_POST[$field]) && trim($_POST[$field]) !== '') {
        $columns[] = $field;
        $values[] = '?';
        $bindings[] = filter_input(INPUT_POST, $field, FILTER_SANITIZE_STRING);
        $types .= 's';
    }
}

if (empty($columns)) {
    echo json_encode(['success' => false, 'message' => 'No valid fields provided']);
    exit();
}

try {
    $query = "INSERT INTO $source_table (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit();
    }

    $stmt->bind_param($types, ...$bindings);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Vehicle added successfully']);
    } else {
        error_log("Execute failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to add vehicle']);
    }

    $stmt->close();
} catch (Exception $e) {
    error_log("Error in add_vehicle.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

$conn->close();
?>