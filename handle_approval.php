<?php
session_start();
require 'db_connect.php';

// Strict authorization check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Main Admin') {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] === 'approve' ? 'approve' : 'decline';

    error_log("Processing edit_id: $editId, action: $action, timestamp: " . date('Y-m-d H:i:s'));

    $conn->begin_transaction();

    try {
        // 1. Lock the pending edit
        $stmt = $conn->prepare("SELECT * FROM pending_edits WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $edit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$edit) {
            throw new Exception("No record found for edit_id: $editId");
        }
        if ($edit['status'] !== 'pending') {
            throw new Exception("Edit_id $editId has status: " . ($edit['status'] ?? 'null'));
        }

        error_log("Pending edit found: " . json_encode($edit));

        // 2. Only apply changes if approved
        if ($action === 'approve') {
            $proposedData = json_decode($edit['proposed_data'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid proposal data: " . json_last_error_msg());
            }

            error_log("Proposed data: " . json_encode($proposedData));

            // Fetch current values for fields to be archived
            $stmt = $conn->prepare("SELECT date_transferred, days_contract, days_elapsed, date_ended, assignment FROM `{$edit['target_table']}` WHERE id = ?");
            $stmt->bind_param("i", $edit['target_record_id']);
            $stmt->execute();
            $current_data = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            error_log("Current data: " . json_encode($current_data));

            // Validate and build update
            $updates = [];
            $params = [];
            $types = '';
            
            foreach ($proposedData as $field => $value) {
                if (!preg_match('/^[a-z_]+$/', $field)) {
                    continue;
                }
                $updates[] = "`$field` = ?";
                $params[] = $value === '' ? null : $value;
                $types .= 's'; // Use string type for proposed data, let MySQL cast
            }

            // Add last_ fields with current values
            $last_fields = [
                'date_transferred' => 'last_date_transferred',
                'days_contract' => 'last_days_contract',
                'days_elapsed' => 'last_days_elapsed',
                'date_ended' => 'last_date_ended',
                'assignment' => 'last_assignment'
            ];

            foreach ($last_fields as $original => $last_field) {
                $value = isset($current_data[$original]) ? $current_data[$original] : null;
                $updates[] = "`$last_field` = ?";
                $params[] = $value;
                // Explicit type handling to avoid date misinterpretation
                if ($value === null) {
                    $types .= 's';
                } elseif ($original === 'days_contract' || $original === 'days_elapsed') {
                    $types .= 'i';
                } else {
                    $types .= 's';
                }
            }

            if (!empty($updates)) {
                $query = "UPDATE `{$edit['target_table']}` 
                         SET " . implode(', ', $updates) . " 
                         WHERE id = ?";
                $types .= 'i';
                $params[] = $edit['target_record_id'];
                
                error_log("Update query: $query");
                error_log("Parameters: " . json_encode($params));
                error_log("Types: $types");
                
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param($types, ...$params);
                if (!$stmt->execute()) {
                    throw new Exception("Update failed: " . $stmt->error);
                }
                $affected_rows = $stmt->affected_rows;
                error_log("Update affected rows: $affected_rows");
                $stmt->close();
            } else {
                error_log("No valid updates to apply for edit_id: $editId");
            }
        }

        // 3. Update edit status (remove reviewed_at)
        $stmt = $conn->prepare("UPDATE pending_edits SET status = ?, reviewed_by_user_id = ? WHERE id = ?");
        $status = $action === 'approve' ? 'approved' : 'declined';
        $stmt->bind_param("sii", $status, $_SESSION['user_id'], $editId);
        if (!$stmt->execute()) {
            throw new Exception("Status update failed: " . $stmt->error);
        }
        $stmt->close();

        $conn->commit();
        error_log("Approval completed successfully for edit_id: $editId");
        header("Location: pending_edits.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Approval Error: " . $e->getMessage());
        header("HTTP/1.1 500 Internal Server Error");
        exit("Processing error: " . $e->getMessage());
    }
}
?>