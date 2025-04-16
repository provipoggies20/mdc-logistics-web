<?php
session_start();
require 'db_connect.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $device_name = $_POST['device_name'];
    $device_type = $_POST['device_type'];

    $sql = "INSERT INTO aika (device_name, device_type) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $device_name, $device_type);

    if ($stmt->execute()) {
        header("Location: dashboard.php");
        exit();
    } else {
        echo "Error: " . $stmt->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Device</title>
</head>
<body>
    <h2>Add New Device</h2>
    <form action="add_device.php" method="POST">
        <label>Device Name:</label>
        <input type="text" name="device_name" required><br><br>

        <label>Device Type:</label>
        <input type="text" name="device_type" required><br><br>

        <button type="submit">Add Device</button>
    </form>
    <br>
    <a href="dashboard.php">Back to Dashboard</a>
</body>
</html>
