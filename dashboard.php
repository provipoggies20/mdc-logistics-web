<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}
require 'db_connect.php';

$current_page = basename($_SERVER['PHP_SELF']); // Get the current filename

// Get unique equipment types for filtering
$equipmentQuery = "SELECT DISTINCT equipment_type FROM devices ORDER BY equipment_type";
$equipmentResult = $conn->query($equipmentQuery);

$equipmentTypes = [];
while ($row = $equipmentResult->fetch_assoc()) {
    $equipmentTypes[] = $row['equipment_type'];
}

// Get vehicle count for statistics
$totalVehiclesQuery = "SELECT COUNT(*) as total FROM devices";
$activeVehiclesQuery = "SELECT COUNT(*) as total FROM devices WHERE physical_status = 'Operational'";
$inactiveVehiclesQuery = "SELECT COUNT(*) as total FROM devices WHERE physical_status = 'Inactive'";
$breakdownVehiclesQuery = "SELECT COUNT(*) as total FROM devices WHERE physical_status = 'Breakdown'"; // ✅ Added missing semicolon

$totalVehicles = $conn->query($totalVehiclesQuery)->fetch_assoc()['total'];
$activeVehicles = $conn->query($activeVehiclesQuery)->fetch_assoc()['total'];
$inactiveVehicles = $conn->query($inactiveVehiclesQuery)->fetch_assoc()['total'];
$breakdownVehicles = $conn->query($breakdownVehiclesQuery)->fetch_assoc()['total']; // ✅ Corrected variable name
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fleet Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
	<!-- Leaflet CSS -->
	<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />

	<!-- Leaflet JS -->
	<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
	
	<script src="js/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }
        body {
            display: flex;
            background: #f4f4f4;
        }
        .sidebar {
            width: 250px;
            background: #007bff;
            color: white;
            padding: 20px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
        }
        .sidebar h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        .sidebar ul {
            list-style: none;
            padding: 0;
        }
        .sidebar ul li {
            padding: 15px;
            cursor: pointer;
            transition: 0.3s;
        }
        .sidebar ul li:hover, .sidebar ul li.active {
            background: rgba(255, 255, 255, 0.2);
        }
        .sidebar ul li i {
            margin-right: 10px;
        }
        .main-content {
            margin-left: 260px;
            padding: 20px;
            width: 100%;
        }
        .fleet-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            flex: 1;
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
        }
        .stat-box h3 {
            margin-bottom: 5px;
        }
        .table-container {
            overflow-x: auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: center;
            border: 1px solid #ddd;
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
        .map-container {
			width: 100%;
			height: 500px; /* Adjust as needed */
			position: relative;
			border-radius: 8px; /* Match your UI */
			overflow: hidden;
		}
		map-container {
            margin-top: 20px;
            height: 500px;
            width: 100%;
            background: #ccc;
            text-align: center;
            line-height: 400px;
            font-size: 18px;
            color: black;
			position: relative;
			border-radius: 8px; /* Match your UI */
			overflow: hidden;
        }
		.highlight-red {
			background-color: #ffcccc !important; /* Light red */
			color: #d80000; /* Dark red text */
			font-weight: bold;
		}
		.notification-container {
			position: relative;
			text-align: center;
			margin-bottom: 15px;
		}

		#notificationIcon {
			font-size: 24px;
			cursor: pointer;
			color: white;
		}

		.badge {
			position: absolute;
			top: -5px;
			right: 10px;
			background: red;
			color: white;
			padding: 5px 10px;
			border-radius: 50%;
			font-size: 14px;
			font-weight: bold;
		}
		
		.charts-container {
			display: flex;
			justify-content: center;
			align-items: flex-start;
			flex-wrap: wrap;
			gap: 20px;
		}

		.chart-box {
			width: 100%;
			max-width: 400px;
			height: 250px;
			background: white;
			padding: 10px;
			border-radius: 10px;
			box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
			margin-bottom: 30px; /* Add spacing to prevent overlap */
		}

		.chart-row {
			display: flex;
			flex-direction: row;
			gap: 20px;
		}
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <h2>Fleet System</h2>
	<div class="notification-container">
    <i class="fas fa-bell" id="notificationIcon"></i>
    <span id="notificationBadge" class="badge" style="display: none;">0</span>
	</div>
	<?php
    $current_page = basename($_SERVER['PHP_SELF']); // Get current file name
	?>
    <ul>
        <li class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <a href="dashboard.php" style="color:white;">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        <li class="<?= ($current_page == 'information.php') ? 'active' : ''; ?>">
            <a href="information.php" style="color:white;">
                <i class="fas fa-car"></i> Vehicles
            </a>
        </li>
        <li class="<?= ($current_page == 'report.php') ? 'active' : ''; ?>">
            <a href="report.php" style="color:white;">
                <i class="fas fa-chart-line"></i> Reports
            </a>
        </li>
        <li class="<?= ($current_page == 'profile.php' || $current_page == 'preferences.php' || $current_page == 'notifications.php' || $current_page == 'backup.php') ? 'active' : ''; ?>">
            <a href="#" style="color:white;" onclick="toggleSettings()">
                <i class="fas fa-cogs"></i> Settings
            </a>
            <ul id="settings-menu" class="submenu">
                <li class="<?= ($current_page == 'profile.php') ? 'active' : ''; ?>">
                    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                </li>
                <li class="<?= ($current_page == 'preferences.php') ? 'active' : ''; ?>">
                    <a href="preferences.php"><i class="fas fa-paint-brush"></i> Preferences</a>
                </li>
                <li class="<?= ($current_page == 'notifications.php') ? 'active' : ''; ?>">
                    <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
                </li>
                <li class="<?= ($current_page == 'backup.php') ? 'active' : ''; ?>">
                    <a href="backup.php"><i class="fas fa-database"></i> Backup</a>
                </li>
            </ul>
        </li>
        <li class="<?= ($current_page == 'index.php') ? 'active' : ''; ?>">
            <a href="index.php" style="color:white;">
                <i class="fas fa-home"></i> Home
            </a>
        </li>
        <li>
            <a href="logout.php" style="color:white;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </li>
    </ul>
