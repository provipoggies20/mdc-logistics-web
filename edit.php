<?php
session_start();
require 'db_connect.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Validate ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Invalid request.");
}

$id = intval($_GET['id']); // Sanitize input

// Fetch current data
$query = "SELECT * FROM devices WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$device = $result->fetch_assoc();

if (!$device) {
    die("Record not found.");
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $update_query = "UPDATE devices SET ";
    $params = [];
    $types = "";

    foreach ($_POST as $key => $value) {
        if ($key != "id") { // Avoid modifying ID
            $update_query .= "$key = ?, ";
            $params[] = $value !== "" ? $value : null; // Allow empty fields
            $types .= "s";
        }
    }

    $update_query = rtrim($update_query, ", "); // Remove last comma
    $update_query .= " WHERE id = ?";
    
    $params[] = $id;
    $types .= "i";

    $stmt = $conn->prepare($update_query);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        header("Location: information.php");
        exit();
    } else {
        echo "Error updating record.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vehicle Information</title>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script> <!-- Date picker -->
	<!-- Add this inside the <head> section -->
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
	<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            width: 90%;
            max-width: 800px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.2);
            text-align: center;
        }

        h2 {
            color: #007bff;
            margin-bottom: 15px;
        }

        .form-container {
            max-height: 500px;
            overflow-y: auto;
            padding: 10px 15px;
            margin-bottom: 10px;
            border-radius: 5px;
            background: #fff;
        }

        form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
        }

        .form-group {
            width: 48%;
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        label {
            font-weight: bold;
            margin-bottom: 5px;
        }

        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            transition: 0.3s;
            box-sizing: border-box;
        }

        input:hover, input:focus, select:hover, select:focus {
            border-color: #007bff;
            box-shadow: 0px 0px 5px rgba(0, 123, 255, 0.5);
            outline: none;
        }

        .btn-container {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
            width: 100%;
        }

        button {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.2);
        }

        button:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: scale(1.05);
        }

        .back-button {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s;
            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.2);
        }

        .back-button:hover {
            background: linear-gradient(135deg, #c82333, #a71d2a);
            transform: scale(1.05);
        }

        /* Responsive Design */
        @media (max-width: 600px) {
            .form-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Edit Vehicle Information</h2>
    <div class="form-container">
        <form method="POST">
            <?php foreach ($device as $field => $value): ?>
                <?php if ($field != "id"): ?>
                    <div class="form-group">
                        <label><?php echo strtoupper(str_replace('_', ' ', $field)); ?></label>
                        <?php if ($field == "date_transferred" || $field == "date_ended"): ?>
                            <input type="text" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo htmlspecialchars($value); ?>" placeholder="YYYY/MM/DD HH:MM:SS">
                        <?php elseif ($field == "days_contract" || $field == "days_elapsed"): ?>
                            <input type="text" name="<?php echo $field; ?>" id="<?php echo $field; ?>" value="<?php echo htmlspecialchars($value); ?>" readonly>
                        <?php elseif ($field == "physical_status"): ?>
                            <select name="physical_status" id="physical_status" required>
                                <option value="Operational" <?php if($value == 'Operational') echo 'selected'; ?>>Operational</option>
                                <option value="Inactive" <?php if($value == 'Inactive') echo 'selected'; ?>>Inactive</option>
                                <option value="Breakdown" <?php if($value == 'Breakdown') echo 'selected'; ?>>Breakdown</option>
                            </select>
                        <?php else: ?>
                            <input type="text" name="<?php echo $field; ?>" value="<?php echo htmlspecialchars($value); ?>">
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="hidden" name="id" value="<?php echo $device['id']; ?>">

            <div class="btn-container">
                <button type="submit">Update</button>
                <a href="information.php" class="back-button">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    flatpickr("#date_transferred, #date_ended", { 
        enableTime: true, 
        dateFormat: "Y/m/d H:i:S",
        time_24hr: true,
        allowInput: true
    });

    document.getElementById("date_ended").addEventListener("change", function () {
        let startDate = document.getElementById("date_transferred").value;
        let endDate = document.getElementById("date_ended").value;
        
        if (startDate && endDate) {
            let start = new Date(startDate);
            let end = new Date(endDate);
            let diff = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            document.getElementById("days_contract").value = diff > 0 ? diff : 0;
        }
    });
});
</script>

</body>
</html>
