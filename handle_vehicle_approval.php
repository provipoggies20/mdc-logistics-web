<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Main Admin') {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleId = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] === 'approve' ? 'approve' : 'decline';

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("SELECT * FROM pending_vehicles WHERE id = ? AND pending_status = 'Pending' FOR UPDATE");
        $stmt->bind_param("i", $vehicleId);
        $stmt->execute();
        $vehicle = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$vehicle || $vehicle['pending_status'] !== 'Pending') {
            throw new Exception("Invalid or already processed request");
        }

        if ($action === 'approve') {
            $fields = [
                'target_name', 'equipment_type', 'physical_status', 'assignment', 'date_transferred',
                'days_contract', 'days_elapsed', 'days_lapses', 'date_ended', 'last_updated',
                'position_time', 'address', 'latitude', 'longitude', 'speed', 'direction',
                'total_mileage', 'status', 'type', 'speed_limit', 'days_no_gps', 'last_pms_date',
                'next_pms_date', 'pms_interval', 'tag', 'specs', 'cut_address', 'requested_by',
                'last_assignment', 'last_days_contract', 'last_date_transferred', 'last_date_ended',
                'last_days_elapsed', 'remarks'
            ];

            $values = [];
            $types = '';
            $params = [];

            $field_types = [
                'target_name' => 's', 'equipment_type' => 's', 'physical_status' => 's', 'assignment' => 's',
                'date_transferred' => 's', 'days_contract' => 'i', 'days_elapsed' => 'i', 'days_lapses' => 'i',
                'date_ended' => 's', 'last_updated' => 's', 'position_time' => 's', 'address' => 's',
                'latitude' => 'd', 'longitude' => 'd', 'speed' => 'd', 'direction' => 'd', 'total_mileage' => 'd',
                'status' => 's', 'type' => 's', 'speed_limit' => 'd', 'days_no_gps' => 'i', 'last_pms_date' => 's',
                'next_pms_date' => 's', 'pms_interval' => 'i', 'tag' => 's', 'specs' => 's', 'cut_address' => 's',
                'requested_by' => 's', 'last_assignment' => 's', 'last_days_contract' => 'i',
                'last_date_transferred' => 's', 'last_date_ended' => 's', 'last_days_elapsed' => 'i', 'remarks' => 's'
            ];

            // Calculate next_pms_date if last_pms_date and pms_interval are provided
            if (!empty($vehicle['last_pms_date']) && !empty($vehicle['pms_interval']) && empty($vehicle['next_pms_date'])) {
                try {
                    $lastPmsDate = new DateTime($vehicle['last_pms_date']);
                    $interval = (int)$vehicle['pms_interval'];
                    if ($interval >= 0) {
                        $lastPmsDate->modify("+$interval days");
                        $vehicle['next_pms_date'] = $lastPmsDate->format('Y-m-d');
                    }
                } catch (Exception $e) {
                    error_log("Error calculating next_pms_date for Vehicle ID $vehicleId: " . $e->getMessage());
                }
            }

            foreach ($fields as $field) {
                $value = $vehicle[$field] ?? null;
                // Handle NULL values based on field type
                if ($value === null) {
                    if (in_array($field, ['days_contract', 'days_elapsed', 'days_lapses', 'pms_interval', 'days_no_gps', 'last_days_contract', 'last_days_elapsed'])) {
                        $value = 0; // Default for integers
                    } elseif (in_array($field, ['latitude', 'longitude', 'speed', 'direction', 'total_mileage', 'speed_limit'])) {
                        $value = 0.0; // Default for floats
                    } elseif (in_array($field, ['date_transferred', 'date_ended', 'last_updated', 'position_time', 'last_date_transferred', 'last_date_ended'])) {
                        $value = '0000-00-00 00:00:00'; // Default for NOT NULL DATETIME
                    } elseif (in_array($field, ['last_pms_date', 'next_pms_date'])) {
                        $value = null; // DATE fields can be NULL
                    } else {
                        $value = ''; // Default for strings/text
                    }
                }
                $values[] = "?";
                $params[] = $value;
                $types .= $field_types[$field];
            }

            $query = "INSERT INTO `{$vehicle['source_table']}` (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed for insert: " . $conn->error);
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                throw new Exception("Insert failed: " . $stmt->error);
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE pending_vehicles SET pending_status = ? WHERE id = ?");
        $status = $action === 'approve' ? 'Approved' : 'Rejected';
        $stmt->bind_param("si", $status, $vehicleId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header("Location: pending_edits.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Vehicle Approval Error: " . $e->getMessage());
        header("HTTP/1.1 500 Internal Server Error");
        exit("Processing error: " . $e->getMessage());
    }
}

$conn->close();
?>