</div>

<script>
function toggleSettings() {
    document.getElementById("settings-menu").classList.toggle("active");
}
</script>

<style>
.submenu {
    display: none;
    list-style: none;
    padding-left: 15px;
}
.submenu.active {
    display: block;
}
</style>

<!-- Main Content -->
<div class="main-content">
    <h2>Vehicles Real-Time Monitoring</h2>

    <!-- Fleet Overview -->
    <div class="fleet-stats">
        <div class="stat-box">
            <h3>Total Vehicles</h3>
            <p><?php echo $totalVehicles; ?></p>
        </div>
        <div class="stat-box">
            <h3>Active Vehicles</h3>
            <p><?php echo $activeVehicles; ?></p>
        </div>
        <div class="stat-box">
            <h3>Inactive Vehicles</h3>
            <p><?php echo $inactiveVehicles; ?></p>
        </div>
        <div class="stat-box">
            <h3>Maintenance</h3>
            <p><?php echo $breakdownVehicles; ?></p>
        </div>
    </div>
	
	<div class="charts-container">

    <!-- Pie Chart, Line Chart & Bar Chart in the Same Row -->
    <div class="chart-row">
		<div class="chart-box">
        <canvas id="fleetStatusChart"></canvas>
		</div>
        <div class="chart-box">
            <canvas id="overdueVehiclesChart"></canvas>
        </div>
        <div class="chart-box">
            <canvas id="equipmentDistributionChart"></canvas>
        </div>
    </div>
