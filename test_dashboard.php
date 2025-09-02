<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}
require 'db_connect.php';

$current_page = basename($_SERVER['PHP_SELF']); // Get the current filename

// Get unique equipment types for filtering
$equipmentTypes = [];

// Fetch distinct equipment types from devices
$equipmentQueryDevices = "SELECT DISTINCT equipment_type FROM devices ORDER BY equipment_type";
$equipmentResultDevices = $conn->query($equipmentQueryDevices);

while ($row = $equipmentResultDevices->fetch_assoc()) {
    $equipmentTypes[] = $row['equipment_type'];
}

// Fetch distinct equipment types from komatsu
$equipmentQueryKomatsu = "SELECT DISTINCT equipment_type FROM komatsu ORDER BY equipment_type";
$equipmentResultKomatsu = $conn->query($equipmentQueryKomatsu);

while ($row = $equipmentResultKomatsu->fetch_assoc()) {
    // Avoid duplicates
    if (!in_array($row['equipment_type'], $equipmentTypes)) {
        $equipmentTypes[] = $row['equipment_type'];
    }
}

// Get vehicle count for statistics
$totalVehiclesQuery = "SELECT 
    COUNT(*) as total,
    SUM(physical_status = 'Operational') as active,
    SUM(physical_status = 'Inactive') as inactive,
    SUM(physical_status = 'Breakdown') as breakdown
FROM devices";

$result = $conn->query($totalVehiclesQuery);

if (!$result) {
    die("Database query failed: " . $conn->error);
}

$data = $result->fetch_assoc();
$totalVehicles = $data['total'];
$activeVehicles = $data['active'];
$inactiveVehicles = $data['inactive'];
$breakdownVehicles = $data['breakdown'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fleet Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }
        body {
            display: flex;
            margin: 0;
            font-family: Arial, sans-serif;
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
        .table-sub-container {
            margin-bottom: 30px;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .table-sub-container h2 {
            font-size: 1.2rem;
            color: #004080;
            margin-bottom: 10px ;
        }
        .table-wrapper {
            overflow-x: auto;
            margin-top: 15px;
        }
        button.view-btn, button.edit-btn {
            padding: 5px 10px;
            margin: 0 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button.view-btn {
            background-color: #4CAF50;
            color: white;
        }
        button.edit-btn {
            background-color: #2196F3;
            color: white;
        }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px 0;
        }
        .pagination-button {
            background-color: #007bff; /* Primary color */
            color: white;
            border: none;
            border-radius: 5px;
            padding: 10px 15px;
            margin: 0 5px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s;
        }
        .pagination-button:hover {
            background-color: #0056b3; /* Darker shade on hover */
            transform: scale(1.05); /* Slightly enlarge on hover */
        }
        .pagination-button:disabled {
            background-color: #ccc; /* Disabled button color */
            cursor: not-allowed; /* Change cursor for disabled */
        }
        .pagination-info {
            margin: 0 10px;
            font-size: 16px;
            font-weight: bold;
        }
        .new-entry {
            animation: highlight 2s ease;
            background-color: rgba(0, 200, 0, 0.1);
            border-left: 3px solid #28a745;
        }
        @keyframes highlight {
            0% { background-color: rgba(0, 200, 0, 0.3); }
            100% { background-color: rgba(0, 200, 0, 0.1); }
        }
        .vehicle-table {
            transition: opacity 0.3s ease;
        }
        .refreshing {
            opacity: 0.7;
        }
        .overdue {
            background-color: #ffcccc; /* Light red for overdue contracts */
        }
        .newly-updated {
            background-color: #ccffcc; /* Light green for newly updated vehicles */
        }
        .breakdown {
            background-color: #ffffcc; /* Light yellow for breakdown vehicles */
        }
        #notificationDropdown {
            display: none; /* Initially hidden */
            position: absolute;
            background-color: white;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000; /* Ensure it appears above other elements */
            width: 250px; /* Set a width for the dropdown */
        }
        #notificationDropdown div {
            padding: 10px;
            cursor: pointer; /* Change cursor to pointer for better UX */
        }
        #notificationDropdown div:hover {
            background-color: #f0f0f0; /* Highlight on hover */
        }
        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background: #007bff;
            color: white;
            padding: 20px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            transition: transform 0.3s ease; /* Smooth transition */
            overflow: hidden; /* Hide overflow when collapsed */
        }
        .sidebar.hidden {
            transform: translateX(-100%); /* Slide out to the left */
        }
        /* Main Content Styles */
        .main-content {
            flex-grow: 1;
            margin-left: 250px; /* Default margin for sidebar */
            padding: 20px;
            width: calc(100% - 250px); /* Adjust width based on sidebar */
            transition: margin-left 0.3s ease, width 0.3s ease; /* Smooth transition */
        }
        .main-content.collapsed {
            margin-left: 0; /* No margin when sidebar is hidden */
            width: 100%; /* Full width when sidebar is hidden */
        }
        .table-sub-container h2 {
            font-size: 1.2rem;
            color: #004080;
            margin-bottom: 30px; /* Increased margin to lower the heading */
            margin-top: 10px; /* Optional: Add top margin if needed */
        }
        h2 {
            margin-bottom: 30px; /* Adjust this value for all h2 elements */
        }
		a:hover {
			background-color: #f0f0f0; /* Light gray background on hover */
		}
    </style>
