<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}
require 'db_connect.php';

// Set timezone to PST
date_default_timezone_set('Asia/Manila');
$current_date = date('c');

$current_page = basename($_SERVER['PHP_SELF']);

// Get unique equipment types for filtering
$equipmentTypes = [];

// Fetch distinct equipment types from devices
$equipmentQueryDevices = "SELECT DISTINCT equipment_type FROM devices ORDER BY equipment_type";
$equipmentResultDevices = $conn->query($equipmentQueryDevices);

while ($row = $equipmentResultDevices->fetch_assoc()) {
    $equipmentTypes[] = $row['equipment_type'];
}

// Fetch distinct equipment types from komtrax
$equipmentQueryKomtrax = "SELECT DISTINCT equipment_type FROM komtrax ORDER BY equipment_type";
$equipmentResultKomtrax = $conn->query($equipmentQueryKomtrax);

while ($row = $equipmentResultKomtrax->fetch_assoc()) {
    if (!in_array($row['equipment_type'], $equipmentTypes)) {
        $equipmentTypes[] = $row['equipment_type'];
    }
}

// Get vehicle count for statistics (combined devices and komtrax)
$totalVehiclesQuery = "
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

$result = $conn->query($totalVehiclesQuery);

if (!$result) {
    die("Database query failed: " . $conn->error);
}

$data = $result->fetch_assoc();
$totalVehicles = $data['total'];
$activeVehicles = $data['active'];
$inactiveVehicles = $data['inactive'];
$breakdownVehicles = $data['breakdown'];