</div>


    <!-- Table -->
    <div class="table-container">
    <!-- Filter Dropdown -->
    <label for="equipmentFilter">Filter by Equipment Type:</label>
    <select id="equipmentFilter">
        <option value="">All Equipment Types</option>
        <?php foreach ($equipmentTypes as $type): ?>
            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
        <?php endforeach; ?>
    </select>

    <!-- Table -->
    <table>
        <thead>
            <tr>
                <th>VEHICLE</th>
                <th>LICENSE PLATE</th>
                <th>LOCATION</th>
		<th>DATE TRANSFERRED</th>
		<th>DAYS CONTRACT</th>
		<th>DATE ENDED</th>
		<th>DAYS ELAPSED</th>
                <th>EQUIPMENT TYPE</th>
                <th>PHYSICAL STATUS</th>
            </tr>
        </thead>
        <tbody id="vehicleTableBody">
            <!-- AJAX will load table data here -->
        </tbody>
    </table>

    <!-- Pagination Controls -->
    <div id="paginationControls">
        <button id="prevPage">Previous</button>
        <span id="pageInfo">Page 1</span>
        <button id="nextPage">Next</button>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    let currentPage = 1;
    let filterType = "";
    
    // Initialize Map
    var map = L.map('map').setView([14.5995, 120.9842], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    var marker = null;

    function loadTable(page, filter) {
        fetch(`fetch_vehicles.php?page=${page}&filter=${filter}`)
            .then(response => response.text())
            .then(data => {
                document.getElementById('vehicleTableBody').innerHTML = data;
                document.getElementById('pageInfo').innerText = `Page ${currentPage}`;
                
                // Attach click event for geolocation
                document.querySelectorAll('.vehicle-row').forEach(row => {
                    row.addEventListener('click', function() {
                        let lat = parseFloat(this.getAttribute('data-lat'));
                        let lon = parseFloat(this.getAttribute('data-lon'));
                        let address = this.getAttribute('data-address');

                        if (!isNaN(lat) && !isNaN(lon)) {
                            updateMap(lat, lon, address);
                        } else {
                            alert("No GPS data available for this vehicle.");
                        }
                    });
                });
            });
    }

    function updateMap(lat, lon, address) {
        if (marker) map.removeLayer(marker);
        marker = L.marker([lat, lon]).addTo(map)
            .bindPopup(`<b>Vehicle Location</b><br>Address: ${address}<br>Lat: ${lat}<br>Lon: ${lon}`)
            .openPopup();
        map.setView([lat, lon], 14);
    }

    document.getElementById('equipmentFilter').addEventListener('change', function() {
        filterType = this.value;
        currentPage = 1;  // Reset to first page when filter changes
        loadTable(currentPage, filterType);
    });

    document.getElementById('prevPage').addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadTable(currentPage, filterType);
        }
    });

    document.getElementById('nextPage').addEventListener('click', function() {
        currentPage++;
        loadTable(currentPage, filterType);
    });

    loadTable(currentPage, filterType);
});
</script>

    <!-- Map -->
    <!-- Map Container -->
<div id="map" class="map-container"></div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    let currentPage = 1;
    let filterType = "";

    // Initialize OpenStreetMap (Leaflet.js)
    var map = L.map('map').setView([14.5995, 120.9842], 12); // Default: Manila, PH
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    var marker = null; // Marker placeholder

    function loadTable(page, filter) {
        fetch(`fetch_vehicles.php?page=${page}&filter=${filter}`)
            .then(response => response.text())
            .then(data => {
                document.getElementById('vehicleTableBody').innerHTML = data;
                document.getElementById('pageInfo').innerText = `Page ${currentPage}`;
                
                // Add event listeners to rows for geolocation
                document.querySelectorAll('.vehicle-row').forEach(row => {
                    row.addEventListener('click', function() {
                        let lat = parseFloat(this.getAttribute('data-lat'));
                        let lon = parseFloat(this.getAttribute('data-lon'));
                        let location = this.getAttribute('data-location');
                        updateMap(lat, lon, location);
                    });
                });
            });
    }

    function updateMap(lat, lon, location) {
        if (!isNaN(lat) && !isNaN(lon)) {
            if (marker) map.removeLayer(marker); // Remove existing marker
            marker = L.marker([lat, lon]).addTo(map)
                .bindPopup(`<b>Location:</b> ${location}`).openPopup();
            map.setView([lat, lon], 14); // Zoom into location
        } else {
            alert("No GPS data available for this vehicle.");
        }
    }

    document.getElementById('equipmentFilter').addEventListener('change', function() {
        filterType = this.value;
        currentPage = 1;
        loadTable(currentPage, filterType);
    });

    document.getElementById('prevPage').addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadTable(currentPage, filterType);
        }
    });

    document.getElementById('nextPage').addEventListener('click', function() {
        currentPage++;
        loadTable(currentPage, filterType);
    });

    loadTable(currentPage, filterType);
});
</script>