</head>
<body>

<!-- Toggle Button -->
<button id="toggleSidebarButton" style="position: fixed; top: 20px; left: 20px; background: #007bff; color: white; border: none; padding: 10px; border-radius: 5px; cursor: pointer; z-index: 1000;">
    <i class="fas fa-bars"></i>
</button>

<!-- 🔔 Notification Bell Icon -->
<div id="notificationWrapper" style="position: fixed; top: 20px; right: 30px; z-index: 1000;">
    <div id="notificationIcon" style="cursor: pointer; position: relative;">
        🔔
        <span id="notificationCount" style="position: absolute; top: -10px; right: -10px; background-color: red; color: white; font-size: 10px; padding: 2px 5px; border-radius: 50%; display: none;">0</span>
    </div>
    <div id="notificationDropdown" style="display: none; position: absolute; top: 25px; right: 0; background: white; border: 1px solid #ccc; border-radius: 5px; width: 300px; max-height: 300px; overflow-y: auto; box-shadow: 0 4px 8px rgba(0,0,0,0.1); padding: 10px; font-size: 14px; color: #333;"></div>
</div>

<!-- Sidebar -->
<div class="sidebar">
    <h2>Fleet System</h2>
    <ul>
        <li class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <a href="dashboard.php" style="color:white;">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        <li class="<?= ($current_page == 'information.php') ? 'active' : ''; ?>">
            < a href="information.php" style="color:white;">
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
    <h2><center>Vehicles Real-Time Monitoring</center></h2>

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

    <!-- Filter Dropdown -->
    <label for="equipmentFilter">Filter by Equipment Type:</label>
    <select id="equipmentFilter">
        <option value="">All Equipment Types</option>
        <?php foreach ($equipmentTypes as $type): ?>
            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
        <?php endforeach; ?>
    </select>

    <!-- Table 1: Contract Monitoring -->
    <div class="table-sub-container">
        <h2>Vehicle List (Contract Monitoring)</h2>
        <div class="table-wrapper">
            <table class="vehicle-table" id="tableNoPhysicalStatus">
                <thead>
                    <tr>
                        <th>VEHICLE</th>
                        <th>ASSIGNMENT</th>
                        <th>DATE TRANSFERRED</th>
                        <th>DAYS CONTRACT</th>
                        <th>DATE ENDED</th>
                        <th>DAYS ELAPSED</th>
                        <th>DAYS LAPSES</th>
                        <th>REMARKS</th>
                    </tr>
                </thead>
                <tbody id="noPhysicalStatusBody">
                    <!-- Populated dynamically via JS -->
                </tbody>
            </table>
        </div>
        <!-- Pagination for Table 1 -->
        <div class="pagination" id="paginationControls1">
            <button id="prevPage1" class="pagination -button">Previous</button>
            <span id="pageInfo1" class="pagination-info">Page 1</span>
            <button id="nextPage1" class="pagination-button">Next</button>
        </div>
    </div>

    <!-- Table 2: Maintenance Monitoring -->
    <div class="table-sub-container">
        <h2>Vehicle List (Maintenance Monitoring)</h2>
        <div class="table-wrapper">
            <table class="vehicle-table" id="tableNoContractDates">
                <thead>
                    <tr>
                        <th>VEHICLE</th>
                        <th>ASSIGNMENT</th>
                        <th>PHYSICAL STATUS</th>
                    </tr>
                </thead>
                <tbody id="maintenanceTableBody">
                    <!-- Populated dynamically via JS -->
                </tbody>
            </table>
        </div>
        <div class="pagination" id="paginationControls2">
            <button id="prevPage2" class="pagination-button">Previous</button>
            <span id="pageInfo2" class="pagination-info">Page 1</span>
            <button id="nextPage2" class="pagination-button">Next</button>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    let currentPage1 = 1; // Current page for Table 1
    let currentPage2 = 1; // Current page for Table 2
    const itemsPerPage = 10; // Items per page
    let filterType = ""; // Filter for equipment type
    let refreshInterval = 5000; // Auto-refresh interval
    let refreshTimer;
    let lastCheckTime = Math.floor(Date.now() / 1000); // Last check timestamp

    // Function to load data for Table 1 (Contract Monitoring)
    function loadTable1(page, filter) {
        fetch(`fetch_vehicles.php?page=${page}&filter=${filter}&lastCheck=${lastCheckTime}`)
            .then(response => response.json())
            .then(data => {
                const body1 = document.getElementById('noPhysicalStatusBody');
                body1.innerHTML = '';

                if (data.contractVehicles) {
                    data.contractVehicles.forEach(vehicle => {
                        const row = document.createElement('tr');
                        row.onclick = () => {
                            window.location.href = `information.php?target_name=${vehicle.target_name}`;
                        };

                        if (vehicle.is_overdue) {
                            row.classList.add('overdue');
                        }
                        if (vehicle.is_updated) {
                            row.classList.add('newly-updated');
                        }

                        row.innerHTML = `
                            <td>${vehicle.target_name}</td>
                            <td>${vehicle.assignment}</td>
                            <td>${vehicle.date_transferred}</td>
                            <td>${vehicle.days_contract}</td>
                            <td>${vehicle.date_ended}</td>
                            <td>${vehicle.days_elapsed}</td>
                            <td>${vehicle.days_lapses}</td>
                            <td>${vehicle.remarks}</td>
                        `;
                        body1.appendChild(row);
                    });
                } else {
                    console.error('contractVehicles is undefined in the response');
                }
                // Update pagination info for Table 1
                document.getElementById('pageInfo1').innerText = `Page ${page}`;
            })
            .catch(error => console.error("Error fetching contract vehicles:", error));
    }

    // Function to load data for Table 2 (Maintenance Monitoring)
    function loadTable2(page, filter) {
        fetch(`fetch_vehicles.php?page=${page}&filter=${filter}&lastCheck=${lastCheckTime}`)
            .then(response => response.json())
            .then(data => {
                const body2 = document.getElementById('maintenanceTableBody');
                body2.innerHTML = ''; // Clear previous data

                if (data.maintenanceVehicles) {
                    data.maintenanceVehicles.forEach(vehicle => {
                        const row = document.createElement('tr');
                        row.onclick = () => {
                            window.location.href = `information.php?target_name=${vehicle.target_name}`;
                        };

                        // Highlight breakdown and newly updated vehicles
                        if (vehicle.is_breakdown) {
                            row.classList.add('breakdown');
                        }
                        if (vehicle.is_updated) {
                            row.classList.add('newly-updated');
                        }

                        row.innerHTML = `
                            <td>${vehicle.target_name}</td>
                            <td>${vehicle.assignment}</td>
                            <td>${vehicle.physical_status}</td>
                        `;
                        body2.appendChild(row);
                    });
                } else {
                    console.error('maintenanceVehicles is undefined in the response');
                }

                // Update pagination info for Table 2
                document.getElementById('pageInfo2').innerText = `Page ${page}`;
            })
            .catch(error => console.error("Error fetching maintenance vehicles:", error));
    }

    // Event listener for filter dropdown
    document.getElementById('equipmentFilter').addEventListener('change', function() {
        filterType = this.value; // Get selected filter value
        currentPage1 = 1; // Reset to first page
        currentPage2 = 1; // Reset to first page
        loadTable1(currentPage1, filterType); // Load filtered data for Table 1
        loadTable2(currentPage2, filterType); // Load filtered data for Table 2
    });

    // Event listeners for pagination buttons for Table 1
    document.getElementById('prevPage1').addEventListener('click', () => {
        if (currentPage1 > 1) {
            currentPage1--;
            loadTable1(currentPage1, filterType);
        }
    });

    document.getElementById('nextPage1').addEventListener('click', () => {
        currentPage1++;
        loadTable1(currentPage1, filterType);
    });

    // Event listeners for pagination buttons for Table 2
    document.getElementById('prevPage2').addEventListener('click', () => {
        if (currentPage2 > 1) {
            currentPage2--;
            loadTable2(currentPage2, filterType);
        }
    });

    document.getElementById('nextPage2').addEventListener('click', () => {
        currentPage2++;
        loadTable2(currentPage2, filterType);
    });

    // Initial load for both tables
    loadTable1(currentPage1, filterType);
    loadTable2(currentPage2, filterType);

    // Auto-refresh functionality
    refreshTimer = setInterval(() => {
        lastCheckTime = Math.floor(Date.now() / 1000);
        loadTable1(currentPage1, filterType);
        loadTable2(currentPage2, filterType);
    }, refreshInterval);
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const bellIcon = document.getElementById('notificationIcon');
    const dropdown = document.getElementById('notificationDropdown');

    // Function to fetch and display notifications
    function fetchNotifications() {
        fetch('fetch_notifications.php')
            .then(response => response.json())
            .then(data => {
                dropdown.innerHTML = ''; // Clear previous notifications
                let count = 0;

                if (data.length > 0) {
                    count = data.length;
                    data.forEach(notification => {
                        dropdown.innerHTML += `
                            <a href="information.php?target_name=${notification.target_name}" style="text-decoration: none; color: inherit; display: block; padding: 5px 0;">
                                ${notification.message} - ${notification.target_name} (${notification.equipment_type})
                            </a>`;
                    });
                }

                const countSpan = document.getElementById('notificationCount');
                countSpan.style.display = count > 0 ? 'inline' : 'none';
                countSpan.textContent = count;
            })
            .catch(error => console.error('Error fetching notifications:', error));
    }

    // Toggle dropdown visibility on bell icon click
    bellIcon.addEventListener('click', function (event) {
        event.stopPropagation(); // Prevent the click from propagating to the document
        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        fetchNotifications(); // Fetch notifications when the bell icon is clicked
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function (event) {
        if (!bellIcon.contains(event.target) && !dropdown.contains(event.target)) {
            dropdown.style.display = 'none';
        }
    });

    // Fetch notifications every 10 seconds
    setInterval(fetchNotifications, 10000);
    fetchNotifications(); // Initial fetch
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

<script>
document.addEventListener("DOMContentLoaded", function () {
    const toggleButton = document.getElementById("toggleSidebarButton");
    const sidebar = document.querySelector(".sidebar");
    const mainContent = document.querySelector(".main-content");

    // Check local storage for sidebar state
    if (localStorage.getItem("sidebarHidden") === "true") {
        sidebar.classList.add("hidden");
        mainContent.classList.add("collapsed"); // Adjust main content when sidebar is hidden
        toggleButton.innerHTML = '<i class="fas fa-bars"></i>';
    }

    toggleButton.addEventListener("click", () => {
        sidebar.classList.toggle("hidden");
 mainContent.classList.toggle("collapsed"); // Toggle the collapsed class on main content
        const isHidden = sidebar.classList.contains("hidden");
        localStorage.setItem("sidebarHidden", isHidden); // Save state in local storage
        toggleButton.innerHTML = isHidden ? '<i class="fas fa-bars"></i>' : '<i class="fas fa-times"></i>';
    });
});
</script>

</body>
</html>

<?php $conn->close(); ?>