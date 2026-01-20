<?php
session_start();
require 'db_connect.php';

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Check if user has permission (not just User role)
if ($_SESSION['role'] === 'User') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit();
}

// Set execution time limit
set_time_limit(300); // 5 minutes

// Get the current directory
$currentDir = __DIR__;

// Path to Python script
$pythonScript = $currentDir . DIRECTORY_SEPARATOR . 'assignment_report_generator.py';

// Check if Python script exists
if (!file_exists($pythonScript)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Python script not found: ' . $pythonScript]);
    exit();
}

// Determine Python executable path
$pythonPaths = [
    'python',     // Linux/Mac preferred
    'python3',      // If Python 3 is default
    'C:\Users\GPSX2\AppData\Local\Programs\Python\Python313\python.exe', // Your Python 3.13
    'C:\\Python311\\python.exe',
    'C:\\Python310\\python.exe',
    'C:\\Python39\\python.exe'
];

$pythonExe = null;
foreach ($pythonPaths as $path) {
    $output = shell_exec($path . ' --version 2>&1');
    if ($output && strpos($output, 'Python') !== false) {
        $pythonExe = $path;
        break;
    }
}

if (!$pythonExe) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Python executable not found. Please install Python 3.']);
    exit();
}

// Execute Python script and capture output
$command = escapeshellarg($pythonExe) . ' ' . escapeshellarg($pythonScript) . ' 2>&1';
$output = shell_exec($command);

// Try to parse the JSON response from Python
$result = json_decode($output, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // JSON parsing failed - log the raw output
    error_log("Python script output (not JSON): " . $output);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid response from report generator',
        'details' => $output
    ]);
    exit();
}

// Check if generation was successful
if (!$result || !isset($result['success']) || !$result['success']) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Unknown error occurred',
        'details' => $result['details'] ?? ''
    ]);
    exit();
}

// Get the output file path
$outputFile = $result['file'] ?? null;

if (!$outputFile || !file_exists($outputFile)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Excel file not generated',
        'output' => $output
    ]);
    exit();
}

// Clear any output buffers
if (ob_get_level()) {
    ob_end_clean();
}

// Send the Excel file to the client
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . basename($outputFile) . '"');
header('Content-Length: ' . filesize($outputFile));
header('Cache-Control: max-age=0');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');
header('Expires: 0');

// Output the file
readfile($outputFile);

// Delete the file after sending
@unlink($outputFile);

exit();
?>