<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}
require 'db_connect.php';

$allowed_columns = ['target_name', 'license_plate_no', 'position_time', 'address', 'assignment', 'equipment_type', 'physical_equipment'];
$sort_column = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_columns) ? $_GET['sort'] : 'target_name';
$sort_order = isset($_GET['order']) && $_GET['order'] == 'desc' ? 'DESC' : 'ASC';

// Get unique equipment types
$equipmentQuery = "SELECT DISTINCT equipment_type FROM aika ORDER BY equipment_type";
$equipmentResult = $conn->query($equipmentQuery);

$equipmentTypes = [];
while ($row = $equipmentResult->fetch_assoc()) {
    $equipmentTypes[] = $row['equipment_type'];
}

// Get selected filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';

// Pagination settings
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base query
$sql = "SELECT * FROM aika";
$params = [];
$types = "";

if (!empty($filter)) {
    $sql .= " WHERE equipment_type = ?";
    $params[] = $filter;
    $types .= "s";
}

// Get total count for pagination
$countSql = str_replace("SELECT *", "SELECT COUNT(*) AS total", $sql);
$countStmt = $conn->prepare($countSql);
if (!empty($filter)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Add pagination to SQL query
$sql .= " ORDER BY $sort_column $sort_order LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            text-align: center;
            margin: 20px;
        }
        .container {
            max-width: 95%;
            margin: auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
        }
        h2 {
            margin-bottom: 20px;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            background: white;
        }
        th, td {
            padding: 12px;
            text-align: center;
            border: 1px solid #ddd;
            word-wrap: break-word;
            white-space: normal;
        }
        th {
            background-color: #007bff;
            color: white;
            cursor: pointer;
        }
        tbody tr:hover {
            background-color: #f5f5f5;
            cursor: pointer;
        }
        .pagination {
            margin-top: 15px;
        }
        .pagination a {
            padding: 8px 12px;
            text-decoration: none;
            border: 1px solid #007bff;
            margin: 3px;
            color: #007bff;
            border-radius: 4px;
        }
        .pagination a.active {
            background-color: #007bff;
            color: white;
        }
        .filter-container {
            margin-bottom: 15px;
        }
        .filter-container select {
            padding: 8px;
            font-size: 16px;
        }
        .btn-container {
            margin-bottom: 15px;
        }
        a.button {
            padding: 10px 15px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Vehicles Real Time Monitoring</h2>

    <div class="btn-container">
        <a href="information.php" class="button">View Full Information</a>
        <a href="index.php" class="button">Home</a>
        <a href="logout.php" class="button">Logout</a>
    </div>

    <div class="filter-container">
        <form method="GET">
            <label for="filter"><strong>Filter by Equipment Type:</strong></label>
            <select name="filter" id="filter" onchange="this.form.submit()">
                <option value="">Show All</option>
                <?php foreach ($equipmentTypes as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>" 
                        <?php echo ($filter == $type) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th onclick="sortTable('target_name')">GPS NAME</th>
                    <th onclick="sortTable('license_plate_no')">LICENSE PLATE NO.</th>
                    <th>POSITION TIME</th>
                    <th>CURRENT LOCATION</th>
                    <th onclick="sortTable('equipment_type')">EQUIPMENT TYPE</th>
                    <th>PHYSICAL STATUS</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr onclick="openMap('<?php echo htmlspecialchars($row['address']); ?>')">
                        <td><?php echo htmlspecialchars($row['target_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['license_plate_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['position_time']); ?></td>
                        <td><?php echo htmlspecialchars($row['address']); ?></td>
                        <td><?php echo htmlspecialchars($row['equipment_type']); ?></td>
                        <td><?php echo htmlspecialchars($row['physical_status']); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&filter=<?php echo urlencode($filter); ?>" 
               class="<?php echo ($i == $page) ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
</div>

<script>
function openMap(address) {
    alert("Show geolocation for: " + address);
}
function sortTable(column) {
    let currentUrl = new URL(window.location.href);
    let order = currentUrl.searchParams.get("order") === "asc" ? "desc" : "asc";
    currentUrl.searchParams.set("sort", column);
    currentUrl.searchParams.set("order", order);
    window.location.href = currentUrl.toString();
}
</script>

</body>
</html>

<?php $conn->close(); ?>
