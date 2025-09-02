<?php
ob_start(); // Start output buffering
session_start();

// Check session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.html");
    exit();
}

require 'db_connect.php';

// Ensure database connection is valid
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Server error: Unable to connect to database. Please try again later.");
}

// Set timezone to PST
date_default_timezone_set('Asia/Manila');
$current_date = date('c');

$current_page = basename($_SERVER['PHP_SELF']);

// Get unique equipment types
$equipmentTypes = [];
$equipmentQuery = "
    SELECT DISTINCT equipment_type FROM (
        SELECT equipment_type FROM devices WHERE equipment_type IS NOT NULL
        UNION
        SELECT equipment_type FROM komtrax WHERE equipment_type IS NOT NULL
    ) AS combined ORDER BY equipment_type";
$equipmentResult = $conn->query($equipmentQuery);
if ($equipmentResult) {
    while ($row = $equipmentResult->fetch_assoc()) {
        $equipmentTypes[] = trim($row['equipment_type']);
    }
} else {
    error_log("Equipment types query failed: " . $conn->error);
}

// Get vehicle statistics
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

// Get pending count for Main Admin
$pendingCount = 0;
if ($_SESSION['role'] === 'Main Admin') {
    $pendingQuery = "SELECT COUNT(*) as count FROM pending_edits WHERE status = 'pending'";
    $pendingResult = $conn->query($pendingQuery);
    if ($pendingResult) {
        $pendingCount = $pendingResult->fetch_assoc()['count'];
    } else {
        error_log("Pending count query failed: " . $conn->error);
    }
}

