<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}
require 'db_connect.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$allowed_columns = ['target_name', 'license_plate_no', 'latitude', 'longitude', 'position_time', 'address', 
'equipment_type', 'physical_equipment'
];
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
$limit = 10; // Adjust as needed
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
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
$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Fetch data with sorting and pagination
//$sql = "SELECT * FROM aika ORDER BY $sort_column $sort_order LIMIT $limit OFFSET $offset";
//$result = $conn->query($sql);

// Define hidden columns
$hidden_columns = [
    'id', 'Assignment', 'date_transferred', 'days_contract', 'date_ended', 'days_elapsed', 
    'days_no_gps', 'last_assignment', 'last_days_contract', 'last_date_transferred', 
    'last_date_ended', 'last_days_elapsed', 'speed_limit', 'speed', 'direction', 'gps_id', 'conduction_sticker', 
	'tag', 'specs', 'remarks', 'total_mileage', 'type'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <style>
        .table-container {
            max-width: 100%;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }
        th, td {
			padding: 8px;
			text-align: center; /* Center alignment */
			border: 1px solid #ddd;
			word-wrap: break-word;
			white-space: normal;
			max-width: 300px;
		}

		th {
			background-color: #f2f2f2;
			font-weight: bold;
		}
		
		tbody tr:hover {
        background-color: #f5f5f5; /* Light gray highlight */
        cursor: pointer;
    }
		}
    </style>
</head>
<body>
    <h2>Devices Summary</h2>

    <a href="information.php">View Full Information</a>

    <div class="table-container">
	<form method="GET">
    <label for="filter">Filter by Equipment Type:</label>
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

        <table border="1">
    <thead>
        <tr>
            <th>TARGET NAME</th>
            <th>LICENSE PLATE NO.</th>
            <th>LATITUDE</th>
            <th>LONGUTUDE</th>
            <th>POSITION TIME</th>
            <th>ADDRESS</th>
            <th>EQUIPMENT TYPE</th>
            <th>PHYSICAL STATUS</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['target_name']); ?></td>
                <td><?php echo htmlspecialchars($row['license_plate_no']); ?></td>
                <td><?php echo htmlspecialchars($row['latitude']); ?></td>
                <td><?php echo htmlspecialchars($row['longitude']); ?></td>
                <td><?php echo htmlspecialchars($row['position_time']); ?></td>
                <td><?php echo htmlspecialchars($row['address']); ?></td>
                <td><?php echo htmlspecialchars($row['equipment_type']); ?></td>
                <td><?php echo htmlspecialchars($row['physical_status']); ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
    </div>

    <!-- Pagination -->
    <div>
    <?php if ($totalPages > 1): ?>
        <nav>
            <ul>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li style="display: inline; margin-right: 5px;">
                        <a href="?page=<?php echo $i; ?>&filter=<?php echo urlencode($filter); ?>"
                            style="text-decoration: none; padding: 5px 10px; border: 1px solid black;
                            <?php echo ($i == $page) ? 'background-color: gray; color: white;' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

    <br>
    <a href="index.php">Back to Home</a> | <a href="logout.php">Logout</a>
</body>
</html>

<?php $conn->close(); ?>
