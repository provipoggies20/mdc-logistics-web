<?php
/**
 * Checks if a pending change exists for a specific record.
 *
 * @param mysqli $conn Database connection object.
 * @param string $targetTable The name of the table (e.g., 'devices').
 * @param int $recordId The ID of the record.
 * @return bool True if a pending change exists, false otherwise.
 *
function hasPendingChange(mysqli $conn, string $targetTable, int $recordId): bool {
    $checkQuery = "SELECT id FROM pending_edits WHERE target_table = ? AND target_record_id = ? AND status = 'pending' LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    if (!$checkStmt) {
        error_log("Prepare failed for pending change check: " . $conn->error);
        return false; // Or handle error appropriately
    }
    $checkStmt->bind_param("si", $targetTable, $recordId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    return $checkResult->num_rows > 0;
}*/

$servername = "localhost";
$username = "root"; // Change this to your MySQL username
$password = ""; // Change this to your MySQL password
$database = "mdc"; // Your database name

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
