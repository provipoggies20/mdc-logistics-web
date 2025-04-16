<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}
require 'db_connect.php';

// Date filter
$whereClause = "";
if (isset($_GET['date_range']) && !empty($_GET['date_range'])) {
    $dateRange = $_GET['date_range'];
    if ($dateRange == "today") {
        $whereClause = "WHERE DATE(timestamp) = CURDATE()";
    } elseif ($dateRange == "this_month") {
        $whereClause = "WHERE MONTH(timestamp) = MONTH(CURDATE()) AND YEAR(timestamp) = YEAR(CURDATE())";
    } elseif ($dateRange == "this_year") {
        $whereClause = "WHERE YEAR(timestamp) = YEAR(CURDATE())";
    }
}

// Fetch logs
$query = "SELECT * FROM logs $whereClause ORDER BY timestamp DESC";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Logs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; }
        .container { width: 80%; margin: auto; padding: 20px; }
        h2 { text-align: center; }
        .filter-container { display: flex; justify-content: center; margin-bottom: 20px; }
        select { padding: 10px; margin-right: 10px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px; text-align: center; border: 1px solid #ddd; }
        th { background-color: #007bff; color: white; }
        tr:hover { background-color: #f5f5f5; }
        .no-data { text-align: center; padding: 20px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Recent Changes</h2>

    <!-- Filter -->
    <div class="filter-container">
        <form method="GET">
            <select name="date_range" onchange="this.form.submit()">
                <option value="">All Time</option>
                <option value="today" <?= (isset($_GET['date_range']) && $_GET['date_range'] == "today") ? "selected" : ""; ?>>Today</option>
                <option value="this_month" <?= (isset($_GET['date_range']) && $_GET['date_range'] == "this_month") ? "selected" : ""; ?>>This Month</option>
                <option value="this_year" <?= (isset($_GET['date_range']) && $_GET['date_range'] == "this_year") ? "selected" : ""; ?>>This Year</option>
            </select>
        </form>
    </div>

    <!-- Table -->
    <table>
        <thead>
            <tr>
                <th>User</th>
                <th>Role</th>
                <th>Action</th>
                <th>Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['user']) ?></td>
                        <td><?= htmlspecialchars($row['role']) ?></td>
                        <td><?= htmlspecialchars($row['action']) ?></td>
                        <td><?= htmlspecialchars($row['timestamp']) ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4" class="no-data">No logs found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>

<?php $conn->close(); ?>