$pendingCount = 0;
if ($_SESSION['role'] === 'Main Admin') {
    $pendingQuery = "SELECT COUNT(*) as count FROM pending_edits WHERE status = 'pending'";
    $pendingResult = $conn->query($pendingQuery);
    if ($pendingResult) {
        $pendingCount = $pendingResult->fetch_assoc()['count'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fleet Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
            background-color: #f0f2f5;
        }
        .fleet-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            flex: 1;
            background: linear-gradient(135deg, #ffffff, #f9f9f9);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .stat-box:hover {
            transform: translateY(-5px);
        }
        .stat-box h3 {
            margin-bottom: 5px;
            color: #333;
            font-size: 1.1rem;
        }
        .stat-box p {
            font-size: 1.5rem;
            font-weight: bold;
            color: #007bff;
        }
        .table-container {
            overflow-x: auto;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            background: #ff4757;
            color: white;
            padding: 5px 10px;
            border-radius: 50%;
            font-size: 14px;
            font-weight: bold;
        }
        .table-sub-container {
            margin-bottom: 30px;
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .table-sub-container h2 {
            font-size: 1.3rem;
            color: #004080;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .table-wrapper {
            overflow-x: auto;
            margin-top: 15px;
        }
        button.view-btn, button.edit-btn {
            padding: 5px 10px;
            margin: 0 5px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
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
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            margin: 0 5px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s;
        }
        .pagination-button:hover {
            background-color: #0056b3;
            transform: scale(1.05);
        }
        .pagination-button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        .pagination-info {
            margin: 0 10px;
            font-size: 16px;
            font-weight: bold;
        }
        .new-entry {
            animation: highlight 2s ease;
            background-color: rgba(0, 200, 0, 0.1);
            border-left: 4px solid #28a745;
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
            animation: blink-red 1s linear infinite;
        }
        .breakdown {
            animation: blink-yellow 1s linear infinite;
        }
        .newly-updated {
            background-color: #ccffcc;
        }
        .komtrax-vehicle {
            background-color: #e6f3ff;
        }
        .null-days-lapses {
            background-color: rgba(255, 0, 0, 0.2);
        }
        @keyframes blink-red {
            0% { background-color: rgba(255,0,0,0.1); }
            50% { background-color: rgba(255,0,0,0.4); }
            100% { background-color: rgba(255,0,0,0.1); }
        }
        @keyframes blink-yellow {
            0% { background-color: rgba(255,255,0,0.1); }
            50% { background-color: rgba(255,255,0,0.4); }
            100% { background-color: rgba(255,255,0,0.1); }
        }
        #notificationDropdown {
            display: none;
            position: absolute;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            width: 300px;
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
        }
        #notificationDropdown a {
            display: block;
            padding: 10px;
            text-decoration: none;
            color: #333;
            transition: background-color 0.3s;
        }
        #notificationDropdown a.unread {
            font-weight: bold;
            border-left: 4px solid #ff4757;
            background-color: #fff5f5;
        }
        #notificationDropdown a.read {
            color: #666;
        }
        #notificationDropdown a:hover {
            background-color: #f0f0f0;
        }
        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 20px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            transition: transform 0.3s ease;
            overflow: hidden;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.2);
        }
        .sidebar.hidden {
            transform: translateX(-100%);
        }
        .sidebar-nav {
            color: #e0e0e0;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            text-decoration: none;
            cursor: pointer;
            padding: 10px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .sidebar-nav:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(5px);
        }
        .sidebar-nav.highlight {
            border-left: 4px solid #ff4757;
            background: rgba(255, 71, 87, 0.1);
            color: #fff;
        }
        .sidebar ul {
            margin-top: 20px;
        }
        .sidebar ul li {
            margin: 8px 0;
            border-radius: 8px;
        }
        .sidebar ul li.active {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }
        .sidebar ul li i {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: #2a2a2a;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: #007bff;
            border-radius: 4px;
        }
        .main-content {
            flex-grow: 1;
            margin-left: 250px;
            padding: 30px;
            width: calc(100% - 250px);
            transition: margin-left 0.3s ease, width 0.3s ease;
            background: #f0f2f5;
        }
        .main-content.collapsed {
            margin-left: 0;
            width: 100%;
        }
        h2 {
            margin-bottom: 30px;
            color: #004080;
            text-align: center;
            font-weight: 600;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal.show {
            display: flex;
            opacity: 1;
        }
        .modal-content {
            background: linear-gradient(135deg, #ffffff, #f8f9fa);
            padding: 25px;
            border-radius: 15px;
            width: 650px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            position: relative;
            transform: translateY(-100%);    
            opacity: 0;
            transition: transform 0.5s ease, opacity 0.5s ease;
            border: 1px solid #e0e0e0;
        }
        .modal.show .modal-content {
            transform: translateY(0);
            opacity: 1;
        }
        .modal-content h3 {
            margin-bottom: 20px;
            color: #004080;
            font-size: 1.5rem;
            font-weight: 600;
            text-align: center;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .modal-content .info-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .modal-content .info-item {
            background: #fff;
            padding: 15px;
            border-radius: 10px;
            border: 1px solid #eee;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .modal-content .info-item:hover {
            transform: translateY(-3px);
        }
        .modal-content .info-item label {
            font-weight: 600;
            color: #333;
            display: block;
            margin-bottom: 8px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .modal-content .info-item span {
            display: block;
            word-wrap: break-word;
            white-space: normal;
            color: #555;
            font-size: 0.95rem;
            line-height: 1.4;
        }
        .modal-content .highlight-red {
            background-color: #ffebee;
            border-color: #ef9a9a;
            color: #c62828;
            font-weight: 600;
        }
        .close-btn {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            background: #fff;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .close-btn:hover {
            color: #000;
            background: #f0f0f0;
            transform: rotate(90deg);
        }
        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 25px;
        }
        .edit-button, .delete-button, .save-button, .cancel-button {
            background: linear-gradient(45deg, #2196F3, #1976D2);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
            text-decoration: none;
            text-align: center;
            display: inline-block; /* Ensure buttons stay inline */
        }
        .edit-button:hover, .save-button:hover, .cancel-button:hover {
            background: linear-gradient(45deg, #1976D2, #1565C0);
            transform: scale(1.05);
            box-shadow: 0 6px 15px rgba(33, 150, 243, 0.6);
        }
        .delete-button {
            background: linear-gradient(45deg, #dc3545, #c82333);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
        }
        .delete-button:hover {
            background: linear-gradient(45deg, #c82333, #b21f2d);
            transform: scale(1.05);
            box-shadow: 0 6px 15px rgba(220, 53, 69, 0.6);
        }
        .save-button {
            background: linear-gradient(45deg, #28a745, #218838);
        }
        .save-button:hover {
            background: linear-gradient(45deg, #218838, #1e7e34);
        }
        .cancel-button {
            background: linear-gradient(45deg, #6c757d, #5a6268);
        }
        .cancel-button:hover {
            background: linear-gradient(45deg, #5a6268, #4e555b);
        }
        .map-icon {
            color: #ff9800;
            font-size: 18px;
            cursor: pointer;
            transition: color 0.3s ease, transform 0.2s ease;
            margin: 0 auto;
            display: inline-block;
            padding: 4px;
        }
        .map-icon:hover {
            color: #e68a00;
            transform: scale(1.2);
        }
        .map-icon.disabled {
            color: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        #mapModal .leaflet-popup-content {
            font-family: Arial, sans-serif;
            font-size: 14px;
        }
        #mapModal .leaflet-popup-content b {
            color: #333;
        }
        .filter-search-section {
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .filter-search-section .filter-section {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .filter-search-section label {
            font-size: 1rem;
            color: #333;
            font-weight: 500;
        }
        .filter-search-section select,
        .filter-search-section input[type="text"] {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 0.95rem;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .filter-search-section select:focus,
        .filter-search-section input[type="text"]:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
        }
        .filter-search-section input[type="text"] {
            flex: 1;
            min-width: 200px;
        }
        .no-results {
            text-align: center;
            padding: 20px;
            color: #c62828;
            font-weight: bold;
        }
        .form-container {
            max-height: 60vh;
            overflow-y: auto;
            padding: 10px 15px;
            margin-bottom: 10px;
            border-radius: 5px;
            background: #fff;
            border: 1px solid #eee;
        }
        .form-group {
            width: calc(50% - 10px);
            display: flex;
            flex-direction: column;
            text-align: left;
            margin-bottom: 10px;
        }
        .form-group label {
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            transition: 0.3s;
            box-sizing: border-box;
            background-color: #f9f9f9;
        }
        .form-group input[readonly] {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        .form-group input:hover:not([readonly]), .form-group input:focus:not([readonly]),
        .form-group select:hover, .form-group select:focus {
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0,123,255,0.5);
            outline: none;
            background-color: #fff;
        }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        @media (max-width: 700px) {
            .form-group {
                width: 100%;
            }
        }
        .submenu {
            display: none;
            list-style: none;
            padding-left: 15px;
        }
        .submenu.active {
            display: block;
        }
        #toggleSidebarButton {
            position: fixed;
            top: 50%;
            left: 250px;
            transform: translateY(-50%);
            background: #007bff;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 0 5px 5px 0;
            cursor: pointer;
            z-index: 1000;
            transition: left 0.3s ease, background 0.3s ease;
            box-shadow: 2px 0 5px rgba(0,0,0,0.2);
        }
        #toggleSidebarButton.hidden {
            left: 0;
        }
        #toggleSidebarButton:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>

<!-- Toggle Button -->
<button id="toggleSidebarButton" class="sidebar-visible">
    <i class="fas fa-arrow-left"></i>
</button>

<!-- Notification Bell Icon (Main Admin Only) -->
<?php if ($_SESSION['role'] === 'Main Admin') : ?>
<div id="notificationWrapper" style="position: fixed; top: 20px; right: 30px; z-index: 1000;">
    <div id="notificationIcon" style="cursor: pointer; position: relative;">
        🔔
        <span id="notificationCount" style="
            position: absolute;
            top: -10px;
            right: -10px;
            background-color: red;
            color: white;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 50%;
            display: none;
        ">0</span>
    </div>
    <div id="notificationDropdown" style="
        display: none;
        position: absolute;
        top: 40px;
        right: 0;
        background: white;
        border: 1px solid #ccc;
        border-radius: 5px;
        width: 300px;
        max-height: 300px;
        overflow-y: auto;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        padding: 10px;
        font-size: 14px;
        color: #333;
        z-index: 1001;
        background-color: rgba(255, 255, 255, 0.95);
    "></div>
</div>
<?php endif; ?>

<!-- Sidebar -->
<div class="sidebar">
    <div>
    <img href="https://google.com" src="MDC LOGO.png" style="width: 130px;height: 70px; margin-left:40px"/>
    </div>
    <ul>
    <?php if ($_SESSION['role'] === 'Main Admin') : ?>
        <li class="<?= ($current_page == 'pending_edits.php') ? 'active' : ''; ?>">
            <span class="sidebar-nav" data-href="pending_edits.php" style="padding-right: 35px; font-size: 12px;">
                <i class="fas fa-clipboard-check"></i>
                <span>For Approval</span>
            </span>
        </li>
        <?php endif; ?>
        <li class="<?= ($current_page == 'pms_due_summary.php') ? 'active' : ''; ?>">
            <span class="sidebar-nav <?= ($current_page == 'pms_due_summary.php') ? 'highlight' : ''; ?>" data-href="pms_due_summary.php">
                <i class="fa fa-pie-chart" aria-hidden="true"></i>
                <span>Summary</span>
            </span>
        </li>
        <li class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <span id="maintenanceNav" class="sidebar-nav" data-href="dashboard.php" style="padding-right: 35px;">
                <i class="fas fa-tachometer-alt"></i>
                <span>Maintenance</span>
            </span>
        </li>
        <li class="<?= ($current_page == 'monitoring.php') ? 'active' : ''; ?>">
            <span id="monitoringNav" class="sidebar-nav" data-href="monitoring.php" style="padding-right: 35px;">
                <i class="fas fa-chart-line"></i>
                <span>Monitoring</span>
            </span>
        </li>
        <li class="<?= ($current_page == 'geofence.php') ? 'active' : ''; ?>">
            <span id="geofenceNav" class="sidebar-nav" data-href="geofence.php" style="padding-right: 35px;">
                <i class="fas fa-map-marker-alt"></i>
                <span>Geofence</span>
            </span>
        </li>
        <li class="<?= ($current_page == 'information.php') ? 'active' : ''; ?>">
            <span class="sidebar-nav" data-href="information.php">
                <i class="fas fa-car"></i> Vehicles
            </span>
        </li>
        <li class="<?= ($current_page == 'profile.php' || $current_page == 'preferences.php' || $current_page == 'notifications.php' || $current_page == 'backup.php') ? 'active' : ''; ?>">
            <span class="sidebar-nav" onclick="toggleSettings()">
                <i class="fas fa-cogs"></i> Settings
            </span>
            <ul id="settings-menu" class="submenu">
                <li class="<?= ($current_page == 'profile.php') ? 'active' : ''; ?>">
                    <span class="sidebar-nav" data-href="profile.php" style="font-size:12px;"><i class="fas fa-user"></i> Profile</span>
                </li>
                <li class="<?= ($current_page == 'preferences.php') ? 'active' : ''; ?>">
                    <span class="sidebar-nav" data-href="preferences.php" style="font-size:12px;"><i class="fas fa-paint-brush"></i> Preferences</span>
                </li>
                <li class="<?= ($current_page == 'notifications.php') ? 'active' : ''; ?>">
                    <span class="sidebar-nav" data-href="notifications.php" style="font-size:12px;"><i class="fas fa-bell"></i> Notifications</span>
                </li>
                <li class="<?= ($current_page == 'backup.php') ? 'active' : ''; ?>">
                    <span class="sidebar-nav" data-href="backup.php" style="font-size:12px;"><i class="fas fa-database"></i> Backup</span>
                </li>
            </ul>
        </li>
        <li class="<?= ($current_page == 'index.php') ? 'active' : ''; ?>">
            <span class="sidebar-nav" data-href="index.php">
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

<!-- Modal Popup for Vehicle Information and Editing -->
<div id="vehicleModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">×</span>
        <h3 id="modalTitle">Vehicle Information</h3>
        <div id="messageContainer"></div>
        <div id="vehicleInfo"></div>
        <div id="editForm" style="display: none;"></div>
        <div class="modal-buttons" id="modalButtons" style="display: flex; gap: 15px; justify-content: center;">
        <?php 
        // Debug: Log the current role to error log
        error_log("Current user role: " . ($_SESSION['role'] ?? 'Not set'));
        if ($_SESSION['role'] !== 'User') : ?>
            <button id="editButton" class="edit-button">Edit</button>
        <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSettings() {
    document.getElementById("settings-menu").classList.toggle("active");
}

function showModal() {
    const modal = document.getElementById('vehicleModal');
    modal.classList.add('show');
}

function closeModal() {
    const modal = document.getElementById('vehicleModal');
    modal.classList.remove('show');
    document.getElementById('vehicleInfo').innerHTML = '';
    document.getElementById('editForm').innerHTML = '';
    document.getElementById('editForm').style.display = 'none';
    document.getElementById('modalButtons').innerHTML = '<?php if ($_SESSION['role'] !== 'User') : ?><button id="editButton" class="edit-button">Edit</button><?php endif; ?>';
    document.getElementById('modalTitle').innerText = 'Vehicle Information';
    document.getElementById('messageContainer').innerHTML = '';
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('vehicleModal');
    if (event.target === modal) {
        closeModal();
    }
}

document.addEventListener("DOMContentLoaded", function () {
    // Assignment mapping for user-friendly display
const assignmentMap = {
    'assignment_amlan': 'Amlan',
    'assignment_balingueo': 'Balingueo SS',
    'assignment_banilad': 'Banilad SS',
    'assignment_barotac': 'Barotac Viejo SS',
    'assignment_bayombong': 'Bayombong SS',
    'assignment_binan': 'Binan SS',
    'assignment_bolo': 'Bolo',
    'assignment_botolan': 'Botolan SS',
    'assignment_cadiz': 'Cadiz SS',
    'assignment_calacass': 'Calaca SS',
    'assignment_calacatl': 'Calaca TL',
    'assignment_calatrava': 'Calatrava SS',
    'assignment_castillejos': 'Castillejos TL',
    'assignment_dasmarinas': 'Dasmarinas SS',
    'assignment_dumanjug': 'Dumanjug SS',
    'assignment_ebmagalona': 'EB Magalona SS',
    'assignment_headoffice': 'Head Office',
    'assignment_hermosatl': 'Hermosa TL',
    'assignment_hermosa': 'Hermosa SS',
    'assignment_ilijan': 'Ilijan SS',
    'assignment_isabel': 'Isabel SS',
    'assignment_maasin': 'Maasin SS',
    'assignment_muntinlupa': 'Muntinlupa SS',
    'assignment_pantabangan': 'Pantabangan SS',
    'assignment_paoay': 'Paoay TL',
    'assignment_pinamucan': 'Pinamucan SS',
    'assignment_quirino': 'Quirino',
    'assignment_sanjose': 'San Jose SS',
    'assignment_tabango': 'Tabango SS',
    'assignment_tayabas': 'Tayabas SS',
    'assignment_taytay': 'Taytay SS',
    'assignment_terrasolar': 'Terra Solar',
    'assignment_tuguegarao': 'Tuguegarao SS',
    'assignment_tuy': 'Tuy SS'
};
    let currentPage1 = 1;
    let currentPage2 = 1;
    const itemsPerPage = 10;
    let filterType = "";
    let searchQuery = "";
    let refreshInterval = 5000;
    let lastCheckTime = Math.floor(Date.now() / 1000);
    let searchTimeout;
    let isPageActive = true;

    // Define readonly fields
    const fieldsReadonlySystemBase = [
        'id', 'last_updated', 'position_time', 'address', 'latitude', 'longitude',
        'speed', 'direction', 'total_mileage', 'status', 'speed_limit', 'days_no_gps'
    ];
    const komtraxAdditionalReadonly = [
        'cut_address', 'last_assignment', 'last_days_contract', 'last_date_transferred',
        'last_date_ended', 'last_days_elapsed'
    ];

    // Define display fields for devices and komtrax
    const devicesDisplayFields = [
                    'id', 'target_name', 'equipment_type', 'physical_status', 'assignment',
                    'date_transferred', 'days_contract', 'days_elapsed', 'days_lapses', 'date_ended',
                    'last_updated', 'position_time', 'address', 'latitude', 'longitude', 'speed',
                    'direction', 'total_mileage', 'status', 'type', 'speed_limit', 'days_no_gps',
                    'last_pms_date', 'next_pms_date', 'pms_interval', 'tag', 'specs', 'cut_address',
                    'last_assignment', 'last_days_contract', 'last_date_transferred', 'last_date_ended',
                    'last_days_elapsed', 'remarks', 'source_table', 'requested_by'
    ];
    const komtraxDisplayFields = [
        ...devicesDisplayFields,
        'cut_address', 'last_assignment', 'last_days_contract', 'last_date_transferred',
        'last_date_ended', 'last_days_elapsed', 'requested_by'
    ];
    // Handle sidebar navigation clicks
    document.querySelectorAll('.sidebar-nav[data-href]').forEach(nav => {
        if (!nav.hasAttribute('onclick')) {
            nav.addEventListener('click', function () {
                const href = this.getAttribute('data-href');
                if (href) {
                    window.location.href = href;
                }
            });
        }
    });

// Add Vehicle Button Handler
const addVehicleButton = document.getElementById('addVehicleButton');
    if (addVehicleButton) {
        addVehicleButton.addEventListener('click', () => {
            openAddVehicleModal();
        });
    }
    // Assume userRole is fetched from session (e.g., via PHP echo or AJAX)
    const userRole = '<?php echo htmlspecialchars($_SESSION['role'] ?? 'User'); ?>';
    function openAddVehicleModal() {
    const vehicleInfoDiv = document.getElementById('vehicleInfo');
    const editForm = document.getElementById('editForm');
    const modalTitle = document.getElementById('modalTitle');
    const modalButtons = document.getElementById('modalButtons');
    const messageContainer = document.getElementById('messageContainer');
    
    // Check user role
    const userRole = '<?php echo $_SESSION["role"]; ?>';
    if (userRole === 'User') {
        messageContainer.innerHTML = '<div class="message error">You do not have permission to add vehicles.</div>';
        showModal();
        return;
    }

    modalTitle.innerText = 'Add New Vehicle';
    vehicleInfoDiv.innerHTML = '';
    messageContainer.innerHTML = '';

    const displayFields = [
        'id', 'target_name', 'equipment_type', 'physical_status', 'assignment',
        'date_transferred', 'days_contract', 'days_elapsed', 'days_lapses', 'date_ended',
        'last_updated', 'position_time', 'address', 'latitude', 'longitude', 'speed',
        'direction', 'total_mileage', 'status', 'type', 'speed_limit', 'days_no_gps',
        'last_pms_date', 'next_pms_date', 'pms_interval', 'tag', 'specs', 'cut_address',
        'last_assignment', 'last_days_contract', 'last_date_transferred', 'last_date_ended',
        'last_days_elapsed', 'requested_by'
    ];

    const fieldsReadonlySystem = [
        'id', 'days_elapsed', 'days_lapses', 'last_updated', 'position_time',
        'address', 'latitude', 'longitude', 'speed', 'direction', 'total_mileage',
        'status', 'speed_limit', 'days_no_gps', 'last_assignment',
        'last_days_contract', 'last_date_transferred', 'last_date_ended', 'cut_address'
    ];
    const fieldsReadonlyForm = [...fieldsReadonlySystem, 'next_pms_date'];

    let formHTML = '<form id="addVehicleForm" class="form-container"><div style="display: flex; flex-wrap: wrap; gap: 15px; justify-content: space-between;">';
    displayFields.forEach(field => {
        const isReadonly = fieldsReadonlyForm.includes(field);
        let readonlyAttr = isReadonly ? ' readonly' : '';
        let inputValue = '';
        let inputType = 'text';
        let inputClass = '';
        let placeholder = '';
        let options = '';
        let requiredAttr = '';

        if (['target_name', 'equipment_type', 'physical_status', 'requested_by'].includes(field)) {
            requiredAttr = ' required';
        }

        if (field === 'id') {
            inputValue = 'Auto-Generated';
            readonlyAttr = ' readonly';
            placeholder = 'System Generated';
        } else if (field === 'physical_status') {
            inputType = 'select';
            options = `
                <option value="">Select Status...</option>
                <option value="Operational">Operational</option>
                <option value="Inactive">Inactive</option>
                <option value="Breakdown">Breakdown</option>
            `;
        } else if (field === 'type') {
            inputType = 'select';
            requiredAttr = ' required';
            options = `
                <option value="">Select Type...</option>
                <option value="Vehicle">Vehicle</option>
                <option value="Equipment">Equipment</option>
            `;
        } else if (['date_transferred', 'date_ended', 'last_date_transferred', 'last_date_ended'].includes(field)) {
            inputType = 'text';
            inputClass = 'date-field';
            placeholder = 'YYYY-MM-DD HH:MM:SS';
            inputValue = new Date().toISOString().replace('T', ' ').substring(0, 19);
        } else if (['last_pms_date', 'next_pms_date'].includes(field)) {
            inputType = 'text';
            inputClass = 'pms-date-field';
            placeholder = 'MM-DD-YYYY';
            if (field === 'last_pms_date') {
                inputValue = new Date().toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
            }
        } else if (['days_contract', 'days_no_gps', 'pms_interval', 'last_days_contract', 'last_days_elapsed'].includes(field)) {
            inputType = 'number';
            inputValue = '0';
            placeholder = 'Days';
        } else if (['address', 'latitude', 'longitude'].includes(field)) {
            placeholder = 'System Generated';
        } else if (field === 'requested_by') {
            placeholder = 'Enter requester name';
        }

        if (inputType === 'select') {
            formHTML += `
                <div class="form-group">
                    <label for="${field}">${field.toUpperCase().replace(/_/g, ' ')}:</label>
                    <select name="${field}" id="${field}" ${isReadonly ? 'disabled' : ''}${requiredAttr}>${options}</select>
                </div>
            `;
        } else {
            formHTML += `
                <div class="form-group">
                    <label for="${field}">${field.toUpperCase().replace(/_/g, ' ')}:</label>
                    <input type="${inputType}" name="${field}" id="${field}" value="${inputValue}" placeholder="${placeholder}" class="${inputClass}" ${readonlyAttr}${requiredAttr} ${inputType === 'number' ? 'min="0"' : ''}>
                </div>
            `;
        }
    });
    formHTML += `<input type="hidden" name="source_table" value="devices">`;
    formHTML += `</div></form>`;
    editForm.innerHTML = formHTML;
    editForm.style.display = 'block';

    // Initialize Flatpickr
    flatpickr("#vehicleModal .date-field", {
        enableTime: true,
        dateFormat: "Y-m-d H:i:s",
        time_24hr: true,
        allowInput: true,
        defaultDate: new Date()
    });
    const pmsDatePickers = flatpickr("#vehicleModal .pms-date-field", {
        enableTime: false,
        dateFormat: "m-d-Y",
        allowInput: true,
        onChange: function(selectedDates, dateStr, instance) {
            if (instance.element.id === 'last_pms_date') {
                calculateNextPmsDate();
            }
        }
    });

    // PMS Date Calculation
    const lastPmsDateInput = document.getElementById("last_pms_date");
    const pmsIntervalInput = document.getElementById("pms_interval");
    const nextPmsDateInput = document.getElementById("next_pms_date");
    function calculateNextPmsDate() {
        if (!lastPmsDateInput || !pmsIntervalInput || !nextPmsDateInput) return;
        const lastPmsStr = lastPmsDateInput.value;
        const intervalStr = pmsIntervalInput.value;
        const intervalDays = parseInt(intervalStr, 10);
        if (isNaN(intervalDays) || intervalDays < 0) {
            nextPmsDateInput.value = "";
            messageContainer.innerHTML = '<div class="message error">Invalid PMS interval. Please enter a non-negative number.</div>';
            return;
        }
        try {
            const lastPmsDate = flatpickr.parseDate(lastPmsStr, "m-d-Y");
            if (lastPmsDate && !isNaN(lastPmsDate)) {
                const nextPmsDate = new Date(lastPmsDate.getTime());
                nextPmsDate.setDate(nextPmsDate.getDate() + intervalDays);
                nextPmsDateInput.value = flatpickr.formatDate(nextPmsDate, "m-d-Y");
            } else {
                nextPmsDateInput.value = "";
                messageContainer.innerHTML = '<div class="message error">Invalid Last PMS Date format. Please use MM-DD-YYYY.</div>';
            }
        } catch (e) {
            nextPmsDateInput.value = "";
            messageContainer.innerHTML = '<div class="message error">Error calculating Next PMS Date: ' + e.message + '</div>';
        }
    }
    if (pmsIntervalInput) pmsIntervalInput.addEventListener("input", calculateNextPmsDate);
    calculateNextPmsDate();

    // Contract and Lapse Calculation
    const dateTransferredInput = document.getElementById("date_transferred");
    const dateEndedInput = document.getElementById("date_ended");
    const daysContractInput = document.getElementById("days_contract");
    const daysElapsedInput = document.getElementById("days_elapsed");
    const daysLapsesInput = document.getElementById("days_lapses");
    function calculateContractAndLapses() {
        if (!dateTransferredInput || !dateEndedInput || !daysContractInput || !daysElapsedInput || !daysLapsesInput) return;
        const now = new Date();
        let contractDays = 0, elapsedDays = 0, lapsedDays = 0;
        const startStr = dateTransferredInput.value;
        const endStr = dateEndedInput.value;
        try {
            const start = flatpickr.parseDate(startStr, "Y-m-d H:i:s");
            const end = flatpickr.parseDate(endStr, "Y-m-d H:i:s");
            if (start && end && end >= start) {
                contractDays = Math.floor((end - start) / (1000 * 60 * 60 * 24));
            } else {
                messageContainer.innerHTML = '<div class="message error">Invalid date range: Date Transferred must be before Date Ended.</div>';
            }
        } catch (e) {
            messageContainer.innerHTML = '<div class="message error">Error parsing dates: ' + e.message + '</div>';
        }
        daysContractInput.value = contractDays >= 0 ? contractDays : 0;
        try {
            const start = flatpickr.parseDate(startStr, "Y-m-d H:i:s");
            if (start) elapsedDays = Math.floor((now - start) / (1000 * 60 * 60 * 24));
        } catch (e) {}
        daysElapsedInput.value = elapsedDays >= 0 ? elapsedDays : 0;
        try {
            const end = flatpickr.parseDate(endStr, "Y-m-d H:i:s");
            if (end && now > end) lapsedDays = Math.floor((now - end) / (1000 * 60 * 60 * 24));
        } catch (e) {}
        daysLapsesInput.value = lapsedDays >= 0 ? lapsedDays : 0;
    }
    if (dateTransferredInput) dateTransferredInput.addEventListener("change", calculateContractAndLapses);
    if (dateEndedInput) dateEndedInput.addEventListener("change", calculateContractAndLapses);
    calculateContractAndLapses();

    modalButtons.innerHTML = `
        <button class="save-button" id="saveAddButton">Save New Vehicle</button>
        <button class="cancel-button" id="cancelAddButton">Cancel</button>
    `;

    const saveAddButton = document.getElementById('saveAddButton');
    const cancelAddButton = document.getElementById('cancelAddButton');
    if (saveAddButton) {
    saveAddButton.addEventListener('click', () => {
        const form = document.getElementById('addVehicleForm');
        // Client-side validation
        const targetName = form.querySelector('#target_name').value.trim();
        const equipmentType = form.querySelector('#equipment_type').value.trim();
        const physicalStatus = form.querySelector('#physical_status').value;
        const type = form.querySelector('#type').value;
        if (!targetName || !equipmentType || !physicalStatus || !type) {
            messageContainer.innerHTML = '<div class="message error">Please fill in all required fields: Target Name, Equipment Type, Physical Status, Type.</div>';
            return;
        }

        const formData = new FormData(form);
        formData.append('action', 'add');

        fetch('submit_vehicle.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`Server error: ${response.status} ${response.statusText}`);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error(`Invalid response from server: ${text}`);
                }
            });
        })
        .then(result => {
            if (result.success) {
                messageContainer.innerHTML = '<div class="message success">' + result.message + '</div>';
                setTimeout(() => {
                    closeModal();
                    location.reload();
                }, 2000);
            } else {
                messageContainer.innerHTML = '<div class="message error">' + (result.message || 'Failed to submit vehicle addition.') + '</div>';
            }
        })
        .catch(error => {
            messageContainer.innerHTML = '<div class="message error">Error submitting vehicle addition: ' + error.message + '</div>';
        });
    });
}
    if (cancelAddButton) {
        cancelAddButton.addEventListener('click', closeModal);
    }

    showModal();
}

    // Sidebar Toggle
    const toggleButton = document.getElementById("toggleSidebarButton");
    const sidebar = document.querySelector(".sidebar");
    const mainContent = document.querySelector(".main-content");

    if (toggleButton && sidebar && mainContent) {
        if (localStorage.getItem("sidebarHidden") === 'true') {
            sidebar.classList.add("hidden");
            mainContent.classList.add("collapsed");
            toggleButton.classList.add("hidden");
            toggleButton.innerHTML = '<i class="fas fa-arrow-right"></i>';
        } else {
            toggleButton.classList.remove("hidden");
            toggleButton.innerHTML = '<i class="fas fa-arrow-left"></i>';
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

    // Notification Handling (Main Admin Only)
    const bellIcon = document.getElementById('notificationIcon');
    const dropdown = document.getElementById('notificationDropdown');
    const countSpan = document.getElementById('notificationCount');

    if (bellIcon && dropdown && countSpan) {
        function fetchNotifications() {
            if (!isPageActive) return;
            fetch('fetch_notifications.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Invalid JSON response from fetch_notifications:', text);
                            throw new Error(`JSON parse error: ${e.message}`);
                        }
                    });
                })
                .then(data => {
                    dropdown.innerHTML = '';
                    let unreadCount = 0;

                    if (Array.isArray(data)) {
                        data.forEach(notification => {
                            const isUnread = notification.read_status === 0;
                            if (isUnread) unreadCount++;
                            dropdown.innerHTML += `
                                <a href="information.php?target_name=${encodeURIComponent(notification.target_name)}" 
                                   class="${isUnread ? 'unread' : 'read'}">
                                    ${notification.message || 'No message'} - ${notification.target_name || 'N/A'} (${notification.equipment_type || 'N/A'})
                                </a>`;
                        });
                    }

                    countSpan.textContent = unreadCount;
                    countSpan.style.display = unreadCount > 0 ? 'inline' : 'none';
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }

        bellIcon.addEventListener('click', function (event) {
            event.stopPropagation();
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            fetch('mark_notification_viewed.php', { method: 'POST' })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Invalid JSON response from mark_notification_viewed:', text);
                            throw new Error(`JSON parse error: ${e.message}`);
                        }
                    });
                })
                .then(result => {
                    if (result.status === 'success') {
                        fetchNotifications();
                    }
                })
                .catch(error => console.error('Error marking notifications as read:', error));
        });

        document.addEventListener('click', function (event) {
            if (!bellIcon.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        });

        setInterval(fetchNotifications, 10000);
        fetchNotifications();
    }

    // Function to update sidebar highlights based on counts
    function updateRedHighlightCounts(counts) {
        if (counts && typeof counts.pending_edits === 'number' && !counts.error) {
            lastValidCounts = { ...counts };
        } else {
            console.warn('Invalid or error counts received, using last valid counts:', counts);
            counts = { ...lastValidCounts };
        }
        const maintenanceNav = document.querySelector('.sidebar-nav[data-href="dashboard.php"]');
        const monitoringNav = document.querySelector('.sidebar-nav[data-href="monitoring.php"]');
        const geofenceNav = document.querySelector('.sidebar-nav[data-href="geofence.php"]');
        const pendingEditsNav = document.querySelector('.sidebar-nav[data-href="pending_edits.php"]');

        if (maintenanceNav) {
            maintenanceNav.classList.toggle('highlight', (counts.maintenance || 0) > 0);
        }
        if (monitoringNav) {
            monitoringNav.classList.toggle('highlight', (counts.monitoring || 0) > 0);
        }
        if (geofenceNav) {
            geofenceNav.classList.toggle('highlight', (counts.geofence || 0) > 0);
        }
        if (pendingEditsNav) {
            pendingEditsNav.classList.toggle('highlight', (counts.pending_edits || 0) > 0);
        }
    }

    // Function to fetch highlight counts
    function fetchRedHighlightCounts() {
        if (!isPageActive) return;
        fetch('fetch_red_highlight_counts.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    console.error('Error from fetch_red_highlight_counts:', data.error);
                    updateRedHighlightCounts(lastValidCounts);
                    return;
                }
                updateRedHighlightCounts(data);
            })
            .catch(error => {
                console.error('Error fetching red highlight counts:', error);
                updateRedHighlightCounts(lastValidCounts);
            });
    }

    // Set up Server-Sent Events for real-time updates
    const eventSource = new EventSource('stream_red_highlight_counts.php');
    eventSource.onmessage = function(event) {
        if (!isPageActive) return;
        try {
            const data = JSON.parse(event.data);
            updateRedHighlightCounts(data);
        } catch (e) {
            console.error('Invalid JSON from stream_red_highlight_counts:', event.data);
            updateRedHighlightCounts(lastValidCounts);
        }
    };
    eventSource.onerror = function() {
        console.error('SSE error for red highlight counts, attempting to reconnect');
        setTimeout(() => {
            const newEventSource = new EventSource('stream_red_highlight_counts.php');
            newEventSource.onmessage = eventSource.onmessage;
            newEventSource.onerror = eventSource.onerror;
            eventSource.close();
            eventSource = newEventSource;
        }, 5000);
    };
    // Track page visibility to pause/resume updates
    document.addEventListener('visibilitychange', () => {
        isPageActive = !document.hidden;
        if (isPageActive) fetchRedHighlightCounts();
    });

    // Initial fetch and on focus
    window.addEventListener('focus', fetchRedHighlightCounts);
    fetchRedHighlightCounts();

    function loadTables(page1, page2, filter, search) {
    fetch(`fetch_vehicles.php?page1=${page1}&page2=${page2}&filter=${encodeURIComponent(filter)}&search=${encodeURIComponent(search)}&lastCheck=${lastCheckTime}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response from fetch_vehicles:', text);
                    throw new Error(`JSON parse error: ${e.message}`);
                }
            });
        })
        .then(data => {
            // Table 1: Contract Monitoring
            const body1 = document.getElementById('noPhysicalStatusBody');
            body1.innerHTML = data.contractVehicles.length > 0 ? '' : '<tr><td colspan="9" class="no-results">No results found</td></tr>';
            data.contractVehicles.forEach(vehicle => {
                const row = document.createElement('tr');
                row.onclick = (e) => {
                    if (e.target.classList.contains('map-icon')) return;
                    fetchVehicleInfo(vehicle.target_name);
                };
                if (vehicle.source === 'komtrax') row.classList.add('komtrax-vehicle');
                if (vehicle.is_overdue) row.classList.add('overdue');
                if (vehicle.is_updated) row.classList.add('newly-updated');

                const latVal = parseFloat(vehicle.latitude);
                const lngVal = parseFloat(vehicle.longitude);
                const safeLat = !isNaN(latVal) && latVal >= -90 && latVal <= 90 ? latVal.toFixed(6) : '';
                const safeLng = !isNaN(lngVal) && lngVal >= -180 && lngVal <= 180 ? lngVal.toFixed(6) : '';
                const safeName = vehicle.target_name ? vehicle.target_name.replace(/[<>]/g, '') : 'N/A';
                const hasLocation = safeLat && safeLng;
                const iconAttributes = hasLocation 
                    ? `class="fas fa-map-pin map-icon" data-lat="${safeLat}" data-lng="${safeLng}" data-name="${safeName}" title="View on Map"`
                    : `class="fas fa-map-pin map-icon disabled" title="No location data available"`;

                const friendlyAssignment = assignmentMap[vehicle.assignment] || vehicle.assignment;

                row.innerHTML = `
                    <td>${vehicle.target_name}</td>
                    <td>${friendlyAssignment}</td>
                    <td>${vehicle.date_transferred}</td>
                    <td>${vehicle.days_contract}</td>
                    <td>${vehicle.date_ended}</td>
                    <td>${vehicle.days_elapsed}</td>
                    <td>${vehicle.days_lapses !== null ? vehicle.days_lapses : 'N/A'}</td>
                    <td>${vehicle.remarks}</td>
                    <td><i ${iconAttributes}></i></td>
                `;
                body1.appendChild(row);
            });

            // Attach event listeners to map icons
            const mapIcons = document.querySelectorAll('.map-icon');
            mapIcons.forEach(icon => {
                if (!icon.classList.contains('disabled')) {
                    icon.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const lat = parseFloat(icon.getAttribute('data-lat'));
                        const lng = parseFloat(icon.getAttribute('data-lng'));
                        const name = icon.getAttribute('data-name');
                        if (!isNaN(lat) && !isNaN(lng)) {
                            showVehicleOnMap(lat, lng, name);
                        } else {
                            alert('Invalid or missing location data.');
                        }
                    });
                }
            });

            // Table 2: Maintenance Monitoring
            const body2 = document.getElementById('maintenanceTableBody');
            body2.innerHTML = data.maintenanceVehicles.length > 0 ? '' : '<tr><td colspan="3" class="no-results">No results found</td></tr>';
            data.maintenanceVehicles.forEach(vehicle => {
                const row = document.createElement('tr');
                const friendlyAssignment = assignmentMap[vehicle.assignment] || vehicle.assignment;
                row.onclick = () => fetchVehicleInfo(vehicle.target_name);
                if (vehicle.source === 'komtrax') row.classList.add('komtrax-vehicle');
                if (vehicle.is_breakdown) row.classList.add('breakdown');
                if (vehicle.is_updated) row.classList.add('newly-updated');
                if (vehicle.is_days_lapses_null) row.classList.add('null-days-lapses');
                row.innerHTML = `
                    <td>${vehicle.target_name}</td>
                    <td>${friendlyAssignment}</td>
                    <td>${vehicle.physical_status}</td>
                `;
                body2.appendChild(row);
            });

            // Update pagination
            document.getElementById('pageInfo1').innerText = `Page ${page1} of ${Math.ceil((data.totalContractCount || 0) / itemsPerPage)}`;
            document.getElementById('pageInfo2').innerText = `Page ${page2} of ${Math.ceil((data.totalMaintenanceCount || 0) / itemsPerPage)}`;
            updatePaginationControls(data.totalContractCount || 0, page1, 'table1');
            updatePaginationControls(data.totalMaintenanceCount || 0, page2, 'table2');
        })
        .catch(error => console.error("Error fetching vehicles:", error));
}
let mapInstance = null;

function showVehicleOnMap(lat, lng, name) {
    try {
        const mapUrl = `vehicle_map.html?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}&name=${encodeURIComponent(name)}`;
        console.log('Attempting to open map with URL:', mapUrl);
        mapWindow = window.open(mapUrl, 'vehicleMap');
        if (mapWindow) {
            console.log('Map window opened or updated successfully');
            mapWindow.focus();
        } else {
            console.warn('Map window failed to open, likely due to pop-up blocker');
            alert('Unable to open map. Please allow pop-ups for this site and try again.');
        }
    } catch (error) {
        console.error('Error in showVehicleOnMap:', error.message, error.stack);
        alert('Error displaying map: ' + error.message);
    }
}

// Add event listeners to map buttons after rendering all rows
const mapButtons = document.querySelectorAll('.map-btn');
console.log(`Found ${mapButtons.length} map buttons`); // Debug: Verify buttons are found
mapButtons.forEach(button => {
    button.addEventListener('click', (e) => {
        e.stopPropagation(); // Prevent row click event
        console.log('Map button clicked:', button.dataset); // Debug: Log button data
        const lat = button.getAttribute('data-lat');
        const lng = button.getAttribute('data-lng');
        const name = button.getAttribute('data-name');
        if (lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng))) {
            showVehicleOnMap(lat, lng, name);
        } else {
            console.warn('Invalid location data:', { lat, lng, name }); // Debug: Log invalid data
            alert('Location data not available for this vehicle.');
        }
    });
});

function fetchVehicleInfo(targetName) {
    fetch(`fetch_vehicle_info.php?target_name=${encodeURIComponent(targetName)}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response from fetch_vehicle_info:', text);
                    throw new Error(`JSON parse error: ${e.message}`);
                }
            });
        })
        .then(data => {
            const vehicleInfoDiv = document.getElementById('vehicleInfo');
            const editForm = document.getElementById('editForm');
            const modalTitle = document.getElementById('modalTitle');
            const messageContainer = document.getElementById('messageContainer');
            const modalButtons = document.getElementById('modalButtons');

            modalButtons.innerHTML = '<?php if ($_SESSION["role"] !== "User") : ?><button id="editButton" class="edit-button">Edit</button><?php endif; ?><?php if ($_SESSION["role"] === "Admin" || $_SESSION["role"] === "Main Admin") : ?><button id="deleteButton" class="delete-button">Delete</button><?php endif; ?>';

            if (data.error) {
                vehicleInfoDiv.innerHTML = `<p style="color: #c62828; text-align: center;">Error: ${data.error}</p>`;
                modalButtons.innerHTML = '';
                editForm.style.display = 'none';
                modalTitle.innerText = 'Vehicle Information';
            } else {
                const currentDate = new Date('2025-05-20T22:13:00-08:00');
                let daysElapsed = 0;
                let daysLapses = 0;

                if (data.date_transferred) {
                    try {
                        const dateTransferred = new Date(data.date_transferred);
                        if (!isNaN(dateTransferred)) {
                            const diffTime = currentDate.getTime() - dateTransferred.getTime();
                            daysElapsed = Math.floor(diffTime / (1000 * 60 * 60 * 24));
                        }
                    } catch (e) {
                        console.error("Error calculating days_elapsed:", e);
                    }
                }

                if (data.date_ended) {
                    try {
                        const dateEnded = new Date(data.date_ended);
                        if (!isNaN(dateEnded)) {
                            const diffTime = currentDate.getTime() - dateEnded.getTime();
                            daysLapses = Math.max(0, Math.floor(diffTime / (1000 * 60 * 60 * 24)));
                        }
                    } catch (e) {
                        console.error("Error calculating days_lapses:", e);
                    }
                }

                data.days_elapsed = daysElapsed;
                data.days_lapses = daysLapses;
                data.source_table = data.source;

                const displayFields = data.source === 'devices' ? devicesDisplayFields : komtraxDisplayFields;
                const daysContract = parseInt(data.days_contract) || 0;
                const highlightClass = (daysElapsed > daysContract && daysContract > 0) ? 'highlight-red' : '';

                let formHTML = '<div class="info-group">';
                displayFields.forEach(col => {
                    if (col === 'source_table') return; // Skip source_table for display
                    let value = data[col] ?? 'N/A';
                    if (value === '0000-00-00') value = 'N/A';
                    if (col === 'assignment') {
                        value = assignmentMap[value] || value; // Use user-friendly assignment name
                    }
                    if (col === 'last_assignment') {
                        value = assignmentMap[value] || value; // Use user-friendly assignment name
                    }
                    value = value.toString().replace(/</g, '<').replace(/>/g, '>');
                    formHTML += `
                        <div class="info-item ${col === 'days_elapsed' && highlightClass ? highlightClass : ''}">
                            <label>${col.toUpperCase().replace(/_/g, ' ')}:</label>
                            <span>${value}</span>
                        </div>
                    `;
                });
                formHTML += '</div>';
                vehicleInfoDiv.innerHTML = formHTML;
                editForm.innerHTML = '';
                editForm.style.display = 'none';
                modalTitle.innerText = 'Vehicle Information';
                messageContainer.innerHTML = '';

                <?php if ($_SESSION['role'] !== 'User') : ?>
                    const editButton = document.getElementById('editButton');
                    if (editButton) {
                        editButton.onclick = () => {
                            enterEditMode(data, displayFields);
                        };
                    }
                <?php endif; ?>
                <?php if ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Main Admin') : ?>
                    const deleteButton = document.getElementById('deleteButton');
                    if (deleteButton) {
                        console.log('Delete button found, attaching event listener for role: <?php echo $_SESSION['role']; ?>');
                        deleteButton.onclick = () => {
                            if (confirm(`Are you sure you want to delete vehicle ${data.target_name}? This action cannot be undone.`)) {
                                fetch('delete_vehicle.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: `id=${encodeURIComponent(data.id)}&table=${encodeURIComponent(data.source)}`
                                })
                                .then(response => {
                                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                                    return response.json();
                                })
                                .then(result => {
                                    if (result.success) {
                                        messageContainer.innerHTML = '<div class="message success">' + result.message + '</div>';
                                        setTimeout(() => {
                                            closeModal();
                                            location.reload();
                                        }, 2000);
                                    } else {
                                        messageContainer.innerHTML = '<div class="message error">' + (result.message || 'Failed to delete vehicle.') + '</div>';
                                    }
                                })
                                .catch(error => {
                                    messageContainer.innerHTML = `<div class="message error">Error deleting vehicle: ${error.message}</div>`;
                                });
                            }
                        };
                    } else {
                        console.warn('Delete button not found in DOM');
                    }
                <?php endif; ?>
            }
            
            showModal();
        })
        .catch(error => {
            const vehicleInfoDiv = document.getElementById('vehicleInfo');
            const editForm = document.getElementById('editForm');
            const modalButtons = document.getElementById('modalButtons');
            vehicleInfoDiv.innerHTML = `<p style="color: #c62828; text-align: center;">Error fetching vehicle information: ${error.message}</p>`;
            modalButtons.innerHTML = '';
            editForm.style.display = 'none';
            showModal();
        });
}

    // New JavaScript for real-time fleet stats (from dashboard.php)
    function updateFleetStats(data) {
                document.getElementById('totalVehicles').textContent = data.total || 0;
                document.getElementById('activeVehicles').textContent = data.active || 0;
                document.getElementById('inactiveVehicles').textContent = data.inactive || 0;
                document.getElementById('breakdownVehicles').textContent = data.breakdown || 0;
            }

            const vehicleStatsSource = new EventSource('stream_vehicle_stats.php');
            vehicleStatsSource.onmessage = function (event) {
                if (!isPageActive) return;
                try {
                    const data = JSON.parse(event.data);
                    if (data.success) {
                        updateFleetStats(data);
                    } else {
                        console.error('SSE vehicle stats error:', data.error || 'Unknown error');
                    }
                } catch (e) {
                    console.error('Invalid JSON from stream_vehicle_stats:', event.data);
                }
            };
            vehicleStatsSource.onerror = function () {
                console.error('SSE error for vehicle stats, falling back to polling');
                vehicleStatsSource.close();
                setInterval(() => {
                    if (!isPageActive) return;
                    fetch('fetch_vehicle_stats.php')
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            return response.text().then(text => {
                                try {
                                    return JSON.parse(text);
                                } catch (e) {
                                    console.error('Invalid JSON response from fetch_vehicle_stats:', text);
                                    throw new Error(`JSON parse error: ${e.message}`);
                                }
                            });
                        })
                        .then(data => {
                            if (data.success) {
                                updateFleetStats(data);
                            } else {
                                console.error('Fetch vehicle stats error:', data.error || 'Unknown error');
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching vehicle stats:', error);
                        });
                }, 10000);
            };

    function enterEditMode(data, displayFields) {
        const vehicleInfoDiv = document.getElementById('vehicleInfo');
        const editForm = document.getElementById('editForm');
        const modalTitle = document.getElementById('modalTitle');
        const modalButtons = document.getElementById('modalButtons');
        const messageContainer = document.getElementById('messageContainer');

        const validTables = ['devices', 'komtrax'];
        if (!validTables.includes(data.source)) {
            messageContainer.innerHTML = '<div class="message error">Invalid table source. Cannot edit this record.</div>';
            modalButtons.innerHTML = '<button class="cancel-button" onclick="closeModal()">Close</button>';
            return;
        }

        fetch(`check_pending_edits.php?id=${encodeURIComponent(data.id)}&table=${encodeURIComponent(data.source)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response from check_pending_edits:', text);
                        throw new Error(`JSON parse error: ${e.message}`);
                    }
                });
            })
            .then(result => {
                if (!result || typeof result.hasPending === 'undefined') {
                    messageContainer.innerHTML = '<div class="message error">Invalid response from server.</div>';
                    modalButtons.innerHTML = '<button class="cancel-button" onclick="closeModal()">Close</button>';
                    return;
                }
                if (result.error) {
                    messageContainer.innerHTML = `<div class="message error">Error: ${result.error}</div>`;
                    modalButtons.innerHTML = '<button class="cancel-button" onclick="closeModal()">Close</button>';
                    return;
                }
                if (result.hasPending) {
                    messageContainer.innerHTML = '<div class="message error">This record has changes pending approval. Please wait until they are reviewed before submitting new changes.</div>';
                    modalButtons.innerHTML = '<button class="cancel-button" onclick="closeModal()">Close</button>';
                    return;
                }

                modalTitle.innerText = `Edit ${data.source.charAt(0).toUpperCase() + data.source.slice(1)} Information (ID: ${data.id})`;
                vehicleInfoDiv.innerHTML = '';
                editForm.style.display = 'block';
                modalButtons.innerHTML = `
                    <button class="save-button" id="saveButton">Submit Changes for Approval</button>
                    <button class="cancel-button" id="cancelButton">Cancel</button>
                `;

                const fieldsReadonlySystem = data.source === 'devices' ?
                    fieldsReadonlySystemBase :
                    [...fieldsReadonlySystemBase, ...komtraxAdditionalReadonly];
                const fieldsReadonlyForm = [...fieldsReadonlySystem, 'next_pms_date'];

                let formHTML = '<form id="editVehicleForm" class="form-container"><div style="display: flex; flex-wrap: wrap; gap: 15px; justify-content: space-between;">';
                displayFields.forEach(field => {
                    if (field === 'id' || field === 'source_table') return;
                    const isReadonly = fieldsReadonlyForm.includes(field);
                    const readonlyAttr = isReadonly ? ' readonly' : '';
                    let inputValue = data[field] ?? '';
                    inputValue = inputValue.toString().replace(/</g, '<').replace(/>/g, '>');
                    let inputClass = '';
                    let placeholder = '';

                    if (field === 'date_transferred' || field === 'date_ended' || field === 'last_date_transferred' || field === 'last_date_ended') {
                        inputClass = 'date-field';
                        placeholder = 'YYYY-MM-DD HH:MM:SS';
                        inputValue = inputValue || new Date().toISOString().replace('T', ' ').substring(0, 19);
                    } else if (field === 'last_pms_date') {
                        inputClass = 'pms-date-field';
                        placeholder = 'MM-DD-YYYY';
                        if (inputValue && inputValue !== '0000-00-00') {
                            try {
                                inputValue = new Date(inputValue).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
                            } catch (e) {
                                console.error(`Error formatting ${field}:`, e);
                            }
                        }
                    } else if (field === 'next_pms_date') {
                        inputClass = 'pms-date-field';
                        placeholder = 'MM-DD-YYYY (Auto)';
                        if (inputValue && inputValue !== '0000-00-00') {
                            try {
                                inputValue = new Date(inputValue).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
                            } catch (e) {
                                console.error(`Error formatting ${field}:`, e);
                            }
                        }
                    } else if (field === 'pms_interval') {
                        placeholder = 'Days';
                    }

                    if (field === 'physical_status') {
                        formHTML += `
                            <div class="form-group">
                                <label for="${field}">${field.toUpperCase().replace(/_/g, ' ')}:</label>
                                <select name="${field}" id="${field}" ${isReadonly ? 'disabled' : 'required'}>
                                    <option value="">Select Status...</option>
                                    <option value="Operational" ${inputValue === 'Operational' ? 'selected' : ''}>Operational</option>
                                    <option value="Inactive" ${inputValue === 'Inactive' ? 'selected' : ''}>Inactive</option>
                                    <option value="Breakdown" ${inputValue === 'Breakdown' ? 'selected' : ''}>Breakdown</option>
                                </select>
                            </div>
                        `;
                    } else if (field === 'assignment') {
                            formHTML += `
                                <div class="form-group">
                                    <label for="${field}">${field.toUpperCase().replace(/_/g, ' ')}:</label>
<select name="${field}" id="${field}" ${isReadonly ? 'disabled' : 'required'}>
                                        <option value="">Select Assignment...</option>
                                        <option value="assignment_amlan" ${inputValue === 'assignment_amlan' ? 'selected' : ''}>Amlan</option>
                                        <option value="assignment_balingueo" ${inputValue === 'assignment_balingueo' ? 'selected' : ''}>Balingueo SS</option>
                                        <option value="assignment_banilad" ${inputValue === 'assignment_banilad' ? 'selected' : ''}>Banilad SS</option>
                                        <option value="assignment_barotac" ${inputValue === 'assignment_barotac' ? 'selected' : ''}>Barotac Viejo SS</option>
                                        <option value="assignment_bayombong" ${inputValue === 'assignment_bayombong' ? 'selected' : ''}>Bayombong SS</option>
                                        <option value="assignment_binan" ${inputValue === 'assignment_binan' ? 'selected' : ''}>Binan SS</option>
                                        <option value="assignment_bolo" ${inputValue === 'assignment_bolo' ? 'selected' : ''}>Bolo</option>
                                        <option value="assignment_botolan" ${inputValue === 'assignment_botolan' ? 'selected' : ''}>Botolan SS</option>
                                        <option value="assignment_cadiz" ${inputValue === 'assignment_cadiz' ? 'selected' : ''}>Cadiz SS</option>
                                        <option value="assignment_calacass" ${inputValue === 'assignment_calacass' ? 'selected' : ''}>Calaca SS</option>
                                        <option value="assignment_calacatl" ${inputValue === 'assignment_calacatl' ? 'selected' : ''}>Calaca TL</option>
                                        <option value="assignment_calatrava" ${inputValue === 'assignment_calatrava' ? 'selected' : ''}>Calatrava SS</option>
                                        <option value="assignment_castillejos" ${inputValue === 'assignment_castillejos' ? 'selected' : ''}>Castillejos TL</option>
                                        <option value="assignment_dasmarinas" ${inputValue === 'assignment_dasmarinas' ? 'selected' : ''}>Dasmarinas SS</option>
                                        <option value="assignment_dumanjug" ${inputValue === 'assignment_dumanjug' ? 'selected' : ''}>Dumanjug SS</option>
                                        <option value="assignment_ebmagalona" ${inputValue === 'assignment_ebmagalona' ? 'selected' : ''}>EB Magalona SS</option>
                                        <option value="assignment_headoffice" ${inputValue === 'assignment_headoffice' ? 'selected' : ''}>Head Office</option>
                                        <option value="assignment_hermosatl" ${inputValue === 'assignment_hermosatl' ? 'selected' : ''}>Hermosa TL</option>
                                        <option value="assignment_hermosa" ${inputValue === 'assignment_hermosa' ? 'selected' : ''}>Hermosa SS</option>
                                        <option value="assignment_ilijan" ${inputValue === 'assignment_ilijan' ? 'selected' : ''}>Ilijan SS</option>
                                        <option value="assignment_isabel" ${inputValue === 'assignment_isabel' ? 'selected' : ''}>Isabel SS</option>
                                        <option value="assignment_maasin" ${inputValue === 'assignment_maasin' ? 'selected' : ''}>Maasin SS</option>
                                        <option value="assignment_muntinlupa" ${inputValue === 'assignment_muntinlupa' ? 'selected' : ''}>Muntinlupa SS</option>
                                        <option value="assignment_pantabangan" ${inputValue === 'assignment_pantabangan' ? 'selected' : ''}>Pantabangan SS</option>
                                        <option value="assignment_paoay" ${inputValue === 'assignment_paoay' ? 'selected' : ''}>Paoay TL</option>                                        
                                        <option value="assignment_pinamucan" ${inputValue === 'assignment_pinamucan' ? 'selected' : ''}>Pinamucan SS</option>
                                        <option value="assignment_quirino" ${inputValue === 'assignment_quirino' ? 'selected' : ''}>Quirino</option>
                                        <option value="assignment_sanjose" ${inputValue === 'assignment_sanjose' ? 'selected' : ''}>San Jose SS</option>
                                        <option value="assignment_tabango" ${inputValue === 'assignment_tabango' ? 'selected' : ''}>Tabango SS</option>
                                        <option value="assignment_tayabas" ${inputValue === 'assignment_tayabas' ? 'selected' : ''}>Tayabas SS</option>
                                        <option value="assignment_taytay" ${inputValue === 'assignment_taytay' ? 'selected' : ''}>Taytay SS</option>
                                        <option value="assignment_terrasolar" ${inputValue === 'assignment_terrasolar' ? 'selected' : ''}>Terra Solar</option>
                                        <option value="assignment_terrasolar" ${inputValue === 'assignment_tuguegarao' ? 'selected' : ''}>Tuguegarao SS</option>
                                        <option value="assignment_tuy" ${inputValue === 'assignment_tuy' ? 'selected' : ''}>Tuy SS</option>                                    
                                        </select>
                                </div>
                            `;
                    } else {
                        const inputType = ['date_transferred', 'date_ended', 'last_date_transferred', 'last_date_ended', 'last_pms_date', 'next_pms_date'].includes(field) ? 'text' :
                                         field === 'pms_interval' ? 'number' : 'text';
                        const minAttr = field === 'pms_interval' ? 'min="0" step="1"' : '';
                        formHTML += `
                            <div class="form-group">
                                <label for="${field}">${field.toUpperCase().replace(/_/g, ' ')}:</label>
                                <input type="${inputType}" ${minAttr} class="${inputClass}" name="${field}" id="${field}" value="${inputValue}" placeholder="${placeholder}" ${readonlyAttr}>
                            </div>
                        `;
                    }
                });
                formHTML += `
                    <input type="hidden" name="id" value="${data.id}">
                    <input type="hidden" name="table" value="${data.source}">
                    </div></form>`;
                editForm.innerHTML = formHTML;

                flatpickr(".date-field", {
                    enableTime: true,
                    dateFormat: "Y-m-d H:i:s",
                    time_24hr: true,
                    allowInput: true,
                    defaultDate: new Date()
                });

                const pmsDatePickers = flatpickr(".pms-date-field", {
                    enableTime: false,
                    dateFormat: "m-d-Y",
                    allowInput: true,
                    onChange: function(selectedDates, dateStr, instance) {
                        if (instance.element.id === 'last_pms_date') {
                            calculateNextPmsDate();
                        }
                    }
                });

                const nextPmsDatePicker = document.getElementById('next_pms_date')?._flatpickr;
                const lastPmsDateInput = document.getElementById("last_pms_date");
                const pmsIntervalInput = document.getElementById("pms_interval");
                const nextPmsDateInput = document.getElementById("next_pms_date");

                function calculateNextPmsDate() {
                    if (!lastPmsDateInput || !pmsIntervalInput || !nextPmsDateInput || !nextPmsDatePicker) {
                        console.warn("PMS date inputs missing for next_pms_date calculation");
                        return;
                    }

                    const lastPmsStr = lastPmsDateInput.value;
                    const intervalStr = pmsIntervalInput.value;
                    const intervalDays = parseInt(intervalStr, 10);

                    if (isNaN(intervalDays) || intervalDays < 0 || intervalStr !== intervalDays.toString()) {
                        nextPmsDateInput.value = "";
                        if (nextPmsDatePicker) {
                            nextPmsDatePicker.clear();
                        }
                        return;
                    }

                    const lastPmsDate = flatpickr.parseDate(lastPmsStr, "m-d-Y");
                    if (lastPmsDate instanceof Date && !isNaN(lastPmsDate)) {
                        try {
                            const nextPmsDate = new Date(lastPmsDate.getTime());
                            nextPmsDate.setDate(nextPmsDate.getDate() + intervalDays);
                            const formattedNextPmsDate = flatpickr.formatDate(nextPmsDate, "m-d-Y");
                            nextPmsDateInput.value = formattedNextPmsDate;
                            if (nextPmsDatePicker) {
                                nextPmsDatePicker.setDate(nextPmsDate, false);
                            }
                        } catch (e) {
                            console.error("Error calculating next PMS date:", e);
                            nextPmsDateInput.value = "";
                            if (nextPmsDatePicker) {
                                nextPmsDatePicker.clear();
                            }
                        }
                    } else {
                        nextPmsDateInput.value = "";
                        if (nextPmsDatePicker) {
                            nextPmsDatePicker.clear();
                        }
                    }
                }

                if (pmsIntervalInput) {
                    pmsIntervalInput.addEventListener("input", calculateNextPmsDate);
                }
                calculateNextPmsDate();

                const dateTransferredInput = document.getElementById("date_transferred");
                const dateEndedInput = document.getElementById("date_ended");
                const daysContractInput = document.getElementById("days_contract");
                const daysElapsedInput = document.getElementById("days_elapsed");
                const daysLapsesInput = document.getElementById("days_lapses");

                function calculateContractAndLapses() {
                    if (!dateTransferredInput || !dateEndedInput || !daysContractInput || !daysElapsedInput || !daysLapsesInput) {
                        console.warn("Missing inputs for contract/lapse calculations");
                        return;
                    }

                    const startStr = dateTransferredInput.value;
                    const endStr = dateEndedInput.value;
                    const now = new Date('2025-05-20T22:13:00-08:00');
                    let contractDays = 0;
                    let elapsedDays = 0;
                    let lapsedDays = 0;

                    try {
                        const start = flatpickr.parseDate(startStr, "Y-m-d H:i:s");
                        const end = flatpickr.parseDate(endStr, "Y-m-d H:i:s");
                        if (start && end && !isNaN(start) && !isNaN(end) && end >= start) {
                            const diffTime = end.getTime() - start.getTime();
                            contractDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
                        }
                    } catch (e) {
                        console.error("Error parsing dates for days_contract:", e);
                    }
                    daysContractInput.value = contractDays >= 0 ? contractDays : 0;

                    try {
                        const start = flatpickr.parseDate(startStr, "Y-m-d H:i:s");
                        if (start && !isNaN(start)) {
                            const elapsedTime = now.getTime() - start.getTime();
                            elapsedDays = Math.floor(elapsedTime / (1000 * 60 * 60 * 24));
                        }
                    } catch (e) {
                        console.error("Error parsing date_transferred for days_elapsed:", e);
                    }
                    daysElapsedInput.value = elapsedDays >= 0 ? elapsedDays : 0;

                    try {
                        const end = flatpickr.parseDate(endStr, "Y-m-d H:i:s");
                        if (end && !isNaN(end) && now > end) {
                            const lapsedTime = now.getTime() - end.getTime();
                            lapsedDays = Math.floor(lapsedTime / (1000 * 60 * 60 * 24));
                        }
                    } catch (e) {
                        console.error("Error parsing date_ended for days_lapses:", e);
                    }
                    daysLapsesInput.value = lapsedDays >= 0 ? lapsedDays : 0;

                    if (elapsedDays > contractDays && contractDays > 0) {
                        daysElapsedInput.parentElement.classList.add('highlight-red');
                    } else {
                        daysElapsedInput.parentElement.classList.remove('highlight-red');
                    }
                }

                if (dateTransferredInput) {
                    dateTransferredInput.addEventListener("change", calculateContractAndLapses);
                }
                if (dateEndedInput) {
                    dateEndedInput.addEventListener("change", calculateContractAndLapses);
                }
                calculateContractAndLapses();

                const saveButton = document.getElementById('saveButton');
                const cancelButton = document.getElementById('cancelButton');

                if (saveButton) {
                    saveButton.addEventListener('click', () => {
                        const form = document.getElementById('editVehicleForm');
                        const formData = new FormData(form);
                        fetch('submit_edit.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            return response.text().then(text => {
                                try {
                                    return JSON.parse(text);
                                } catch (e) {
                                    console.error('Invalid JSON response from submit_edit:', text);
                                    throw new Error(`JSON parse error: ${e.message}`);
                                }
                            });
                        })
                        .then(result => {
                            if (result.success) {
                                messageContainer.innerHTML = '<div class="message success">' + result.message + '</div>';
                                setTimeout(() => {
                                    fetchVehicleInfo(data.target_name);
                                }, 2000);
                            } else {
                                messageContainer.innerHTML = '<div class="message error">' + result.message + '</div>';
                            }
                        })
                        .catch(error => {
                            messageContainer.innerHTML = `<div class="message error">Error submitting changes: ${error.message}</div>`;
                        });
                    });
                }

                if (cancelButton) {
                    cancelButton.addEventListener('click', () => {
                        fetchVehicleInfo(data.target_name);
                    });
                }
            })
            .catch(error => {
                messageContainer.innerHTML = `<div class="message error">Error checking pending edits: ${error.message}</div>`;
                modalButtons.innerHTML = '<button class="cancel-button" onclick="closeModal()">Close</button>';
            });
    }

    function updatePaginationControls(totalCount, currentPage, table) {
        const totalPages = Math.ceil(totalCount / itemsPerPage);
        const prevButton = document.getElementById(`prevPage${table === 'table1' ? '1' : '2'}`);
        const nextButton = document.getElementById(`nextPage${table === 'table1' ? '1' : '2'}`);

        if (prevButton) prevButton.disabled = currentPage === 1;
        if (nextButton) nextButton.disabled = currentPage === totalPages || totalPages === 0;
    }

    const equipmentFilter = document.getElementById('equipmentFilter');
    const searchInput = document.getElementById('searchInput');

    if (equipmentFilter) {
        equipmentFilter.addEventListener('change', function() {
            filterType = this.value;
            currentPage1 = 1;
            currentPage2 = 1;
            loadTables(currentPage1, currentPage2, filterType, searchQuery);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchQuery = this.value.trim();
            currentPage1 = 1;
            currentPage2 = 1;
            searchTimeout = setTimeout(() => {
                loadTables(currentPage1, currentPage2, filterType, searchQuery);
            }, 300);
        });
    }

    document.getElementById('prevPage1').addEventListener('click', () => {
        if (currentPage1 > 1) {
            currentPage1--;
            loadTables(currentPage1, currentPage2, filterType, searchQuery);
        }
    });

    document.getElementById('nextPage1').addEventListener('click', () => {
        currentPage1++;
        loadTables(currentPage1, currentPage2, filterType, searchQuery);
    });

    document.getElementById('prevPage2').addEventListener('click', () => {
        if (currentPage2 > 1) {
            currentPage2--;
            loadTables(currentPage1, currentPage2, filterType, searchQuery);
        }
    });

    document.getElementById('nextPage2').addEventListener('click', () => {
        currentPage2++;
        loadTables(currentPage1, currentPage2, filterType, searchQuery);
    });

    loadTables(currentPage1, currentPage2, filterType, searchQuery);

    let refreshTimer = setInterval(() => {
        if (isPageActive) {
            lastCheckTime = Math.floor(Date.now() / 1000);
            loadTables(currentPage1, currentPage2, filterType, searchQuery);
        }
    }, refreshInterval);
});
</script>

<!-- Main Content -->
<div class="main-content">
    <h2><center>Vehicles Real-Time Monitoring</center></h2>

        <!-- Fleet Overview (updated to match dashboard.php) -->
        <div class="fleet-stats">
            <div class="stat-box">
                <h3>Total Equipments/Vehicles</h3>
                <p id="totalVehicles"><?php echo htmlspecialchars($totalVehicles); ?></p>
            </div>
            <div class="stat-box">
                <h3>Operational</h3>
                <p id="activeVehicles"><?php echo htmlspecialchars($activeVehicles); ?></p>
            </div>
            <div class="stat-box">
                <h3>Inactive Vehicles</h3>
                <p id="inactiveVehicles"><?php echo htmlspecialchars($inactiveVehicles); ?></p>
            </div>
            <div class="stat-box">
                <h3>Breakdowns</h3>
                <p id="breakdownVehicles"><?php echo htmlspecialchars($breakdownVehicles); ?></p>
            </div>
        </div>

    <div class="filter-search-section">
        <div class="filter-section">
            <label for="equipmentFilter">Filter by Equipment Type:</label>
            <select id="equipmentFilter">
                <option value="">All Equipment Types</option>
                <?php foreach ($equipmentTypes as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>">
                        <?php echo htmlspecialchars($type); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-section">
            <label for="searchInput">Search by Vehicle:</label>
            <input type="text" id="searchInput" placeholder="Enter vehicle name...">
        </div>
        <?php if ($_SESSION['role'] !== 'User') : ?>
        <div class="filter-section" style="margin: 0;">
            <button id="addVehicleButton" class="edit-button">Add Vehicle</button>
        </div>
        <?php endif;?>
    </div>

    <!-- Table 1: Contract Monitoring -->
    <div class="table-sub-container">
        <h2>Vehicle Mobilization Monitoring</h2>
        <div class="table-wrapper">
            <table class="vehicle-table">
            <thead>
                <tr>
                    <th>Vehicle Name</th>
                    <th>Assignment</th>
                    <th>Date Deployed</th>
                    <th>Contract Period</th>
                    <th>Date Ended</th>
                    <th>Days Elapsed</th>
                    <th>Days Lapses</th>
                    <th>Remarks</th>
                    <th>Map</th>
                </tr>
            </thead>
                <tbody id="noPhysicalStatusBody"></tbody>
            </table>
        </div>
        <div class="pagination">
            <button class="pagination-button" id="prevPage1">Previous</button>
            <span class="pagination-info" id="pageInfo1">Page 1</span>
            <button class="pagination-button" id="nextPage1">Next</button>
        </div>
    </div>

    <!-- Table 2: Maintenance Monitoring -->
    <div class="table-sub-container">
        <h2>Maintenance Monitoring</h2>
        <div class="table-wrapper">
            <table class="vehicle-table">
                <thead>
                    <tr>
                        <th>Vehicle Name</th>
                        <th>Assignment</th>
                        <th>Physical Status</th>
                    </tr>
                </thead>
                <tbody id="maintenanceTableBody"></tbody>
            </table>
        </div>
        <div class="pagination">
            <button class="pagination-button" id="prevPage2">Previous</button>
            <span class="pagination-info" id="pageInfo2">Page 1</span>
            <button class="pagination-button" id="nextPage2">Next</button>
        </div>
    </div>
</div>
<!-- Map Modal -->
<div id="mapModal" class="modal">
    <div class="modal-content" style="width: 80%; max-width: 1000px;">
        <span class="close-btn" onclick="closeMapModal()">×</span>
        <h3 id="mapModalTitle">Vehicle Location</h3>
        <div id="map" style="height: 500px; width: 100%; border-radius: 10px;"></div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>