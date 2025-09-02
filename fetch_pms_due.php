<?php
session_start();

// Check session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.html");
    exit();
}

require 'db_connect.php';

// Ensure database connection
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Server error: Unable to connect to database.");
}

// Set timezone
date_default_timezone_set('Asia/Manila');
$current_date = '2025-05-22 21:21:00';

// Vehicle statistics
$totalVehicles = 0;
$activeVehicles = 0;
$inactiveVehicles = 0;
$breakdownVehicles = 0;
$statsQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(LOWER(physical_status) = 'operational') as active,
        SUM(LOWER(physical_status) = 'inactive') as inactive,
        SUM(LOWER(physical_status) = 'breakdown') as breakdown
    FROM (
        SELECT target_name, physical_status 
        FROM devices 
        WHERE equipment_type IS NOT NULL
        UNION
        SELECT target_name, physical_status 
        FROM komtrax 
        WHERE equipment_type IS NOT NULL
    ) AS combined_vehicles";
$statsResult = $conn->query($statsQuery);
if ($statsResult) {
    $data = $statsResult->fetch_assoc();
    $totalVehicles = $data['total'] ?? 0;
    $activeVehicles = $data['active'] ?? 0;
    $inactiveVehicles = $data['inactive'] ?? 0;
    $breakdownVehicles = $data['breakdown'] ?? 0;
} else {
    error_log("Statistics query failed: " . $conn->error);
}

// PMS due and nearing vehicles
$dueVehicles = [];
$pmsQuery = "
    SELECT target_name, equipment_type, 
        CASE 
            WHEN next_pms_date <= CURDATE() THEN 'Due'
            WHEN next_pms_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Nearing'
        END AS status
    FROM (
        SELECT target_name, equipment_type, next_pms_date
        FROM devices 
        WHERE equipment_type IS NOT NULL AND next_pms_date IS NOT NULL AND next_pms_date != '0000-00-00'
        UNION
        SELECT target_name, equipment_type, next_pms_date
        FROM komtrax 
        WHERE equipment_type IS NOT NULL AND next_pms_date IS NOT NULL AND next_pms_date != '0000-00-00'
    ) AS combined_vehicles
    WHERE next_pms_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
$pmsResult = $conn->query($pmsQuery);
if ($pmsResult) {
    while ($row = $pmsResult->fetch_assoc()) {
        $dueVehicles[] = [
            'target_name' => $row['target_name'],
            'equipment_type' => $row['equipment_type'],
            'status' => $row['status']
        ];
    }
} else {
    error_log("PMS query failed: " . $conn->error);
}

// Contract monitoring
$contractVehicles = [];
$contractQuery = "
    SELECT target_name, assignment, date_transferred, days_contract, date_ended, source
    FROM (
        SELECT target_name, assignment, date_transferred, days_contract, date_ended, 'devices' as source
        FROM devices 
        WHERE equipment_type IS NOT NULL AND physical_status != 'Operational' AND date_ended IS NOT NULL
        UNION
        SELECT target_name, assignment, date_transferred, days_contract, date_ended, 'komtrax' as source
        FROM komtrax 
        WHERE equipment_type IS NOT NULL AND physical_status != 'Operational' AND date_ended IS NOT NULL
    ) AS combined_vehicles";
$contractResult = $conn->query($contractQuery);
if ($contractResult) {
    while ($row = $contractResult->fetch_assoc()) {
        $days_elapsed = 0;
        $days_lapses = 0;
        $date_transferred = $row['date_transferred'] ? new DateTime($row['date_transferred']) : null;
        $date_ended = $row['date_ended'] ? new DateTime($row['date_ended']) : null;
        $current = new DateTime($current_date);

        if ($date_transferred) {
            $interval = $current->diff($date_transferred);
            $days_elapsed = $interval->days;
        }
        if ($date_ended && $date_ended <= $current) {
            $interval = $current->diff($date_ended);
            $days_lapses = $interval->days;
        }
        $days_contract = (int)($row['days_contract'] ?? 0);
        $is_overdue = $days_elapsed > $days_contract && $days_contract > 0;

        if ($date_ended && $date_ended <= $current) {
            $contractVehicles[] = [
                'target_name' => $row['target_name'],
                'assignment' => $row['assignment'],
                'days_lapses' => $days_lapses,
                'source' => $row['source'],
                'is_overdue' => $is_overdue
            ];
        }
    }
} else {
    error_log("Contract query failed: " . $conn->error);
}

