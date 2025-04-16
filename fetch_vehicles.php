<?php
require 'db_connect.php';

$limit = 10; // Records per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';

// SQL Query with Filtering
$sql = "SELECT * FROM devices";
if (!empty($filter)) {
    $sql .= " WHERE equipment_type = ?";
}
$sql .= " LIMIT $limit OFFSET $offset";

$stmt = $conn->prepare($sql);

if (!empty($filter)) {
    $stmt->bind_param("s", $filter);
}

$stmt->execute();
$result = $stmt->get_result();

// Generate Table Rows
while ($row = $result->fetch_assoc()): 
    $daysElapsed = (int)$row['days_elapsed'];
    $daysContract = (int)$row['days_contract'];
    $highlightClass = ($daysElapsed > $daysContract) ? 'highlight-red' : ''; // Add class if overdue
?>
    <tr class="vehicle-row <?php echo $highlightClass; ?>" 
        data-lat="<?php echo htmlspecialchars($row['latitude']); ?>" 
        data-lon="<?php echo htmlspecialchars($row['longitude']); ?>"
        data-address="<?php echo htmlspecialchars($row['address']); ?>">
        <td><i class="fas fa-car"></i> <?php echo htmlspecialchars($row['target_name']); ?></td>
        <td><?php echo htmlspecialchars($row['license_plate_no']); ?></td>
        <td><?php echo htmlspecialchars($row['address']); ?></td> <!-- KEEPING ADDRESS DISPLAYED -->
	<td><?php echo htmlspecialchars($row['date_transferred']); ?></td>
	<td><?php echo htmlspecialchars($row['days_contract']); ?></td>
	<td><?php echo htmlspecialchars($row['date_ended']); ?></td>
	<td><?php echo htmlspecialchars($row['days_elapsed']); ?></td>
        <td><?php echo htmlspecialchars($row['equipment_type']); ?></td>
        <td>
            <?php 
                $status = htmlspecialchars($row['physical_status']);
                echo $status === "Operational" ? "🟢 Operational" : "🔴 Breakdown";
            ?>
        </td>
    </tr>
<?php endwhile; ?>
