<?php
session_start();
require 'db_connect.php';

function hasPendingChange(mysqli $conn, string $targetTable, int $recordId): bool {
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
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id']) || !ctype_digit((string)$_GET['id']) || 
    !isset($_GET['table']) || !in_array($_GET['table'], ['devices', 'komtrax'])) {
    die("Invalid request: Missing or invalid ID or table.");
}

$id = intval($_GET['id']);
$table = $_GET['table'];
$errorMessage = '';
$successMessage = '';

$query = "SELECT * FROM $table WHERE id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed for fetching record: " . $conn->error);
    die("Error preparing database query.");
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$record = $result->fetch_assoc();
$stmt->close();

if (!$record) {
    die("Record not found in $table.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (hasPendingChange($conn, $table, $id)) {
        $errorMessage = "This record has changes pending approval. Please wait until they are reviewed before submitting new changes.";
    } else {
        $fields_to_approve_base = ['assignment', 'date_ended', 'physical_status', 'last_pms_date', 'next_pms_date', 'pms_interval', 'date_transferred', 'days_contract'];
        $komtrax_additional_approval = ['tag', 'specs'];
        $fields_to_approve = $table === 'komtrax' ? array_merge($fields_to_approve_base, $komtrax_additional_approval) : $fields_to_approve_base;
        $changed_data = [];

        // Fetch original record
        $query = "SELECT * FROM $table WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $record = $result->fetch_assoc();
        $stmt->close();

        // Process editable fields
        foreach ($fields_to_approve as $field) {
            if (!array_key_exists($field, $record) || $field === 'days_contract') {
                continue; // Skip calculated fields for direct input processing
            }
            $original_value = $record[$field] ?? null;
            $submitted_value = isset($_POST[$field]) ? trim($_POST[$field]) : null;
            $submitted_value = ($submitted_value === "") ? null : $submitted_value;

            if ($field === 'date_transferred' || $field === 'date_ended') {
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
                        $errorMessage .= " Invalid format for " . strtoupper(str_replace('_', ' ', $field)) . ". ";
                    }
                } elseif ($original_value !== null) {
                    $changed_data[$field] = null;
                }
            } elseif ($field === 'last_pms_date' || $field === 'next_pms_date') {
                if ($submitted_value !== null) {
                    try {
                        $submitted_dt = DateTime::createFromFormat('m-d-Y', $submitted_value);
                        if (!$submitted_dt) {
                            error_log("Invalid date format submitted for $field in $table (expected m-d-Y): " . $submitted_value);
                            $errorMessage .= " Invalid format for " . strtoupper(str_replace('_', ' ', $field)) . " (use MM-DD-YYYY). ";
                            continue;
                        }
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
            } elseif (in_array($field, ['assignment', 'physical_status', 'pms_interval', 'tag', 'specs'])) {
                if ((string)$original_value !== (string)$submitted_value) {
                    if ($field === 'pms_interval' && $submitted_value !== null && !ctype_digit((string)$submitted_value)) {
                        $errorMessage .= " PMS Interval must be a whole number (days). ";
                    } else {
                        $changed_data[$field] = $submitted_value;
                    }
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

        if (!empty($changed_data) && empty($errorMessage)) {
            $proposed_data_json = json_encode($changed_data);
            $requested_by_user_id = $_SESSION['user_id'];
            $edit_type = 'update';

            $insert_query = "INSERT INTO pending_edits (target_table, target_record_id, edit_type, proposed_data, requested_by_user_id, status) VALUES (?, ?, ?, ?, ?, 'pending')";
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) {
                error_log("Prepare failed for inserting pending edit: " . $conn->error);
                $errorMessage = "Error submitting changes for approval. Please try again.";
            } else {
                $insert_stmt->bind_param("sissi", $table, $id, $edit_type, $proposed_data_json, $requested_by_user_id);
                if ($insert_stmt->execute()) {
                    $successMessage = "Changes submitted for approval successfully.";
                    header("Refresh: 3; url=information.php");
                } else {
                    $errorMessage = "Error submitting changes for approval: " . $insert_stmt->error;
                    error_log("Pending edit insert error: " . $insert_stmt->error);
                }
                $insert_stmt->close();
            }
        } elseif (empty($changed_data) && empty($errorMessage)) {
            $successMessage = "No relevant changes detected for fields requiring approval.";
            header("Refresh: 3; url=information.php");
        }
    }
}

// Calculate days_contract, days_elapsed, and days_lapses for display
$days_contract = 0;
$days_elapsed = 0;
$days_lapses = 0;

if (!empty($record['date_transferred'])) {
    try {
        $start = new DateTime($record['date_transferred']);
        $now = new DateTime();
        $days_elapsed = $now->diff($start)->days;
    } catch (Exception $e) {
        error_log("Error calculating days_elapsed for $table ID $id: " . $e->getMessage() . " - date_transferred: " . $record['date_transferred']);
    }
}

if (!empty($record['date_ended'])) {
    try {
        $end = new DateTime($record['date_ended']);
        $now = new DateTime();
        $days_lapses = max(0, $now->diff($end)->days);
    } catch (Exception $e) {
        error_log("Error calculating days_lapses for $table ID $id: " . $e->getMessage() . " - date_ended: " . $record['date_ended']);
    }
}

if (!empty($record['date_transferred']) && !empty($record['date_ended'])) {
    try {
        $start = new DateTime($record['date_transferred']);
        $end = new DateTime($record['date_ended']);
        if ($end >= $start) {
            $days_contract = $end->diff($start)->days;
        }
    } catch (Exception $e) {
        error_log("Error calculating days_contract for $table ID $id: " . $e->getMessage() . " - date_transferred: " . $record['date_transferred'] . ", date_ended: " . $record['date_ended']);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit <?php echo ucfirst($table); ?> Information</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; }
        .container { width: 90%; max-width: 800px; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.2); text-align: center; margin-top: 20px; }
        h2 { color: #007bff; margin-bottom: 15px; }
        .form-container { max-height: 70vh; overflow-y: auto; padding: 10px 15px; margin-bottom: 10px; border-radius: 5px; background: #fff; border: 1px solid #eee; }
        form { display: flex; flex-wrap: wrap; gap: 15px; justify-content: space-between; }
        .form-group { width: calc(50% - 10px); display: flex; flex-direction: column; text-align: left; margin-bottom: 10px; }
        label { font-weight: bold; margin-bottom: 5px; color: #333; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; transition: 0.3s; box-sizing: border-box; background-color: #f9f9f9; }
        input[readonly] { background-color: #e9ecef; cursor: not-allowed; }
        input:hover:not([readonly]), input:focus:not([readonly]), select:hover, select:focus { border-color: #007bff; box-shadow: 0 0 5px rgba(0,123,255,0.5); outline: none; background-color: #fff; }
        .btn-container { margin-top: 20px; display: flex; gap: 10px; justify-content: center; width: 100%; }
        button[type="submit"], .back-button { color: white; padding: 12px 20px; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.2); text-decoration: none; display: inline-block; text-align: center; }
        button[type="submit"] { background: linear-gradient(135deg, #28a745, #218838); }
        button[type="submit"]:hover { background: linear-gradient(135deg, #218838, #1e7e34); transform: scale(1.05); }
        button[type="submit"]:disabled { background: #cccccc; cursor: not-allowed; transform: none; box-shadow: none; }
        .back-button { background: linear-gradient(135deg, #6c757d, #5a6268); }
        .back-button:hover { background: linear-gradient(135deg, #5a6268, #4e555b); transform: scale(1.05); }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; font-weight: bold; text-align: center; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        @media (max-width: 700px) { .form-group { width: 100%; } }
    </style>
</head>
<body>

<div class="container">
    <h2>Edit <?php echo ucfirst($table); ?> Information (ID: <?php echo $id; ?>)</h2>

    <?php if ($errorMessage): ?>
        <div class="message error"><?php echo htmlspecialchars(trim($errorMessage)); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="message success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="edit-form" <?php if ($errorMessage && strpos($errorMessage, 'pending approval') !== false) echo 'onsubmit="alert(\'Cannot submit: This record has changes pending approval.\'); return false;"'; ?>>
            <?php
            $fields_readonly_system_base = [
                'id', 'days_contract', 'last_updated', 'position_time', 'address', 'latitude', 'longitude', 
                'speed', 'direction', 'total_mileage', 'status', 'type', 'days_elapsed', 'days_lapses', 
                'speed_limit', 'days_no_gps'
            ];
            $komtrax_additional_readonly = [
                'cut_address', 'last_assignment', 'last_days_contract', 'last_date_transferred', 
                'last_date_ended', 'last_days_elapsed', 'remarks'
            ];
            $fields_readonly_system = $table === 'devices' ? 
                $fields_readonly_system_base :
                array_merge($fields_readonly_system_base, $komtrax_additional_readonly);
            $fields_readonly_form = array_merge($fields_readonly_system, ['next_pms_date']);
            ?>

            <?php foreach ($record as $field => $value): ?>
                <?php if ($field != "id"): ?>
                    <div class="form-group">
                        <label for="<?php echo $field; ?>"><?php echo strtoupper(str_replace('_', ' ', $field)); ?>:</label>

                        <?php
                        $is_readonly_form = in_array($field, $fields_readonly_form);
                        $readonly_attr = $is_readonly_form ? ' readonly' : '';
                        $input_value_raw = $value ?? '';
                        $input_value_escaped = htmlspecialchars($input_value_raw);

                        if ($field == "date_transferred" || $field == "date_ended"):
                            $input_class = 'date-field';
                            $placeholder = "YYYY-MM-DD HH:MM:SS";
                            $current_value = $input_value_escaped ?: (new DateTime())->format('Y-m-d H:i:s');
                        ?>
                            <input type="text" class="<?php echo trim($input_class); ?>" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo $current_value; ?>" placeholder="<?php echo $placeholder; ?>" <?php echo $readonly_attr; ?>>
                        <?php elseif ($field == "last_pms_date"):
                            $input_class = 'pms-date-field';
                            $placeholder = "MM-DD-YYYY";
                            $display_value = '';
                            if (!empty($input_value_raw)) {
                                try { $display_value = (new DateTime($input_value_raw))->format('m-d-Y'); } catch (Exception $e) { $display_value = $input_value_escaped; }
                            }
                        ?>
                            <input type="text" class="<?php echo trim($input_class); ?>" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo $display_value; ?>" placeholder="<?php echo $placeholder; ?>" <?php echo $readonly_attr; ?>>
                        <?php elseif ($field == "next_pms_date"):
                            $input_class = 'pms-date-field';
                            $placeholder = "MM-DD-YYYY (Auto)";
                            $display_value = '';
                            if (!empty($input_value_raw)) {
                                try { $display_value = (new DateTime($input_value_raw))->format('m-d-Y'); } catch (Exception $e) { $display_value = $input_value_escaped; }
                            }
                        ?>
                            <input type="text" class="<?php echo trim($input_class); ?>" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo $display_value; ?>" placeholder="<?php echo $placeholder; ?>" readonly>
                        <?php elseif ($field == "pms_interval"): ?>
                            <input type="number" min="0" step="1" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo $input_value_escaped; ?>" placeholder="Days" <?php echo $readonly_attr; ?>>
                        <?php elseif ($field == "days_contract" || $field == "days_elapsed" || $field == "days_lapses" || $field == "days_no_gps"): ?>
                            <input type="number" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo $field == 'days_contract' ? $days_contract : ($field == 'days_elapsed' ? $days_elapsed : ($field == 'days_lapses' ? $days_lapses : $input_value_escaped)); ?>" readonly>
                        <?php elseif ($field == "last_date_transferred" || $field == "last_date_ended"): ?>
                            <input type="text" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo $input_value_escaped; ?>" readonly>
                        <?php elseif ($field == "physical_status"): ?>
                            <select name="physical_status" id="physical_status" required <?php echo $readonly_attr; ?>>
                                <option value="">Select Status...</option>
                                <option value="Operational" <?php if($input_value_raw == 'Operational') echo 'selected'; ?>>Operational</option>
                                <option value="Inactive" <?php if($input_value_raw == 'Inactive') echo 'selected'; ?>>Inactive</option>
                                <option value="Breakdown" <?php if($input_value_raw == 'Breakdown') echo 'selected'; ?>>Breakdown</option>
                            </select>
                        <?php else: ?>
                            <input type="text" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo $input_value_escaped; ?>" <?php echo $readonly_attr; ?>>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <input type="hidden" name="id" value="<?php echo $record['id']; ?>">
            <input type="hidden" name="table" value="<?php echo $table; ?>">

            <div class="btn-container">
                <button type="submit" <?php if ($errorMessage && strpos($errorMessage, 'pending approval') !== false) echo ' disabled'; ?>>Submit Changes for Approval</button>
                <a href="information.php" class="back-button">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    // Initialize Flatpickr for date_transferred and date_ended
    flatpickr(".date-field", {
        enableTime: true,
        dateFormat: "Y-m-d H:i:s",
        time_24hr: true,
        allowInput: true,
        defaultDate: new Date() // Set default to current date and time
    });

    // Initialize Flatpickr for PMS date fields
    const pmsDatePickers = flatpickr(".pms-date-field", {
        enableTime: false,
        dateFormat: "m-d-Y",
        allowInput: true,
        onChange: function(selectedDates, dateStr, instance) {
            if (instance.element.id === 'last_pms_date') {
                calculateNextPmsDate();
            }
        }
    });

    const nextPmsDatePicker = document.getElementById('next_pms_date')?._flatpickr;

    const lastPmsDateInput = document.getElementById("last_pms_date");
    const pmsIntervalInput = document.getElementById("pms_interval");
    const nextPmsDateInput = document.getElementById("next_pms_date");

    function calculateNextPmsDate() {
        if (!lastPmsDateInput || !pmsIntervalInput || !nextPmsDateInput || !nextPmsDatePicker) {
            console.warn("PMS date inputs missing for next_pms_date calculation");
            return;
        }

        const lastPmsStr = lastPmsDateInput.value;
        const intervalStr = pmsIntervalInput.value;
        const intervalDays = parseInt(intervalStr, 10);

        if (isNaN(intervalDays) || intervalDays < 0 || intervalStr !== intervalDays.toString()) {
            nextPmsDateInput.value = "";
            if (nextPmsDatePicker) {
                nextPmsDatePicker.clear();
            }
            return;
        }

        const lastPmsDate = flatpickr.parseDate(lastPmsStr, "m-d-Y");
        if (lastPmsDate instanceof Date && !isNaN(lastPmsDate)) {
            try {
                const nextPmsDate = new Date(lastPmsDate.getTime());
                nextPmsDate.setDate(nextPmsDate.getDate() + intervalDays);
                const formattedNextPmsDate = flatpickr.formatDate(nextPmsDate, "m-d-Y");
                nextPmsDateInput.value = formattedNextPmsDate;
                if (nextPmsDatePicker) {
                    nextPmsDatePicker.setDate(nextPmsDate, false);
                }
            } catch (e) {
                console.error("Error calculating next PMS date:", e);
                nextPmsDateInput.value = "";
                if (nextPmsDatePicker) {
                    nextPmsDatePicker.clear();
                }
            }
        } else {
            nextPmsDateInput.value = "";
            if (nextPmsDatePicker) {
                nextPmsDatePicker.clear();
            }
        }
    }

    if (pmsIntervalInput) {
        pmsIntervalInput.addEventListener("input", calculateNextPmsDate);
    }
    calculateNextPmsDate();

    // Calculate days_contract, days_elapsed, and days_lapses
    const dateTransferredInput = document.getElementById("date_transferred");
    const dateEndedInput = document.getElementById("date_ended");
    const daysContractInput = document.getElementById("days_contract");
    const daysElapsedInput = document.getElementById("days_elapsed");
    const daysLapsesInput = document.getElementById("days_lapses");

    function calculateContractAndLapses() {
        if (!dateTransferredInput || !dateEndedInput || !daysContractInput || !daysElapsedInput || !daysLapsesInput) {
            console.warn("Missing inputs for contract/lapse calculations: ", {
                dateTransferred: !!dateTransferredInput,
                dateEnded: !!dateEndedInput,
                daysContract: !!daysContractInput,
                daysElapsed: !!daysElapsedInput,
                daysLapses: !!daysLapsesInput
            });
            return;
        }

        const startStr = dateTransferredInput.value;
        const endStr = dateEndedInput.value;
        const now = new Date();
        let contractDays = 0;
        let elapsedDays = 0;
        let lapsedDays = 0;

        // Calculate days_contract
        try {
            const start = flatpickr.parseDate(startStr, "Y-m-d H:i:s");
            const end = flatpickr.parseDate(endStr, "Y-m-d H:i:s");
            if (start && end && !isNaN(start) && !isNaN(end) && end >= start) {
                const diffTime = end.getTime() - start.getTime();
                contractDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
            }
        } catch (e) {
            console.error("Error parsing dates for days_contract:", e, "start:", startStr, "end:", endStr);
        }
        daysContractInput.value = contractDays >= 0 ? contractDays : 0;

        // Calculate days_elapsed
        try {
            const start = flatpickr.parseDate(startStr, "Y-m-d H:i:s");
            if (start && !isNaN(start)) {
                const elapsedTime = now.getTime() - start.getTime();
                elapsedDays = Math.floor(elapsedTime / (1000 * 60 * 60 * 24));
            }
        } catch (e) {
            console.error("Error parsing date_transferred for days_elapsed:", e, "start:", startStr);
        }
        daysElapsedInput.value = elapsedDays >= 0 ? elapsedDays : 0;

        // Calculate days_lapses
        try {
            const end = flatpickr.parseDate(endStr, "Y-m-d H:i:s");
            if (end && !isNaN(end) && now > end) {
                const lapsedTime = now.getTime() - end.getTime();
                lapsedDays = Math.floor(lapsedTime / (1000 * 60 * 60 * 24));
            }
        } catch (e) {
            console.error("Error parsing date_ended for days_lapses:", e, "end:", endStr);
        }
        daysLapsesInput.value = lapsedDays >= 0 ? lapsedDays : 0;

        console.debug("Calculated: ", { contractDays, elapsedDays, lapsedDays, startStr, endStr });
    }

    // Attach event listeners
    if (dateTransferredInput) {
        dateTransferredInput.addEventListener("change", calculateContractAndLapses);
    }
    if (dateEndedInput) {
        dateEndedInput.addEventListener("change", calculateContractAndLapses);
    }

    // Run initial calculation
    calculateContractAndLapses();
});
</script>

</body>
</html>