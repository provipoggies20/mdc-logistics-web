<?php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$source_table = $_POST['source_table'] ?? '';
if (!in_array($source_table, ['devices', 'komtrax'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid table']);
    exit;
}

if ($_SESSION['role'] === 'User') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Define fields to process (aligned with pending_vehicles schema, excluding id)
$fields = [
    'target_name', 'equipment_type', 'physical_status', 'assignment', 'date_transferred', 'date_ended',
    'last_pms_date', 'pms_interval', 'requested_by', 'cut_address', 'address', 'days_contract',
    'days_elapsed', 'days_lapses', 'last_updated', 'position_time', 'latitude', 'longitude', 'speed',
    'direction', 'total_mileage', 'status', 'type', 'speed_limit', 'days_no_gps', 'tag', 'specs',
    'last_assignment', 'last_days_contract', 'last_date_transferred', 'last_date_ended', 'last_days_elapsed',
    'remarks'
];

$required_fields = ['target_name', 'equipment_type', 'physical_status', 'type', 'requested_by'];
$vehicle_data = [];
$errorMessage = '';

foreach ($fields as $field) {
    $submitted_value = isset($_POST[$field]) ? trim($_POST[$field]) : null;
    $submitted_value = ($submitted_value === '') ? null : $submitted_value;

    if (in_array($field, $required_fields) && !$submitted_value) {
        $errorMessage .= " Missing required field: " . strtoupper(str_replace('_', ' ', $field)) . ". ";
        continue;
    }

    if (in_array($field, ['date_transferred', 'date_ended', 'last_date_transferred', 'last_date_ended', 'last_updated', 'position_time'])) {
        if ($submitted_value) {
            try {
                $submitted_dt = new DateTime($submitted_value);
                $vehicle_data[$field] = $submitted_dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                error_log("Invalid date/time format for $field: " . $submitted_value);
                $errorMessage .= " Invalid format for " . strtoupper(str_replace('_', ' ', $field)) . " (use YYYY-MM-DD HH:MM:SS). ";
            }
        }
    } elseif ($field === 'last_pms_date') {
        if ($submitted_value) {
            try {
                $submitted_dt = DateTime::createFromFormat('m-d-Y', $submitted_value);
                if (!$submitted_dt) {
                    error_log("Invalid date format for $field: " . $submitted_value);
                    $errorMessage .= " Invalid format for " . strtoupper(str_replace('_', ' ', $field)) . " (use MM-DD-YYYY). ";
                } else {
                    $submitted_dt->setTime(0, 0, 0);
                    $vehicle_data[$field] = $submitted_dt->format('Y-m-d');
                }
            } catch (Exception $e) {
                error_log("Error processing date for $field: " . $e->getMessage());
                $errorMessage .= " Error processing " . strtoupper(str_replace('_', ' ', $field)) . ". ";
            }
        }
    } elseif (in_array($field, ['pms_interval', 'days_contract', 'days_no_gps', 'days_elapsed', 'days_lapses', 'last_days_contract', 'last_days_elapsed'])) {
        if ($submitted_value !== null) {
            if (!is_numeric($submitted_value) || $submitted_value < 0) {
                $errorMessage .= " " . strtoupper(str_replace('_', ' ', $field)) . " must be a non-negative integer. ";
            } else {
                $vehicle_data[$field] = (int)$submitted_value;
            }
        }
    } elseif (in_array($field, ['latitude', 'longitude', 'speed', 'speed_limit', 'direction', 'total_mileage'])) {
        if ($submitted_value !== null) {
            if (!is_numeric($submitted_value)) {
                $errorMessage .= " " . strtoupper(str_replace('_', ' ', $field)) . " must be a number. ";
            } else {
                $vehicle_data[$field] = (float)$submitted_value;
            }
        }
    } elseif ($field === 'physical_status') {
        if ($submitted_value && !in_array($submitted_value, ['Operational', 'Inactive', 'Breakdown'])) {
            $errorMessage .= " Invalid value for Physical Status (use Operational, Inactive, or Breakdown). ";
        } else {
            $vehicle_data[$field] = $submitted_value;
        }
    } else {
        $vehicle_data[$field] = $submitted_value;
    }
}

// Calculate days_contract
if (isset($vehicle_data['date_transferred']) && isset($vehicle_data['date_ended'])) {
    try {
        $start = new DateTime($vehicle_data['date_transferred']);
        $end = new DateTime($vehicle_data['date_ended']);
        if ($end >= $start) {
            $interval = $end->diff($start);
            $vehicle_data['days_contract'] = $interval->days;
        } else {
            $errorMessage .= " Date Ended must be after Date Transferred. ";
        }
    } catch (Exception $e) {
        error_log("Error calculating days_contract: " . $e->getMessage());
        $errorMessage .= " Error calculating contract days. ";
    }
}

if (!empty($errorMessage)) {
    echo json_encode(['success' => false, 'message' => $errorMessage]);
    exit;
}

if (empty($vehicle_data)) {
    echo json_encode(['success' => false, 'message' => 'No valid data provided']);
    exit;
}

// Check for duplicate target_name
$query = "SELECT id FROM $source_table WHERE target_name = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $vehicle_data['target_name']);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Vehicle with this Target Name already exists']);
    $stmt->close();
    exit;
}
$stmt->close();

// Define columns explicitly to match pending_vehicles schema (excluding id)
$insert_columns = array_merge($fields, ['source_table', 'requested_by_user_id', 'pending_status', 'submitted_at']);
$placeholders = array_fill(0, count($insert_columns), '?');
$values = [];

// Define type mappings
$field_types = [
    'target_name' => 's', 'equipment_type' => 's', 'physical_status' => 's', 'assignment' => 's',
    'date_transferred' => 's', 'date_ended' => 's', 'last_pms_date' => 's', 'pms_interval' => 'i',
    'requested_by' => 's', 'cut_address' => 's', 'address' => 's', 'days_contract' => 'i',
    'days_elapsed' => 'i', 'days_lapses' => 'i', 'last_updated' => 's', 'position_time' => 's',
    'latitude' => 'd', 'longitude' => 'd', 'speed' => 'd', 'direction' => 'd', 'total_mileage' => 'd',
    'status' => 's', 'type' => 's', 'speed_limit' => 'd', 'days_no_gps' => 'i', 'tag' => 's',
    'specs' => 's', 'last_assignment' => 's', 'last_days_contract' => 'i', 'last_date_transferred' => 's',
    'last_date_ended' => 's', 'last_days_elapsed' => 'i', 'remarks' => 's', 'source_table' => 's',
    'requested_by_user_id' => 'i', 'pending_status' => 's', 'submitted_at' => 's'
];
$types = '';
foreach ($insert_columns as $col) {
    $types .= $field_types[$col];
}

// Populate values, using NULL for unset fields
foreach ($fields as $field) {
    $values[] = isset($vehicle_data[$field]) ? $vehicle_data[$field] : null;
}
$values[] = $source_table;
$values[] = $_SESSION['user_id'];
$values[] = 'Pending';
$values[] = date('Y-m-d H:i:s');

// Log for debugging
error_log("Insert Columns: " . implode(', ', $insert_columns));
error_log("Values Count: " . count($values));
error_log("Types: " . $types);
error_log("Query: INSERT INTO pending_vehicles (" . implode(', ', $insert_columns) . ") VALUES (" . implode(', ', $placeholders) . ")");

// Prepare and execute insert
$query = "INSERT INTO pending_vehicles (" . implode(', ', $insert_columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed for pending insert: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Error preparing database insertion: ' . $conn->error]);
    exit;
}
$stmt->bind_param($types, ...$values);
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Vehicle addition submitted for approval']);
} else {
    error_log("Insert failed for pending vehicle: " . $stmt->error);
    echo json_encode(['success' => false, 'message' => 'Error saving vehicle addition: ' . $stmt->error]);
}
$stmt->close();

$conn->close();
?>