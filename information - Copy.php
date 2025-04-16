<?php
session_start();
require 'db_connect.php'; 

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch all data from the 'devices' table
$sql = "SELECT * FROM devices";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Information Page</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f4f4f4;
        }

        h2 {
            text-align: center;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 10px;
            text-align: center;
            border: 1px solid #ddd;
            word-wrap: break-word;
            white-space: normal;
        }

        th {
            background-color: #333;
            color: white;
        }

        tbody tr:hover {
            background-color: #f5f5f5; /* Light gray highlight */
            cursor: pointer;
        }

        .edit-btn {
            display: inline-block;
            padding: 5px 10px;
            background-color: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }

        .edit-btn:hover {
            background-color: #218838;
        }
    </style>
</head>
<body>

    <h2>Device Information</h2>

    <table>
        <thead>
            <tr>
                <?php
                // Fetch column names dynamically
                $query = "SHOW COLUMNS FROM devices";
                $columnsResult = $conn->query($query);
                while ($column = $columnsResult->fetch_assoc()) {
                    echo "<th>" . strtoupper(str_replace('_', ' ', $column['Field'])) . "</th>";
                }
                ?>
                <th>ACTIONS</th> <!-- Edit button column -->
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <?php foreach ($row as $key => $value): ?>
                        <td><?php echo htmlspecialchars($value); ?></td>
                    <?php endforeach; ?>
                    <td><a class="edit-btn" href="edit.php?id=<?php echo $row['id']; ?>">Edit</a></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

</body>
</html>
