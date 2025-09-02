<?php
ob_start(); // Start output buffering
session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'User') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    ob_end_flush();
    exit();
}

require 'db_connect.php';

// Ensure database connection is valid
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: Unable to connect to database.']);
    ob_end_flush();
    exit();
}

// Validate input parameters
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id']) || !isset($_POST['table'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
    ob_end_flush();
    exit();
}

$id = trim($_POST['id']);
$table = trim($_POST['table']);
$valid_tables = ['devices', 'komtrax'];

// Validate table name
if (!in_array($table, $valid_tables)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid table specified.']);
    ob_end_flush();
    exit();
}

// Sanitize inputs to prevent SQL injection
$id = mysqli_real_escape_string($conn, $id);
$table = mysqli_real_escape_string($conn, $table);

// Check if the record exists
$check_query = "SELECT target_name FROM `$table` WHERE id = '$id'";
$check_result = $conn->query($check_query);

if (!$check_result) {
    error_log("Check query failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: Unable to verify record.']);
    ob_end_flush();
    exit();
}

if ($check_result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Record not found.']);
    ob_end_flush();
    exit();
}

$vehicle_name = $check_result->fetch_assoc()['target_name'];

// Perform deletion
$delete_query = "DELETE FROM `$table` WHERE id = '$id'";
if ($conn->query($delete_query)) {
    // Log successful deletion
    error_log("Vehicle deleted: ID=$id, Table=$table, Name=$vehicle_name, User={$_SESSION['user_id']}");
    echo json_encode(['success' => true, 'message' => "Vehicle '$vehicle_name' deleted successfully."]);
} else {
    error_log("Deletion failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: Unable to delete record.']);
}

$conn->close();
ob_end_flush();
?>