<script>
function showLocation(address) {
    document.getElementById('map').innerText = "Showing location for: " + address;
}
</script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {
    function fetchNotifications() {
        $.ajax({
            url: 'fetch_notifications.php',
            method: 'GET',
            dataType: 'json',
            success: function (response) {
                let overdueCount = response.overdue_count;
                let badge = $("#notificationBadge");

                console.log("🔄 Fetched overdue count:", overdueCount); // Debugging

                if (overdueCount > 0) {
                    badge.text(overdueCount).show();
                } else {
                    badge.hide();
                }
            },
            error: function (error) {
                console.error("❌ Error fetching notifications:", error);
            }
        });
    }

    fetchNotifications();
    setInterval(fetchNotifications, 5000);
});
</script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    fetch('fetch_chart_data.php')
        .then(response => response.json())
        .then(data => {
            // 🌟 Common Chart Options
            const commonOptions = {
                responsive: true,
                maintainAspectRatio: false, // Allows better height control
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12, // Smaller legend boxes
                            font: { size: 12 }
                        }
                    }
                }
            };

            // 📊 Fleet Status (Pie Chart)
            new Chart(document.getElementById('fleetStatusChart'), {
                type: 'pie',
                data: {
                    labels: ['Operational', 'Inactive', 'Breakdown'],
                    datasets: [{
                        data: [data.fleet_status.operational, data.fleet_status.inactive, data.fleet_status.breakdown],
                        backgroundColor: ['#4CAF50', '#FFC107', '#F44336']
                    }]
                },
                options: { 
                    ...commonOptions,
                    cutout: '35%' // Semi-donut look
                }
            });

            // 📈 Overdue Vehicles Over Time (Line Chart)
            new Chart(document.getElementById('overdueVehiclesChart'), {
                type: 'line',
                data: {
                    labels: data.overdue_vehicles.map(item => item.date),
                    datasets: [{
                        label: 'Overdue Vehicles',
                        data: data.overdue_vehicles.map(item => item.overdue_count),
                        borderColor: '#2196F3',
                        borderWidth: 2,
                        fill: false,
                        pointBackgroundColor: '#2196F3',
                        tension: 0.3
                    }]
                },
                options: { 
                    ...commonOptions,
                    scales: {
                        x: { ticks: { font: { size: 10 } } },
                        y: { ticks: { font: { size: 10 } } }
                    }
                }
            });

            // 📊 Equipment Type Distribution (Bar Chart)
            new Chart(document.getElementById('equipmentDistributionChart'), {
                type: 'bar',
                data: {
                    labels: data.equipment_distribution.map(item => item.equipment_type),
                    datasets: [{
                        label: 'Count',
                        data: data.equipment_distribution.map(item => item.count),
                        backgroundColor: '#673AB7',
                        borderColor: '#512DA8',
                        borderWidth: 1
                    }]
                },
                options: { 
                    ...commonOptions,
                    scales: {
                        x: { ticks: { font: { size: 10 } } },
                        y: { ticks: { font: { size: 10 } } }
                    }
                }
            });
        })
        .catch(error => console.error('Error loading chart data:', error));
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    let currentPage = window.location.pathname.split("/").pop(); // Get current filename
    let sidebarLinks = document.querySelectorAll(".sidebar li a");

    sidebarLinks.forEach(link => {
        if (link.getAttribute("href") === currentPage) {
            link.parentElement.classList.add("active");
        }
    });
});
</script>

</body>
</html>

<?php $conn->close(); ?>
