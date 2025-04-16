<?php
session_start();
require 'db_connect.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

$id = $_GET['id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $device_name = $_POST['device_name'];
    $device_type = $_POST['device_type'];

    $sql = "UPDATE devices SET device_name = ?, device_type = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $device_name, $device_type, $id);

    if ($stmt->execute()) {
        header("Location: dashboard.php");
        exit();
    } else {
        echo "Error: " . $stmt->error;
    }
}

$sql = "SELECT * FROM devices WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$device = $result->fetch_assoc();

if (!$device) {
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Device</title>
</head>
<body>
    <h2>Edit Device</h2>
    <form action="edit_device.php?id=<?php echo $id; ?>" method="POST">
        <label>Device Name:</label>
        <input type="text" name="device_name" value="<?php echo htmlspecialchars($device['device_name']); ?>" required><br><br>

        <label>Device Type:</label>
        <input type="text" name="device_type" value="<?php echo htmlspecialchars($device['device_type']); ?>" required><br><br>

        <button type="submit">Update Device</button>
    </form>
    <br>
    <a href="dashboard.php">Back to Dashboard</a>
</body>
</html>
