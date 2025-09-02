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

    $checkQuery = "SELECT id FROM pending_edits WHERE target_table = ? AND target_record_id = ? AND status = 'pending' LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    if (!$checkStmt) {
        error_log("Prepare failed for pending change check: " . $conn->error);
        echo json_encode(['error' => 'Database preparation error', 'hasPending' => false]);
        ob_end_flush();
        exit();
    }

    $checkStmt->bind_param("si", $table, $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $hasPending = $checkResult->num_rows > 0;
    $checkStmt->close();

    echo json_encode(['hasPending' => $hasPending, 'error' => null]);
} catch (Exception $e) {
    error_log("Exception in check_pending_edits.php: " . $e->getMessage());
    echo json_encode(['error' => 'Server error occurred', 'hasPending' => false]);
} finally {
    $conn->close();
    ob_end_flush();
}
?>