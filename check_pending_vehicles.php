<?php
// Start output buffering to capture any unintended output
ob_start();
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

// Suppress all notices and warnings
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['error' => 'Unauthorized access', 'hasPending' => false]);
        ob_end_flush();
        exit();
    }

    if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id']) || 
        !isset($_GET['table']) || !in_array($_GET['table'], ['devices', 'komtrax'])) {
        echo json_encode(['error' => 'Invalid request parameters', 'hasPending' => false]);
        ob_end_flush();
        exit();
    }

    $id = intval($_GET['id']);
    $table = $_GET['table'];

    // Check for pending edits on the specific record
    $editQuery = "SELECT id FROM pending_edits WHERE target_table = ? AND target_record_id = ? AND status = 'pending' LIMIT 1";
    $editStmt = $conn->prepare($editQuery);
    if (!$editStmt) {
        error_log("Prepare failed for pending edit check: " . $conn->error);
        echo json_encode(['error' => 'Database preparation error', 'hasPending' => false]);
        ob_end_flush();
        exit();
    }
    $editStmt->bind_param("si", $table, $id);
    $editStmt->execute();
    $editResult = $editStmt->get_result();
    $hasPendingEdits = $editResult->num_rows > 0;
    $editStmt->close();

    // Check for pending vehicle additions for the same table
    $vehicleQuery = "SELECT id FROM pending_vehicles WHERE source_table = ? AND pending_status = 'Pending' LIMIT 1";
    $vehicleStmt = $conn->prepare($vehicleQuery);
    if (!$vehicleStmt) {
        error_log("Prepare failed for pending vehicle check: " . $conn->error);
        echo json_encode(['error' => 'Database preparation error', 'hasPending' => false]);
        ob_end_flush();
        exit();
    }
    $vehicleStmt->bind_param("s", $table);
    $vehicleStmt->execute();
    $vehicleResult = $vehicleStmt->get_result();
    $hasPendingVehicles = $vehicleResult->num_rows > 0;
    $vehicleStmt->close();

    // Return true if either pending edits or pending vehicles exist
    $hasPending = $hasPendingEdits || $hasPendingVehicles;

    echo json_encode(['hasPending' => $hasPending, 'error' => null]);
} catch (Exception $e) {
    error_log("Exception in check_pending_edits.php: " . $e->getMessage());
    echo json_encode(['error' => 'Server error occurred', 'hasPending' => false]);
} finally {
    $conn->close();
    ob_end_flush();
}
?>