// Maintenance monitoring
$maintenanceVehicles = [];
$maintenanceQuery = "
    SELECT target_name, equipment_type, physical_status, source
    FROM (
        SELECT target_name, equipment_type, physical_status, 'devices' as source
        FROM devices 
        WHERE equipment_type IS NOT NULL AND LOWER(physical_status) = 'breakdown'
        UNION
        SELECT target_name, equipment_type, physical_status, 'komtrax' as source
        FROM komtrax 
        WHERE equipment_type IS NOT NULL AND LOWER(physical_status) = 'breakdown'
    ) AS combined_vehicles";
$maintenanceResult = $conn->query($maintenanceQuery);
if ($maintenanceResult) {
    while ($row = $maintenanceResult->fetch_assoc()) {
        $maintenanceVehicles[] = [
            'target_name' => $row['target_name'],
            'equipment_type' => $row['equipment_type'],
            'physical_status' => $row['physical_status'] ?? 'N/A',
            'source' => $row['source']
        ];
    }
} else {
    error_log("Maintenance query failed: " . $conn->error);
}

// Geofence status
$geofenceVehicles = [];
$geofenceQuery = "
    SELECT target_name, assignment, status, source
    FROM (
        SELECT target_name, assignment, status, 'devices' as source
        FROM geofence 
        WHERE target_name IN (SELECT target_name FROM devices WHERE equipment_type IS NOT NULL)
        UNION
        SELECT target_name, assignment, status, 'komtrax' as source
        FROM geofence 
        WHERE target_name IN (SELECT target_name FROM komtrax WHERE equipment_type IS NOT NULL)
    ) AS combined_vehicles";
$geofenceResult = $conn->query($geofenceQuery);
if ($geofenceResult) {
    while ($row = $geofenceResult->fetch_assoc()) {
        $geofenceVehicles[] = [
            'target_name' => $row['target_name'],
            'assignment' => $row['assignment'],
            'status' => $row['status'],
            'source' => $row['source'],
            'is_inside' => stripos($row['status'], 'inside') !== false
        ];
    }
} else {
    error_log("Geofence query failed: " . $conn->error);
}