ob_end_clean();
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
        h2 {
            margin-bottom: 30px;
            color: #004080;
            text-align: center;
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
        button.map-btn {
            padding: 5px 10px;
            margin: 0 5px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background-color: #ff9800;
            color: white;
            transition: all 0.3s ease;
        }
        button.map-btn:hover {
            background-color: #e68a00;
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
            background-color: #ffcccc;
        }
        .newly-updated {
            background-color: #ccffcc;
        }
        .breakdown {
            background-color: #ffffcc;
        }
        .komtrax-vehicle {
            background-color: #e6f3ff;
        }
        .pms-due {
            animation: blink-red 1s linear infinite;
        }
        .pms-nearing {
            animation: blink-yellow 1s linear infinite;
        }
        .no-results {
            text-align: center;
            padding: 20px;
            color: #c62828;
            font-weight: bold;
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
            z-index: 1001;
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
            <span class="sidebar-nav" data-href="pms_due_summary.php" style="padding-right: 35px;">
                <i class="fa fa-pie-chart" aria-hidden="true"></i>
                <span>Summary</span>
            </span>
        </li>
        <li class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <span class="sidebar-nav" data-href="dashboard.php" style="padding-right: 35px;">
                <i class="fas fa-tachometer-alt"></i>
                <span>Maintenance</span>
            </span>
        </li>
        <li class="<?= ($current_page == 'monitoring.php') ? 'active' : ''; ?>">
            <span class="sidebar-nav" data-href="monitoring.php" style="padding-right: 35px;">
                <i class="fas fa-chart-line"></i>
                <span>Monitoring</span>
            </span>
        </li>
        <li class="<?= ($current_page == 'geofence.php') ? 'active' : ''; ?>">
            <span class="sidebar-nav" data-href="geofence.php" style="padding-right: 35px;">
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

<!-- Main Content -->
<div class="main-content">
    <h2><center>Vehicle PMS Schedule Monitoring</center></h2>           
     <!-- Fleet Overview -->
     <div class="fleet-stats">
        <div class="stat-box">
            <h3>Total Equipments/Vehicles</h3>
            <p id="totalVehicles">Loading...</p>
        </div>
        <div class="stat-box">
            <h3>Operational</h3>
            <p id="activeVehicles">Loading...</p>
        </div>
        <div class="stat-box">
            <h3>Inactive Vehicles</h3>
            <p id="inactiveVehicles">Loading...</p>
        </div>
        <div class="stat-box">
            <h3>Breakdowns</h3>
            <p id="breakdownVehicles">Loading...</p>
        </div>
    </div>

    <!-- Filter and Search Section with Add Vehicle Button -->
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
        <?php endif; ?>
    </div>

    <!-- PMS Schedule Table -->
    <div class="table-sub-container">
        <h2>PMS Schedule Overview</h2>
        <div class="table-wrapper">
            <table class="vehicle-table" id="pmsScheduleTable">
                <thead>
                    <tr>
                        <th>VEHICLE</th>
                        <th>EQUIPMENT TYPE</th>
                        <th>LAST PMS DATE</th>
                        <th>NEXT PMS DATE</th>
                        <th>DAYS REMAINING</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody id="pmsScheduleBody">
                    <tr><td colspan="6">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <!-- Pagination -->
        <div class="pagination" id="paginationControls">
            <button id="prevPage" class="pagination-button" disabled>Previous</button>
            <span id="pageInfo" class="pagination-info">Page 1</span>
            <button id="nextPage" class="pagination-button" disabled>Next</button>
        </div>
    </div>
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
        <?php if ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Main Admin') : ?>
        <?php endif; ?>
    </div>
</div>

<script>
const serverDate = new Date('<?php echo $current_date; ?>');

// Define assignmentDisplayMap globally
const assignmentDisplayMap = {
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
    document.getElementById('modalButtons').innerHTML = '<?php if ($_SESSION["role"] !== "User") : ?><button id="editButton" class="edit-button">Edit</button><?php endif; ?><?php if ($_SESSION["role"] === "Admin" || $_SESSION["role"] === "Main Admin") : ?><button id="deleteButton" class="delete-button">Delete</button><?php endif; ?>';
    document.getElementById('modalTitle').innerText = 'Vehicle Information';
    document.getElementById('messageContainer').innerHTML = '';
}

window.onclick = function(event) {
    const modal = document.getElementById('vehicleModal');
    if (event.target === modal) {
        closeModal();
    }
}

document.addEventListener("DOMContentLoaded", function () {
    console.log('dashboard.php script loaded');
    let currentPage = 1;
    const itemsPerPage = 10;
    let filterType = "";
    let searchQuery = "";
    const refreshInterval = 5000;
    let searchTimeout;
    let isPageActive = true;

    // Define readonly fields
    const fieldsReadonlySystemBase = [
        'id', 'days_contract', 'last_updated', 'position_time', 'address', 'latitude', 'longitude',
        'speed', 'direction', 'total_mileage', 'status', 'type', 'days_elapsed', 'days_lapses',
        'speed_limit', 'days_no_gps', 'source_table', 'license_plate_no', 'cut_address'
    ];
    const komtraxAdditionalReadonly = [
        'cut_address', 'last_assignment', 'last_days_contract', 'last_date_transferred',
        'last_date_ended', 'last_days_elapsed', 'remarks'
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

    // Updated fetchVehicleInfo function
    function fetchVehicleInfo(targetName) {
        console.log(`fetchVehicleInfo called with targetName: ${targetName}`);
        const vehicleInfoDiv = document.getElementById('vehicleInfo');
        const editForm = document.getElementById('editForm');
        const modalTitle = document.getElementById('modalTitle');
        const modalButtons = document.getElementById('modalButtons');
        const messageContainer = document.getElementById('messageContainer');

        if (!vehicleInfoDiv || !editForm || !modalTitle || !modalButtons || !messageContainer) {
            console.error('DOM elements missing:', {
                vehicleInfoDiv: !!vehicleInfoDiv,
                editForm: !!editForm,
                modalTitle: !!modalTitle,
                modalButtons: !!modalButtons,
                messageContainer: !!messageContainer
            });
            return;
        }

        vehicleInfoDiv.innerHTML = '<p>Loading...</p>';
        editForm.innerHTML = '';
        editForm.style.display = 'none';
        modalTitle.innerText = `Vehicle Information: ${targetName}`;
        modalButtons.innerHTML = '<?php if ($_SESSION["role"] !== "User") : ?><button id="editButton" class="edit-button">Edit</button><?php endif; ?><?php if ($_SESSION["role"] === "Admin" || $_SESSION["role"] === "Main Admin") : ?><button id="deleteButton" class="delete-button">Delete</button><?php endif; ?>';
        messageContainer.innerHTML = '';

        fetch(`fetch_vehicle_info.php?target_name=${encodeURIComponent(targetName)}`)
            .then(response => {
                console.log(`Fetch response status: ${response.status}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text().then(text => {
                    console.log(`Raw response text: ${text}`);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response from fetch_vehicle_info:', text);
                        throw new Error(`JSON parse error: ${e.message}`);
                    }
                });
            })
            .then(data => {
                console.log('Parsed vehicle data:', data);
                if (data.error) {
                    console.log('Error in response:', data.error);
                    vehicleInfoDiv.innerHTML = `<p style="color: #c62828; text-align: center;">Error: ${data.error}</p>`;
                    modalButtons.innerHTML = '<button class="cancel-button" onclick="closeModal()">Close</button>';
                    editForm.style.display = 'none';
                    modalTitle.innerText = 'Vehicle Information';
                    showModal();
                    return;
                }
                if (!data || !data.source || !data.id) {
                    console.log('Invalid vehicle data received');
                    vehicleInfoDiv.innerHTML = '<p style="color: #c62828; text-align: center;">Invalid vehicle data received.</p>';
                    modalButtons.innerHTML = '<button class="cancel-button" onclick="closeModal()">Close</button>';
                    editForm.style.display = 'none';
                    modalTitle.innerText = 'Vehicle Information';
                    showModal();
                    return;
                }

                const currentDate = new Date('<?php echo $current_date; ?>');
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

                const daysContract = parseInt(data.days_contract) || 0;
                const highlightClass = (daysElapsed > daysContract && daysContract > 0) ? 'highlight-red' : '';

                const displayFields = [
                    'id', 'target_name', 'equipment_type', 'physical_status', 'assignment',
                    'date_transferred', 'days_contract', 'days_elapsed', 'days_lapses', 'date_ended',
                    'last_updated', 'position_time', 'address', 'latitude', 'longitude', 'speed',
                    'direction', 'total_mileage', 'status', 'type', 'speed_limit', 'days_no_gps',
                    'last_pms_date', 'next_pms_date', 'pms_interval', 'tag', 'specs', 'cut_address',
                    'last_assignment', 'last_days_contract', 'last_date_transferred', 'last_date_ended',
                    'last_days_elapsed', 'remarks', 'source_table', 'requested_by'
                ].filter(col => col in data);

                console.log('Display fields:', displayFields);
                let formHTML = '<div class="info-group">';
                displayFields.forEach(col => {
                    let value = data[col] ?? 'N/A';
                    if (value === '0000-00-00') value = 'N/A';
                    if (col === 'assignment') {
                        console.log(`Processing assignment field. Raw value: ${value}`);
                        const trimmedValue = value ? value.trim() : 'N/A';
                        value = assignmentDisplayMap[trimmedValue] || 'Unknown Assignment';
                        console.log(`Mapped assignment value: ${value}`);
                    }
                    if (col === 'last_assignment') {
                        console.log(`Processing assignment field. Raw value: ${value}`);
                        const trimmedValue = value ? value.trim() : 'N/A';
                        value = assignmentDisplayMap[trimmedValue] || 'Unknown Assignment';
                        console.log(`Mapped assignment value: ${value}`);
                    }
                    if (['last_pms_date', 'next_pms_date'].includes(col) && value !== 'N/A' && value !== '0000-00-00') {
                        try {
                            value = new Date(value).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
                        } catch (e) {
                            console.error(`Error formatting ${col}:`, e);
                        }
                    }
                    value = value.toString().replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
                console.log('Calling showModal');
                showModal();
            })
            .catch(error => {
                console.error('Error in fetchVehicleInfo:', error);
                vehicleInfoDiv.innerHTML = `<p style="color: #c62828; text-align: center;">Error fetching vehicle information: ${error.message}</p>`;
                modalButtons.innerHTML = '<button class="cancel-button" onclick="closeModal()">Close</button>';
                editForm.style.display = 'none';
                modalTitle.innerText = 'Vehicle Information';
                showModal();
            });
    }

    // Updated event listener for table rows
    function attachRowClickListeners() {
        console.log('Attaching row click listeners');
        const rows = document.querySelectorAll('#pmsScheduleTable tbody tr');
        console.log(`Found ${rows.length} table rows`);
        rows.forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.classList.contains('map-icon')) return;
                const targetName = row.cells[0].textContent.trim();
                console.log(`Row clicked, targetName: ${targetName}`);
                if (targetName) {
                    fetchVehicleInfo(targetName);
                } else {
                    console.error('No target_name found for clicked row:', row);
                }
            });
        });
        if (rows.length === 0) {
            console.warn('No table rows found to attach click listeners');
        }
    }

    // Function to update fleet stats
    function updateFleetStats(data) {
        document.getElementById('totalVehicles').textContent = data.total || 0;
        document.getElementById('activeVehicles').textContent = data.active || 0;
        document.getElementById('inactiveVehicles').textContent = data.inactive || 0;
        document.getElementById('breakdownVehicles').textContent = data.breakdown || 0;
    }

    // SSE for real-time vehicle stats
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

    function loadPmsSchedule(page, filter, search) {
        const table = document.getElementById('pmsScheduleTable');
        const tbody = document.getElementById('pmsScheduleBody');
        if (!table || !tbody) {
            console.error('PMS schedule table elements not found');
            return;
        }
        table.classList.add('refreshing');
        fetch(`fetch_pms_schedule.php?page=${page}&filter=${encodeURIComponent(filter)}&search=${encodeURIComponent(search)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response from fetch_pms_schedule:', text);
                        throw new Error(`JSON parse error: ${e.message}`);
                    }
                });
            })
            .then(data => {
                tbody.innerHTML = '';
                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="6" class="no-results">Error loading data: ' + (data.debug || data.error || 'Unknown error') + '</td></tr>';
                    table.classList.remove('refreshing');
                    return;
                }

                renderTable(data.vehicles, page);

                document.getElementById('pageInfo').innerText = `Page ${page} of ${data.totalPages}`;
                updatePaginationControls(data.totalCount, page);
                table.classList.remove('refreshing');
                attachRowClickListeners(); // Re-attach listeners after table update
            })
            .catch(error => {
                console.error('Error fetching PMS schedule:', error);
                tbody.innerHTML = '<tr><td colspan="6" class="no-results">Error fetching data: ' + error.message + '</td></tr>';
                table.classList.remove('refreshing');
            });
    }

    function renderTable(vehicles, page) {
        const tbody = document.getElementById('pmsScheduleBody');
        if (!tbody) {
            console.error('PMS schedule table body not found');
            return;
        }

        tbody.innerHTML = '';

        if (!Array.isArray(vehicles) || vehicles.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="no-results">No results found</td></tr>';
            console.warn('No vehicles data provided');
            return;
        }

        console.log('Rendering', vehicles.length, 'vehicles', { sample: vehicles[0] });

        const today = serverDate;
        vehicles.forEach((vehicle, index) => {
            const row = document.createElement('tr');
            if (vehicle.source === 'komtrax') {
                row.classList.add('komtrax-vehicle');
            }

            const lastPmsDate = !vehicle.last_pms_date || vehicle.last_pms_date === '0000-00-00' ? 'Not Available' : vehicle.last_pms_date;
            const nextPmsDate = !vehicle.next_pms_date || vehicle.next_pms_date === '0000-00-00' ? 'Not Available' : vehicle.next_pms_date;

            let daysRemaining = 'N/A';
            let pmsStatus = 'OK';

            if (vehicle.last_pms_date && vehicle.next_pms_date && vehicle.next_pms_date !== '0000-00-00') {
                try {
                    const nextPmsDateStr = vehicle.next_pms_date.includes('T') ?
                        vehicle.next_pms_date :
                        `${vehicle.next_pms_date}T00:00:00+08:00`;
                    const nextPmsDateObj = new Date(nextPmsDateStr);
                    if (!isNaN(nextPmsDateObj)) {
                        const timeDiff = nextPmsDateObj.getTime() - today.getTime();
                        daysRemaining = Math.ceil(timeDiff / (1000 * 3600 * 24));

                        if (daysRemaining <= 0) {
                            pmsStatus = 'DUE';
                        } else if (daysRemaining <= 7) {
                            pmsStatus = 'NEARING';
                        }
                    } else {
                        daysRemaining = 'Invalid';
                        pmsStatus = 'ERROR';
                    }
                } catch (e) {
                    console.error(`Error parsing next_pms_date for vehicle ${vehicle.target_name}:`, e);
                    daysRemaining = 'Error';
                    pmsStatus = 'ERROR';
                }
            }

            const safeName = vehicle.target_name ? vehicle.target_name.replace(/[<>]/g, '') : 'N/A';
            const latVal = parseFloat(vehicle.latitude);
            const lngVal = parseFloat(vehicle.longitude);
            const safeLat = !isNaN(latVal) && latVal >= -90 && latVal <= 90 ? latVal.toFixed(6) : '';
            const safeLng = !isNaN(lngVal) && lngVal >= -180 && lngVal <= 180 ? lngVal.toFixed(6) : '';

            console.log(`Vehicle ${index + 1}:`, {
                name: safeName,
                latitude: vehicle.latitude,
                longitude: vehicle.longitude,
                safeLat,
                safeLng,
                source: vehicle.source
            });

            const hasLocation = safeLat && safeLng;
            const iconAttributes = hasLocation 
                ? `class="fas fa-map-pin map-icon" data-lat="${safeLat}" data-lng="${safeLng}" data-name="${safeName}" title="View on Map"`
                : `class="fas fa-map-pin map-icon disabled" title="No location data available"`;

            row.innerHTML = `
                <td>${safeName}</td>
                <td>${vehicle.equipment_type || 'N/A'}</td>
                <td>${lastPmsDate}</td>
                <td>${nextPmsDate}</td>
                <td>${daysRemaining}</td>
                <td>${pmsStatus}</td>
            `;

            row.classList.remove('pms-due', 'pms-nearing');
            if (pmsStatus === 'DUE') {
                row.classList.add('pms-due');
            } else if (pmsStatus === 'NEARING') {
                row.classList.add('pms-nearing');
            }

            tbody.appendChild(row);
            console.log(`Added row ${index + 1}: ${safeName}, hasLocation: ${hasLocation}`);
        });

        // Attach event listeners to map icons
        const mapIcons = document.querySelectorAll('.map-icon');
        console.log(`Found ${mapIcons.length} map icons after rendering`);

        mapIcons.forEach(icon => {
            if (!icon.classList.contains('disabled')) {
                icon.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const lat = icon.getAttribute('data-lat');
                    const lng = icon.getAttribute('data-lng');
                    const name = icon.getAttribute('data-name');
                    console.log('Map icon clicked:', { lat, lng, name });
                    const latNum = parseFloat(lat);
                    const lngNum = parseFloat(lng);
                    if (!isNaN(latNum) && !isNaN(lngNum)) {
                        showVehicleOnMap(latNum, lngNum, name);
                    } else {
                        console.warn('Invalid or missing location data:', { lat, lng, name });
                        alert('Location data not available for this vehicle.');
                    }
                });
            }
        });
    }

    function openAddVehicleModal() {
        const vehicleInfoDiv = document.getElementById('vehicleInfo');
        const editForm = document.getElementById('editForm');
        const modalTitle = document.getElementById('modalTitle');
        const modalButtons = document.getElementById('modalButtons');
        const messageContainer = document.getElementById('messageContainer');
        
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
                    messageContainer.innerHTML = `<div class="message error">Error submitting vehicle addition: ${error.message}</div>`;
                });
            });
        }
        if (cancelAddButton) {
            cancelAddButton.addEventListener('click', closeModal);
        }

        showModal();
    }

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
                    [
                        ...fieldsReadonlySystemBase,
                        'last_assignment', 'last_days_contract', 'last_date_transferred',
                        'last_date_ended', 'last_days_elapsed'
                    ] :
                    [...fieldsReadonlySystemBase, ...komtraxAdditionalReadonly];
                const fieldsReadonlyForm = [...fieldsReadonlySystem, 'next_pms_date'];

                let formHTML = '<form id="editVehicleForm" class="form-container"><div style="display: flex; flex-wrap: wrap; gap: 15px; justify-content: space-between;">';
                formHTML += `<input type="hidden" name="action" value="edit">`;
                displayFields.forEach(field => {
                    if (field === 'id') return;
                    const isReadonly = fieldsReadonlyForm.includes(field);
                    const readonlyAttr = isReadonly ? ' readonly' : '';
                    let inputValue = data[field] ?? '';
                    inputValue = inputValue.toString().replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
                                    ${Object.entries(assignmentDisplayMap).map(([key, value]) => `
                                        <option value="${key}" ${inputValue === key ? 'selected' : ''}>${value}</option>
                                    `).join('')}
                                </select>
                            </div>
                        `;
                    } else if (['days_contract', 'days_elapsed', 'days_lapses', 'days_no_gps', 'last_days_contract', 'last_days_elapsed'].includes(field)) {
                        formHTML += `
                            <div class="form-group">
                                <label for="${field}">${field.toUpperCase().replace(/_/g, ' ')}:</label>
                                <input type="number" name="${field}" id="${field}" value="${field === 'days_contract' ? (data.days_contract || 0) : (field === 'days_elapsed' ? data.days_elapsed : (field === 'days_lapses' ? data.days_lapses : inputValue))}" readonly>
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
                    const now = serverDate;
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

    function updatePaginationControls(totalCount, currentPage) {
        const totalPages = Math.ceil(totalCount / itemsPerPage);
        const prevButton = document.getElementById('prevPage');
        const nextButton = document.getElementById('nextPage');
        if (prevButton) prevButton.disabled = currentPage === 1;
        if (nextButton) nextButton.disabled = currentPage === totalPages || totalPages === 0;
    }

    const prevButton = document.getElementById('prevPage');
    if (prevButton) {
        prevButton.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                loadPmsSchedule(currentPage, filterType, searchQuery);
            }
        });
    }

    const nextButton = document.getElementById('nextPage');
    if (nextButton) {
        nextButton.addEventListener('click', () => {
            currentPage++;
            loadPmsSchedule(currentPage, filterType, searchQuery);
        });
    }

    const equipmentFilter = document.getElementById('equipmentFilter');
    if (equipmentFilter) {
        equipmentFilter.addEventListener('change', function() {
            filterType = this.value;
            currentPage = 1;
            loadPmsSchedule(currentPage, filterType, searchQuery);
        });
    }

    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchQuery = this.value.trim();
            currentPage = 1;
            searchTimeout = setTimeout(() => {
                loadPmsSchedule(currentPage, filterType, searchQuery);
            }, 300);
        });
    }

    function refreshPmsSchedule() {
        if (isPageActive) {
            loadPmsSchedule(currentPage, filterType, searchQuery);
        }
    }
    loadPmsSchedule(currentPage, filterType, searchQuery);
    setInterval(refreshPmsSchedule, refreshInterval);

    document.addEventListener('visibilitychange', () => {
        isPageActive = !document.hidden;
    });

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
    
    function updateRedHighlightCounts(counts) {
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

    function fetchRedHighlightCounts() {
        if (!isPageActive) return;
        fetch('fetch_red_highlight_counts.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response from fetch_red_highlight_counts:', text);
                        throw new Error(`JSON parse error: ${e.message}`);
                    }
                });
            })
            .then(data => updateRedHighlightCounts(data))
            .catch(error => {
                console.error('Error fetching red highlight counts:', error);
                updateRedHighlightCounts({ maintenance: 0, monitoring: 0, geofence: 0 });
            });
    }

    const eventSource = new EventSource('stream_red_highlight_counts.php');
    eventSource.onmessage = function (event) {
        if (!isPageActive) return;
        try {
            const data = JSON.parse(event.data);
            updateRedHighlightCounts(data);
        } catch (e) {
            console.error('Invalid JSON from stream_red_highlight_counts:', event.data);
        }
    };
    eventSource.onerror = function () {
        console.error('SSE error for red highlight counts, falling back to polling');
        eventSource.close();
        setInterval(fetchRedHighlightCounts, 10000);
    };

    window.addEventListener('focus', fetchRedHighlightCounts);
    fetchRedHighlightCounts();
});
</script>

</body>
</html>

<?php
$conn->close();
?>