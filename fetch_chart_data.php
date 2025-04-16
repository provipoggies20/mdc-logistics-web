<?php
header('Content-Type: application/json');
include 'db_connect.php'; // Make sure you have a database connection file

$response = [];

// 1️⃣ Fetch Fleet Status Breakdown (Pie Chart)
$query = "SELECT 
            SUM(CASE WHEN physical_status = 'Operational' THEN 1 ELSE 0 END) AS operational,
            SUM(CASE WHEN physical_status = 'Inactive' THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE WHEN physical_status = 'Breakdown' THEN 1 ELSE 0 END) AS breakdown
          FROM aika";
$result = $conn->query($query);
$response['fleet_status'] = $result->fetch_assoc();

// 2️⃣ Fetch Overdue Vehicles Over Time (Line Chart)
$query = "SELECT 
            DATE(date_ended) AS date, 
            COUNT(*) AS overdue_count 
          FROM aika 
          WHERE days_elapsed > days_contract 
          GROUP BY DATE(date_ended) 
          ORDER BY DATE(date_ended) DESC 
          LIMIT 4"; // Last 4 weeks
$result = $conn->query($query);
$response['overdue_vehicles'] = [];
while ($row = $result->fetch_assoc()) {
    $response['overdue_vehicles'][] = $row;
}

// 3️⃣ Fetch Equipment Type Distribution (Bar Chart)
$query = "SELECT equipment_type, COUNT(*) AS count FROM aika GROUP BY equipment_type";
$result = $conn->query($query);
$response['equipment_distribution'] = [];
while ($row = $result->fetch_assoc()) {
    $response['equipment_distribution'][] = $row;
}

// DEBUG OUTPUT
echo json_encode($response, JSON_PRETTY_PRINT)
?>