$current_page = basename($_SERVER['PHP_SELF']);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PMS Due Summary</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        body {
            display: flex;
            background-color: #f0f2f5;
        }
        .fleet-stats {
            display: flex;
            gap: 8px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .stat-box {
            flex: 1;
            background: #fff;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            min-width: 120px;
        }
        .stat-box h3 {
            font-size: 0.9rem;
            color: #333;
        }
        .stat-box p {
            font-size: 1.3rem;
            font-weight: bold;
            color: #007bff;
        }
        .table-sub-container {
            margin-bottom: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            padding: 12px;
        }
        .table-sub-container h2 {
            font-size: 1.1rem;
            color: #004080;
            margin-bottom: 10px;
            text-align: center;
        }
        h2 {
            margin-bottom: 15px;
            color: #004080;
            text-align: center;
            font-size: 1.4rem;
        }
        .card-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
            padding: 8px;
            background: #f9faff;
            border-radius: 8px;
            width: 100%;
            box-sizing: border-box;
        }
        .card {
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
            padding: 8px;
            border-left: 3px solid transparent;
            width: 180px;
            transition: transform 0.2s ease;
        }
        .card:hover {
            transform: translateY(-2px);
        }
        .card-header {
            display: flex;
            align-items: center;
            gap: 5px;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
            margin-bottom: 5px;
        }
        .card-header i {
            font-size: 1rem;
            color: #555;
        }
        .card-header span {
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .card-field {
            font-size: 0.8rem;
            margin-bottom: 4px;
            display: flex;
            gap: 4px;
        }
        .card-field label {
            font-weight: 500;
            color: #444;
            min-width: 60px;
        }
        .card-field span {
            color: #222;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .due {
            border-left-color: #e60000;
            background: linear-gradient(135deg, #ffe6e6, #fff);
        }
        .nearing {
            border-left-color: #e68a00;
            background: linear-gradient(135deg, #fff3cd, #fff);
        }
        .overdue {
            border-left-color: #e60000;
            background: linear-gradient(135deg, #ffe6e6, #fff);
        }
        .breakdown {
            border-left-color: #e68a00;
            background: linear-gradient(135deg, #fff3cd, #fff);
        }
        .inside-geofence {
            border-left-color: #00b300;
            background: linear-gradient(135deg, #e6ffe6, #fff);
        }
        .komtrax-vehicle {
            background: linear-gradient(135deg, #e6f3ff, #fff);
        }
        .no-results {
            text-align: center;
            padding: 10px;
            color: #28a745;
            font-size: 0.9rem;
            background: #e6ffed;
            border-radius: 6px;
            grid-column: 1 / -1;
        }
        .sidebar {
            width: 200px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 12px;
            height: 100vh;
            position: fixed;
            transition: transform 0.3s ease;
            box-shadow: 2px 0 8px rgba(0, 0, 0, 0.2);
        }
        .sidebar.hidden {
            transform: translateX(-100%);
        }
        .sidebar-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 5px;
            cursor: pointer;
            color: #e0e0e0;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        .sidebar-nav:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .sidebar-nav.highlight {
            border-left: 2px solid #ff4757;
            background: rgba(255, 71, 87, 0.1);
        }
        .sidebar ul {
            margin-top: 12px;
        }
        .sidebar ul li {
            margin: 5px 0;
        }
        .sidebar ul li.active {
            background: rgba(255, 255, 255, 0.15);
        }
        .sidebar ul li i {
            font-size: 0.9rem;
            width: 18px;
        }
        .main-content {
            flex-grow: 1;
            margin-left: 200px;
            padding: 15px;
            width: calc(100% - 200px);
            transition: margin-left 0.3s ease, width 0.3s ease;
        }
        .main-content.collapsed {
            margin-left: 0;
            width: 100%;
        }
        #toggleSidebarButton {
            position: fixed;
            top: 50%;
            left: 200px;
            transform: translateY(-50%);
            background: #007bff;
            color: white;
            border: none;
            padding: 6px;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
            z-index: 1000;
            transition: left 0.3s ease;
        }
        #toggleSidebarButton.hidden {
            left: 0;
        }
        @media (max-width: 500px) {
            .fleet-stats {
                flex-direction: column;
            }
            .card-container {
                grid-template-columns: 1fr;
            }
            .card {
                width: 100%;
                max-width: none;
            }
            .sidebar {
                width: 180px;
            }
            .main-content {
                margin-left: 180px;
                width: calc(100% - 180px);
            }
            #toggleSidebarButton {
                left: 180px;
            }
        }
        @media (min-width: 501px) and (max-width: 800px) {
            .card-container {
                grid-template-columns: repeat(2, 1fr);
            }
            .card {
                width: 100%;
            }
        }
        @media (min-width: 801px) {
            .card-container {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }
    </style>
</head>
<body>
    <button id="toggleSidebarButton"><i class="fas fa-arrow-left"></i></button>
    <div class="sidebar">
        <div><img src="MDC LOGO.png" style="width: 110px; height: 55px; margin-left: 25px"/></div>
        <ul>
            <?php if ($_SESSION['role'] === 'Main Admin') : ?>
            <li class="<?= ($current_page == 'pending_edits.php') ? 'active' : ''; ?>">
                <span class="sidebar-nav <?= ($current_page == 'pending_edits.php') ? 'highlight' : ''; ?>" data-href="pending_edits.php">
                    <i class="fas fa-clipboard-check"></i> For Approval
                </span>
            </li>
            <?php endif; ?>
            <li class="<?= ($current_page == 'pms_due_summary.php') ? 'active' : ''; ?>">
                <span class="sidebar-nav <?= ($current_page == 'pms_due_summary.php') ? 'highlight' : ''; ?>" data-href="pms_due_summary.php">
                    <i class="fa fa-joomla"></i> Summary
                </span>
            </li>
            <li class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <span class="sidebar-nav <?= ($current_page == 'dashboard.php') ? 'highlight' : ''; ?>" data-href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Maintenance
                </span>
            </li>
            <li class="<?= ($current_page == 'monitoring.php') ? 'active' : ''; ?>">
                <span class="sidebar-nav <?= ($current_page == 'monitoring.php') ? 'highlight' : ''; ?>" data-href="monitoring.php">
                    <i class="fas fa-chart-line"></i> Monitoring
                </span>
            </li>
            <li class="<?= ($current_page == 'geofence.php') ? 'active' : ''; ?>">
                <span class="sidebar-nav <?= ($current_page == 'geofence.php') ? 'highlight' : ''; ?>" data-href="geofence.php">
                    <i class="fas fa-map-marker-alt"></i> Geofence
                </span>
            </li>
            <li class="<?= ($current_page == 'information.php') ? 'active' : ''; ?>">
                <span class="sidebar-nav <?= ($current_page == 'information.php') ? 'highlight' : ''; ?>" data-href="information.php">
                    <i class="fas fa-car"></i> Vehicles
                </span>
            </li>
            <li class="<?= ($current_page == 'profile.php' || $current_page == 'preferences.php' || $current_page == 'notifications.php' || $current_page == 'backup.php') ? 'active' : ''; ?>">
                <span class="sidebar-nav" onclick="toggleSettings()">
                    <i class="fas fa-cogs"></i> Settings
                </span>
                <ul id="settings-menu" class="submenu <?= ($current_page == 'profile.php' || $current_page == 'preferences.php' || $current_page == 'notifications.php' || $current_page == 'backup.php') ? 'active' : ''; ?>">
                    <li class="<?= ($current_page == 'profile.php') ? 'active' : ''; ?>">
                        <span class="sidebar-nav <?= ($current_page == 'profile.php') ? 'highlight' : ''; ?>" data-href="profile.php">
                            <i class="fas fa-user"></i> Profile
                        </span>
                    </li>
                    <li class="<?= ($current_page == 'preferences.php') ? 'active' : ''; ?>">
                        <span class="sidebar-nav <?= ($current_page == 'preferences.php') ? 'highlight' : ''; ?>" data-href="preferences.php">
                            <i class="fas fa-paint-brush"></i> Preferences
                        </span>
                    </li>
                    <li class="<?= ($current_page == 'notifications.php') ? 'active' : ''; ?>">
                        <span class="sidebar-nav <?= ($current_page == 'notifications.php') ? 'highlight' : ''; ?>" data-href="notifications.php">
                            <i class="fas fa-bell"></i> Notifications
                        </span>
                    </li>
                    <li class="<?= ($current_page == 'backup.php') ? 'active' : ''; ?>">
                        <span class="sidebar-nav <?= ($current_page == 'backup.php') ? 'highlight' : ''; ?>" data-href="backup.php">
                            <i class="fas fa-database"></i> Backup
                        </span>
                    </li>
                </ul>
            </li>
            <li class="<?= ($current_page == 'index.php') ? 'active' : ''; ?>">
                <span class="sidebar-nav <?= ($current_page == 'index.php') ? 'highlight' : ''; ?>" data-href="index.php">
                    <i class="fas fa-home"></i> Home
                </span>
            </li>
            <li>
                <span class="sidebar-nav" data-href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </span>
            </li>
        </ul>
    </div>
    <div class="main-content">
        <h2>Summary</h2>
        <div class="fleet-stats">
            <div class="stat-box">
                <h3>Total Vehicles</h3>
                <p id="totalVehicles"><?php echo htmlspecialchars($totalVehicles); ?></p>
            </div>
            <div class="stat-box">
                <h3>Operational</h3>
                <p id="activeVehicles"><?php echo htmlspecialchars($activeVehicles); ?></p>
            </div>
            <div class="stat-box">
                <h3>Inactive</h3>
                <p id="inactiveVehicles"><?php echo htmlspecialchars($inactiveVehicles); ?></p>
            </div>
            <div class="stat-box">
                <h3>Breakdowns</h3>
                <p id="breakdownVehicles"><?php echo htmlspecialchars($breakdownVehicles); ?></p>
            </div>
        </div>
        <div class="table-sub-container">
            <h2>PMS Overdue and Nearing</h2>
            <div id="pmsTableBody" class="card-container">
                <?php if (empty($dueVehicles)): ?>
                    <p class="no-results">All vehicles are up to date with PMS schedules.</p>
                <?php else: ?>
                    <?php foreach ($dueVehicles as $vehicle): ?>
                        <div class="card <?php echo strtolower($vehicle['status']); ?>">
                            <div class="card-header">
                                <i class="fas fa-tools"></i>
                                <span><?php echo htmlspecialchars($vehicle['target_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-field">
                                <label>Type:</label>
                                <span><?php echo htmlspecialchars($vehicle['equipment_type'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-field">
                                <label>Status:</label>
                                <span><?php echo htmlspecialchars($vehicle['status']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-sub-container">
            <h2>Contract Monitoring (Overdue)</h2>
            <div id="contractTableBody" class="card-container">
                <?php if (empty($contractVehicles)): ?>
                    <p class="no-results">No overdue contracts detected.</p>
                <?php else: ?>
                    <?php foreach ($contractVehicles as $vehicle): ?>
                        <div class="card <?php echo $vehicle['is_overdue'] ? 'overdue' : ''; ?> <?php echo $vehicle['source'] === 'komtrax' ? 'komtrax-vehicle' : ''; ?>">
                            <div class="card-header">
                                <i class="fas fa-file-contract"></i>
                                <span><?php echo htmlspecialchars($vehicle['target_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-field">
                                <label>Assignment:</label>
                                <span><?php echo htmlspecialchars($vehicle['assignment'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-field">
                                <label>Days Lapsed:</label>
                                <span><?php echo htmlspecialchars($vehicle['days_lapses'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-sub-container">
            <h2>Maintenance Monitoring</h2>
            <div id="maintenanceTableBody" class="card-container">
                <?php if (empty($maintenanceVehicles)): ?>
                    <p class="no-results">No maintenance issues detected.</p>
                <?php else: ?>
                    <?php foreach ($maintenanceVehicles as $vehicle): ?>
                        <div class="card breakdown <?php echo $vehicle['source'] === 'komtrax' ? 'komtrax-vehicle' : ''; ?>">
                            <div class="card-header">
                                <i class="fas fa-wrench"></i>
                                <span><?php echo htmlspecialchars($vehicle['target_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-field">
                                <label>Type:</label>
                                <span><?php echo htmlspecialchars($vehicle['equipment_type'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-field">
                                <label>Status:</label>
                                <span><?php echo htmlspecialchars($vehicle['physical_status']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-sub-container">
            <h2>Geofence Status</h2>
            <div id="geofenceTableBody" class="card-container">
                <?php if (empty($geofenceVehicles)): ?>
                    <p class="no-results">No geofence status issues detected.</p>
                <?php else: ?>
                    <?php foreach ($geofenceVehicles as $vehicle): ?>
                        <div class="card <?php echo $vehicle['is_inside'] ? 'inside-geofence' : 'overdue'; ?> <?php echo $vehicle['source'] === 'komtrax' ? 'komtrax-vehicle' : ''; ?>">
                            <div class="card-header">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($vehicle['target_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-field">
                                <label>Assignment:</label>
                                <span><?php echo htmlspecialchars($vehicle['assignment'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-field">
                                <label>Status:</label>
                                <span><?php echo htmlspecialchars($vehicle['status'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        let isPageActive = true;

        function toggleSettings() {
            document.getElementById("settings-menu").classList.toggle("active");
        }

        document.addEventListener("DOMContentLoaded", () => {
            console.log("DOM loaded, rendering cards");

            // Sidebar navigation
            document.querySelectorAll('.sidebar-nav[data-href]').forEach(nav => {
                if (!nav.hasAttribute('onclick')) {
                    nav.addEventListener('click', () => window.location.href = nav.getAttribute('data-href'));
                }
            });

            // Sidebar toggle
            const toggleButton = document.getElementById("toggleSidebarButton");
            const sidebar = document.querySelector(".sidebar");
            const mainContent = document.querySelector(".main-content");
            if (toggleButton && sidebar && mainContent) {
                const isHidden = localStorage.getItem("sidebarHidden") === 'true';
                if (isHidden) {
                    sidebar.classList.add("hidden");
                    mainContent.classList.add("collapsed");
                    toggleButton.classList.add("hidden");
                    toggleButton.innerHTML = '<i class="fas fa-arrow-right"></i>';
                }
                toggleButton.addEventListener("click", () => {
                    sidebar.classList.toggle("hidden");
                    mainContent.classList.toggle("collapsed");
                    const isHidden = sidebar.classList.contains("hidden");
                    toggleButton.classList.toggle("hidden", isHidden);
                    toggleButton.innerHTML = isHidden ? '<i class="fas fa-arrow-right"></i>' : '<i class="fas fa-arrow-left"></i>';
                    localStorage.setItem("sidebarHidden", isHidden);
                });
            }

            // Red highlight counts
            function updateRedHighlightCounts(counts) {
                console.log("Red highlight counts:", counts);
                ['dashboard.php', 'monitoring.php', 'geofence.php', 'pending_edits.php'].forEach(href => {
                    const nav = document.querySelector(`.sidebar-nav[data-href="${href}"]`);
                    if (nav) {
                        const key = href.split('.')[0];
                        nav.classList.toggle('highlight', (counts[key] || 0) > 0);
                    }
                });
            }

            function fetchRedHighlightCounts() {
                if (!isPageActive) return;
                fetch('fetch_red_highlight_counts.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => updateRedHighlightCounts(data))
                    .catch(error => console.error('Fetch red highlight counts failed:', error));
            }

            const eventSource = new EventSource('stream_red_highlight_counts.php');
            eventSource.onmessage = event => {
                if (!isPageActive) return;
                try {
                    updateRedHighlightCounts(JSON.parse(event.data));
                } catch (e) {
                    console.error('SSE red highlight parse error:', event.data);
                }
            };
            eventSource.onerror = () => {
                console.error('SSE red highlight error, polling...');
                eventSource.close();
                setInterval(fetchRedHighlightCounts, 10000);
            };

            // Fleet stats and cards
            function updateFleetStats(data) {
                console.log("Fleet stats:", data);
                document.getElementById('totalVehicles').textContent = data.total || 0;
                document.getElementById('activeVehicles').textContent = data.active || 0;
                document.getElementById('inactiveVehicles').textContent = data.inactive || 0;
                document.getElementById('breakdownVehicles').textContent = data.breakdown || 0;
                updatePmsTable();
                updateContractTable();
                updateMaintenanceTable();
                updateGeofenceTable();
            }

            function updateTable(containerId, data, template, noResultsMsg) {
                const container = document.getElementById(containerId);
                const parent = container.closest('.table-sub-container');
                console.log(`${containerId} data:`, data);
                if (data.length) {
                    container.innerHTML = data.map(template).join('');
                    container.style.display = 'grid';
                    parent.querySelector('.no-results')?.remove();
                } else {
                    container.innerHTML = `<p class="no-results">${noResultsMsg}</p>`;
                    container.style.display = 'grid';
                }
            }

            function updatePmsTable() {
                fetch('fetch_pms_due.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            updateTable('pmsTableBody', data.dueVehicles, vehicle => `
                                <div class="card ${vehicle.status.toLowerCase()}">
                                    <div class="card-header">
                                        <i class="fas fa-tools"></i>
                                        <span>${vehicle.target_name || 'N/A'}</span>
                                    </div>
                                    <div class="card-field">
                                        <label>Type:</label>
                                        <span>${vehicle.equipment_type || 'N/A'}</span>
                                    </div>
                                    <div class="card-field">
                                        <label>Status:</label>
                                        <span>${vehicle.status}</span>
                                    </div>
                                </div>
                            `, 'All vehicles are up to date with PMS schedules.');
                        } else {
                            console.error('PMS data error:', data.error);
                        }
                    })
                    .catch(error => console.error('Fetch PMS failed:', error));
            }

            function updateContractTable() {
                fetch('fetch_contract_monitoring.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            updateTable('contractTableBody', data.contractVehicles, vehicle => `
                                <div class="card ${vehicle.is_overdue ? 'overdue' : ''} ${vehicle.source === 'komtrax' ? 'komtrax-vehicle' : ''}">
                                    <div class="card-header">
                                        <i class="fas fa-file-contract"></i>
                                        <span>${vehicle.target_name || 'N/A'}</span>
                                    </div>
                                    <div class="card-field">
                                        <label>Assignment:</label>
                                        <span>${vehicle.assignment || 'N/A'}</span>
                                    </div>
                                    <div class="card-field">
                                        <label>Days Lapsed:</label>
                                        <span>${vehicle.days_lapses || 'N/A'}</span>
                                    </div>
                                </div>
                            `, 'No overdue contracts detected.');
                        } else {
                            console.error('Contract data error:', data.error);
                        }
                    })
                    .catch(error => console.error('Fetch contract failed:', error));
            }

            function updateMaintenanceTable() {
                fetch('fetch_maintenance_monitoring.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            updateTable('maintenanceTableBody', data.maintenanceVehicles, vehicle => `
                                <div class="card breakdown ${vehicle.source === 'komtrax' ? 'komtrax-vehicle' : ''}">
                                    <div class="card-header">
                                        <i class="fas fa-wrench"></i>
                                        <span>${vehicle.target_name || 'N/A'}</span>
                                    </div>
                                    <div class="card-field">
                                        <label>Type:</label>
                                        <span>${vehicle.equipment_type || 'N/A'}</span>
                                    </div>
                                    <div class="card-field">
                                        <label>Status:</label>
                                        <span>${vehicle.physical_status || 'N/A'}</span>
                                    </div>
                                </div>
                            `, 'No maintenance issues detected.');
                        } else {
                            console.error('Maintenance data error:', data.error);
                        }
                    })
                    .catch(error => console.error('Fetch maintenance failed:', error));
            }

            function updateGeofenceTable() {
                fetch('fetch_geofence.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            updateTable('geofenceTableBody', data.geofenceVehicles, vehicle => `
                                <div class="card ${vehicle.is_inside ? 'inside-geofence' : 'overdue'} ${vehicle.source === 'komtrax' ? 'komtrax-vehicle' : ''}">
                                    <div class="card-header">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span>${vehicle.target_name || 'N/A'}</span>
                                    </div>
                                    <div class="card-field">
                                        <label>Assignment:</label>
                                        <span>${vehicle.assignment || 'N/A'}</span>
                                    </div>
                                    <div class="card-field">
                                        <label>Status:</label>
                                        <span>${vehicle.status || 'N/A'}</span>
                                    </div>
                                </div>
                            `, 'No geofence status issues detected.');
                        } else {
                            console.error('Geofence data error:', data.error);
                        }
                    })
                    .catch(error => console.error('Fetch geofence failed:', error));
            }

            // SSE for vehicle stats
            const vehicleStatsSource = new EventSource('stream_vehicle_stats.php');
            vehicleStatsSource.onmessage = event => {
                if (!isPageActive) return;
                try {
                    const data = JSON.parse(event.data);
                    if (data.success) updateFleetStats(data);
                    else console.error('SSE vehicle stats error:', data.error);
                } catch (e) {
                    console.error('SSE vehicle stats parse error:', event.data);
                }
            };
            vehicleStatsSource.onerror = () => {
                console.error('SSE vehicle stats error, polling...');
                vehicleStatsSource.close();
                setInterval(() => {
                    if (!isPageActive) return;
                    fetch('fetch_vehicle_stats.php')
                        .then(response => {
                            if (!response.ok) throw new Error(`HTTP ${response.status}`);
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) updateFleetStats(data);
                            else console.error('Fetch vehicle stats error:', data.error);
                        })
                        .catch(error => console.error('Fetch vehicle stats failed:', error));
                }, 10000);
            };

            // Page visibility
            document.addEventListener('visibilitychange', () => {
                isPageActive = !document.hidden;
            });

            // Initial updates
            console.log("Starting card updates");
            fetchRedHighlightCounts();
            updatePmsTable();
            updateContractTable();
            updateMaintenanceTable();
            updateGeofenceTable();
        });
    </script>
</body>
</html>