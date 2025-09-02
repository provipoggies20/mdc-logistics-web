<?php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

function hasPendingChange($conn, $targetTable, $recordId) {
    $checkQuery = "SELECT id FROM pending_edits WHERE target_table = ? AND target_record_id = ? AND status = 'pending' LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    if (!$checkStmt) {
        error_log("Prepare failed for pending change check: " . $conn->error);
        return false;
    }
    $checkStmt->bind_param("si", $targetTable, $recordId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $exists = $checkResult->num_rows > 0;
    $checkStmt->close();
    return $exists;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isset($_POST['id']) || !ctype_digit((string)$_POST['id']) || 
    !isset($_POST['table']) || !in_array($_POST['table'], ['devices', 'komtrax'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request: Missing or invalid ID or table']);
    exit();
}

$id = intval($_POST['id']);
$table = $_POST['table'];
$errorMessage = '';
$successMessage = '';

if ($_SESSION['role'] !== 'Main Admin' && hasPendingChange($conn, $table, $id)) {
    echo json_encode(['success' => false, 'message' => 'This record has changes pending approval. Please wait until they are reviewed before submitting new changes.']);
    exit();
}

// Fetch original record
$query = "SELECT * FROM $table WHERE id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed for fetching record: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Error preparing database query']);
    exit();
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$record = $result->fetch_assoc();
$stmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => "Record not found in $table"]);
    exit();
}

// Define all fields that can be approved
$fields_to_approve = [
    'target_name', 'equipment_type', 'assignment', 'physical_status', 'date_transferred', 'date_ended',
    'last_pms_date', 'next_pms_date', 'pms_interval', 'requested_by', 'cut_address', 'address',
    'license_plate_no', 'days_contract', 'days_elapsed', 'days_lapses', 'last_updated', 'position_time',
    'latitude', 'longitude', 'speed', 'direction', 'total_mileage', 'status', 'type', 'speed_limit',
    'days_no_gps', 'tag', 'specs', 'last_assignment', 'last_days_contract', 'last_date_transferred',
    'last_date_ended', 'last_days_elapsed', 'remarks', 'gps_id', 'conduction_sticker'
];

$changed_data = [];

foreach ($fields_to_approve as $field) {
    if (!array_key_exists($field, $record) && !in_array($field, ['days_contract', 'days_elapsed', 'days_lapses', 'requested_by', 'license_plate_no'])) {
        continue;
    }
    $original_value = $record[$field] ?? null;
    $submitted_value = isset($_POST[$field]) ? trim($_POST[$field]) : null;
    $submitted_value = ($submitted_value === "") ? null : $submitted_value;

    if (in_array($field, ['date_transferred', 'date_ended', 'last_date_transferred', 'last_date_ended'])) {
        if ($submitted_value !== null) {
            try {
                $submitted_dt = new DateTime($submitted_value);
                $submitted_db_format = $submitted_dt->format('Y-m-d H:i:s');
                $original_db_format = $original_value ? (new DateTime($original_value))->format('Y-m-d H:i:s') : null;
                if ($submitted_db_format !== $original_db_format) {
                    $changed_data[$field] = $submitted_db_format;
                }
            } catch (Exception $e) {
                error_log("Invalid date/time format submitted for $field in $table: " . $submitted_value . " - Error: " . $e->getMessage());
                $errorMessage .= " Invalid format for " . strtoupper(str_replace('_', ' ', $field)) . " (use YYYY-MM-DD HH:MM:SS). ";
            }
        } elseif ($original_value !== null) {
            $changed_data[$field] = null;
        }
    } elseif (in_array($field, ['last_pms_date', 'next_pms_date'])) {
        if ($submitted_value !== null) {
            try {
                $submitted_dt = DateTime::createFromFormat('m-d-Y', $submitted_value);
                if (!$submitted_dt) {
                    error_log("Invalid date format submitted for $field in $table (expected m-d-Y): " . $submitted_value);
                    $errorMessage .= " Invalid format for " . strtoupper(str_replace('_', ' ', $field)) . " (use MM-DD-YYYY). ";
                    continue;
                }
                $submitted_dt->setTime(0, 0, 0);
                $submitted_db_format = $submitted_dt->format('Y-m-d');
                $original_db_format = $original_value ? (new DateTime($original_value))->format('Y-m-d') : null;
                if ($submitted_db_format !== $original_db_format) {
                    $changed_data[$field] = $submitted_db_format;
                }
            } catch (Exception $e) {
                error_log("Error processing date for $field in $table: " . $e->getMessage());
                $errorMessage .= " Error processing " . strtoupper(str_replace('_', ' ', $field)) . ". ";
            }
        } elseif ($original_value !== null) {
            $changed_data[$field] = null;
        }
    } elseif (in_array($field, ['pms_interval', 'days_contract', 'days_elapsed', 'days_lapses', 'days_no_gps', 'last_days_contract', 'last_days_elapsed', 'speed', 'speed_limit', 'latitude', 'longitude', 'total_mileage'])) {
        if ($submitted_value !== null) {
            if (!is_numeric($submitted_value) || $submitted_value < 0) {
                $errorMessage .= " " . strtoupper(str_replace('_', ' ', $field)) . " must be a non-negative number. ";
            } elseif ((string)$original_value !== (string)$submitted_value) {
                $changed_data[$field] = $submitted_value;
            }
        } elseif ($original_value !== null) {
            $changed_data[$field] = null;
        }
    } elseif ($field === 'physical_status') {
        if ($submitted_value !== null && !in_array($submitted_value, ['Operational', 'Inactive', 'Breakdown', ''])) {
            $errorMessage .= " Invalid value for Physical Status (use Operational, Inactive, or Breakdown). ";
        } elseif ((string)$original_value !== (string)$submitted_value) {
            $changed_data[$field] = $submitted_value;
        }
    } else {
        if ((string)$original_value !== (string)$submitted_value) {
            $changed_data[$field] = $submitted_value;
        }
    }
}

