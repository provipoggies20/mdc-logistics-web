<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}
require 'db_connect.php';

// Allowed sorting columns
$allowed_columns = ['target_name', 'license_plate_no', 'position_time', 'address', 'equipment_type', 'physical_equipment'];
$sort_column = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_columns) ? $_GET['sort'] : 'target_name';
$sort_order = isset($_GET['order']) && $_GET['order'] == 'desc' ? 'DESC' : 'ASC';

// Get unique equipment types
$equipmentQuery = "SELECT DISTINCT equipment_type FROM devices ORDER BY equipment_type";
$equipmentResult = $conn->query($equipmentQuery);
$equipmentTypes = [];
while ($row = $equipmentResult->fetch_assoc()) {
    $equipmentTypes[] = $row['equipment_type'];
}

// Filter selection
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';

// Pagination
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base query
$sql = "SELECT * FROM devices";
$params = [];
$types = "";
if (!empty($filter)) {
    $sql .= " WHERE equipment_type = ?";
    $params[] = $filter;
    $types .= "s";
}

// Get total records
$countSql = str_replace("SELECT *", "SELECT COUNT(*) AS total", $sql);
$countStmt = $conn->prepare($countSql);
if (!empty($filter)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Final query with sorting & pagination
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
    <title>Bus Fleet Dashboard</title>
    <link rel="stylesheet" href="styles.css"> <!-- External CSS file -->
</head>
<body>

<div class="dashboard-container">
    <header>
        <h2>🚌 Bus Fleet Monitoring</h2>
        <nav>
            <a href="information.php" class="button">Full Info</a>
            <a href="index.php" class="button">Home</a>
            <a href="logout.php" class="button">Logout</a>
        </nav>
    </header>

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
                    <th onclick="sortTable('target_name')">🚍 BUS NAME</th>
                    <th onclick="sortTable('license_plate_no')">🚏 PLATE NO.</th>
                    <th>🕒 LAST SEEN</th>
                    <th>📍 LOCATION</th>
                    <th onclick="sortTable('equipment_type')">🛠️ TYPE</th>
                    <th>✅ STATUS</th>
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
                        <td>
                            <?php 
                                $status = htmlspecialchars($row['physical_status']);
                                echo $status === "Operational" ? "🟢 Operational" : "🔴 Maintenance";
                            ?>
                        </td>
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