// Calculate days_contract if date_transferred or date_ended changed
if (isset($changed_data['date_transferred']) || isset($changed_data['date_ended'])) {
    $date_transferred = $changed_data['date_transferred'] ?? $record['date_transferred'] ?? null;
    $date_ended = $changed_data['date_ended'] ?? $record['date_ended'] ?? null;

    if ($date_transferred && $date_ended) {
        try {
            $start = new DateTime($date_transferred);
            $end = new DateTime($date_ended);
            if ($end >= $start) {
                $interval = $end->diff($start);
                $days_contract = $interval->days;
                if ($days_contract !== (int)($record['days_contract'] ?? 0)) {
                    $changed_data['days_contract'] = $days_contract;
                }
            } else {
                $errorMessage .= " Date Ended must be after Date Transferred. ";
            }
        } catch (Exception $e) {
            error_log("Error calculating days_contract in $table: " . $e->getMessage());
            $errorMessage .= " Error calculating contract days. ";
        }
    } else {
        if ($record['days_contract'] !== null) {
            $changed_data['days_contract'] = null;
        }
    }
}

if (!empty($errorMessage)) {
    echo json_encode(['success' => false, 'message' => $errorMessage]);
    exit();
}

if (empty($changed_data)) {
    echo json_encode(['success' => false, 'message' => 'No changes detected']);
    exit();
}

if ($_SESSION['role'] === 'Main Admin') {
    // Directly update the table
    $updates = [];
    $bindings = [];
    $types = '';
    foreach ($changed_data as $field => $value) {
        $updates[] = "$field = ?";
        $bindings[] = $value;
        $types .= 's'; // Treat all as strings for simplicity
    }
    $bindings[] = $id;
    $types .= 'i';

    $query = "UPDATE $table SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed for direct update: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Error preparing database update']);
        exit();
    }
    $stmt->bind_param($types, ...$bindings);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Changes applied successfully']);
    } else {
        error_log("Update failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Failed to apply changes: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    // Store in pending_edits
    $proposed_data_json = json_encode($changed_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON encoding error: " . json_last_error_msg());
        echo json_encode(['success' => false, 'message' => 'Error encoding changes']);
        exit();
    }

    $requested_by_user_id = $_SESSION['user_id'];
    $edit_type = 'update';

    $insert_query = "INSERT INTO pending_edits (target_table, target_record_id, edit_type, proposed_data, requested_by_user_id, status) VALUES (?, ?, ?, ?, ?, 'pending')";
    $insert_stmt = $conn->prepare($insert_query);
    if (!$insert_stmt) {
        error_log("Prepare failed for inserting pending edit: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Error preparing database insertion']);
        exit();
    }
    $insert_stmt->bind_param("sisss", $table, $id, $edit_type, $proposed_data_json, $requested_by_user_id);
    if ($insert_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Changes submitted for approval successfully']);
    } else {
        error_log("Insert failed for pending edit: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Error saving changes']);
    }
    $insert_stmt->close();
}

$conn->close();
?>