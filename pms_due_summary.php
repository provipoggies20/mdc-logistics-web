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
$current_date = date('Y-m-d H:i:s');

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
        WHERE equipment_type IS NOT NULL AND date_ended IS NOT NULL
        UNION
        SELECT target_name, assignment, date_transferred, days_contract, date_ended, 'komtrax' as source
        FROM komtrax 
        WHERE equipment_type IS NOT NULL AND date_ended IS NOT NULL
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

        if ($is_overdue && $days_lapses > 0 && $days_elapsed < 5000) {
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
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
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
            position: relative;
            overflow: hidden;
        }
        .table-sub-container h2 {
            font-size: 1.4rem;
            color: #004080;
            margin-bottom: 20px;
            font-weight: 700;
            letter-spacing: 0.5px;
            position: relative;
            z-index: 1;
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
        .no-results {
            text-align: center;
            padding: 20px;
            color: #c62828;
            font-weight: bold;
            font-size: 1.1rem;
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
            margin-top: 25px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        .edit-button, .save-button, .cancel-button {
            background: linear-gradient(135deg, #2196F3, #1976D2);
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
        }
        .edit-button:hover, .save-button:hover, .cancel-button:hover {
            background: linear-gradient(135deg, #1976D2, #1565C0);
            transform: scale(1.05);
            box-shadow: 0 6px 15px rgba(33, 150, 243, 0.6);
        }
        .save-button {
            background: linear-gradient(135deg, #28a745, #218838);
        }
        .save-button:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
        }
        .cancel-button {
            background: linear-gradient(135deg, #6c757d, #5a6268);
        }
        .cancel-button:hover {
            background: linear-gradient(135deg, #5a6268, #4e555b);
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
        .card-container {
    display: flex;
    flex-wrap: nowrap; /* Prevent wrapping for horizontal scrolling */
    gap: 16px;
    padding: 16px;
    background: linear-gradient(135deg, #f8fafc, #e8ecef);
    border-radius: 12px;
    width: 100%;
    overflow-x: auto; /* Enable horizontal scrolling */
    overflow-y: hidden; /* Hide vertical scrollbar */
    scroll-behavior: smooth; /* Smooth scrolling */
    position: relative;
    animation: containerFadeIn 0.5s ease;
    white-space: nowrap; /* Ensure cards stay in a single row */
}

.card-container::-webkit-scrollbar {
    height: 8px; /* Smaller scrollbar for horizontal scroll */
}

.card-container::-webkit-scrollbar-track {
    background: #f0f0f0;
    border-radius: 4px;
}

.card-container::-webkit-scrollbar-thumb {
    background: #007bff;
    border-radius: 4px;
}

.card-container::-webkit-scrollbar-thumb:hover {
    background: #0056b3;
}

.card {
    flex: 0 0 240px; /* Fixed width for cards */
    background: linear-gradient(135deg, #ffffff, #f9f9f9);
    border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    padding: 16px;
    border: 2px solid transparent;
    transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
    position: relative;
    cursor: pointer;
    animation: cardSlideUp 0.4s ease forwards;
    z-index: 1;
    display: inline-block; /* Ensure cards align horizontally */
}

.card:hover {
    transform: translateY(-5px) scale(1.03);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    border-color: #007bff;
}

.card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e0e0e0;
    margin-bottom: 10px;
    position: relative;
}

.card-header i {
    font-size: 1.3rem;
    color: #34495e;
    transition: color 0.3s ease;
}

.card:hover .card-header i {
    color: #007bff;
}

.card-header span {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px; /* Limit header text width */
}

.card-field {
    font-size: 0.95rem;
    margin-bottom: 8px;
    display: flex;
    gap: 8px;
    align-items: flex-start;
}

.card-field label {
    font-weight: 600;
    color: #34495e;
    min-width: 70px;
    flex-shrink: 0;
}

.card-field span {
    color: #2c3e50;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    font-weight: 500;
    max-width: 150px; /* Limit field text width */
}

/* Remove geofence-card specific sizing */
.geofence-card .card-header span,
.geofence-card .card-field span {
    white-space: nowrap;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.status-badge {
    position: absolute;
    top: -10px;
    right: -10px;
    background: #007bff;
    color: white;
    padding: 6px 12px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    transition: transform 0.3s ease;
}

/* Status-specific styles */
.card.due .status-badge {
    background: #d32f2f;
    animation: pulse 2s infinite;
}

.card.nearing .status-badge {
    background: #f57c00;
    animation: glow 2s infinite;
}

.card.overdue .status-badge {
    background: #ad1fa2;
    animation: pulse 2s infinite;
}

.card.outside .status-badge {
    background: #3cf0ae;
    animation: pulse 2s infinite;
}

.card.breakdown .status-badge {
    background: #f57c00;
    animation: glow 2s infinite;
}

.card.inside-geofence .status-badge {
    background: #2e7d32;
}

.card.komtrax-vehicle .status-badge {
    background: #0288d1;
}

.due {
    border-color: #d32f2f;
    background: linear-gradient(135deg, #ffebee, #ffffff);
}

.nearing {
    border-color: #f57c00;
    background: linear-gradient(135deg, #fff3e0, #ffffff);
}

.overdue {
    border-color: #ad1fa2;
    background: linear-gradient(135deg, #ffebee, #ffffff);
}

.breakdown {
    border-color: #f57c00;
    background: linear-gradient(135deg, #fff3e0, #ffffff);
}

.outside {
    border-color: #3cf0ae;
    background: linear-gradient(135deg, #fff3e0, #ffffff);
}

.inside-geofence {
    border-color: #2e7d32;
    background: linear-gradient(135deg, #e8f5e9, #ffffff);
}

.komtrax-vehicle {
    background: linear-gradient(135deg, #e3f2fd, #ffffff);
}

/* Remove card size variations */
.card.card-large,
.card.card-medium,
.card.card-small {
    flex: 0 0 240px; /* Uniform width */
    padding: 16px;
}

.card.card-large .card-field,
.card.card-medium .card-field,
.card.card-small .card-field {
    font-size: 0.95rem;
    margin-bottom: 8px;
}

.card.card-large .card-field label,
.card.card-medium .card-field label,
.card.card-small .card-field label {
    min-width: 70px;
}

.card.card-large.geofence-card .card-field span,
.card.card-medium.geofence-card .card-field span,
.card.card-small.geofence-card .card-field span,
.card.card-large.geofence-card .card-header span,
.card.card-medium.geofence-card .card-header span,
.card.card-small.geofence-card .card-header span {
    max-width: 150px; /* Uniform max-width for geofence cards */
}

/* Responsive adjustments */
@media (max-width: 600px) {
    .card {
        flex: 0 0 200px; /* Smaller card width for mobile */
    }
    .card-field {
        font-size: 0.9rem;
    }
    .card-field label {
        min-width: 60px;
    }
    .card-header span {
        font-size: 1rem;
        max-width: 140px;
    }
    .geofence-card .card-header span,
    .geofence-card .card-field span {
        max-width: 130px;
    }
    .status-badge {
        font-size: 0.75rem;
        padding: 5px 10px;
    }
}
        .card.due .status-badge {
            background: #d32f2f;
            animation: pulse 2s infinite;
        }
        .card.nearing .status-badge {
            background: #f57c00;
            animation: glow 2s infinite;
        }
        .card.overdue .status-badge {
            background: #ad1fa2;
            animation: pulse 2s infinite;
        }
        .card.outside .status-badge {
            background: #3cf0ae;
            animation: pulse 2s infinite;
        }
        .card.breakdown .status-badge {
            background: #f57c00;
            animation: glow 2s infinite;
        }
        .card.inside-geofence .status-badge {
            background: #2e7d32;
        }
        .card.komtrax-vehicle .status-badge {
            background: #0288d1;
        }
        .due {
            border-color: #d32f2f;
            background: linear-gradient(135deg, #ffebee, #ffffff);
        }
        .nearing {
            border-color: #f57c00;
            background: linear-gradient(135deg, #fff3e0, #ffffff);
        }
        .overdue {
            border-color: #ad1fa2;
            background: linear-gradient(135deg, #ffebee, #ffffff);
        }
        .breakdown {
            border-color: #f57c00;
            background: linear-gradient(135deg, #fff3e0, #ffffff);
        }
        .outside {
            border-color: #3cf0ae;
            background: linear-gradient(135deg, #fff3e0, #ffffff);
        }
        .inside-geofence {
            border-color: #2e7d32;
            background: linear-gradient(135deg, #e8f5e9, #ffffff);
        }
        .komtrax-vehicle {
            background: linear-gradient(135deg, #e3f2fd, #ffffff);
        }
        .card-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.8);
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            z-index: 2000;
            width: 400px;
            max-width: 90%;
            opacity: 0;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }
        .card-modal.show {
            display: block;
            transform: translate(-50%, -50%) scale(1);
            opacity: 1;
        }
        .card-modal .card-header {
            font-size: 1.4rem;
            padding-bottom: 12px;
            margin-bottom: 12px;
        }
        .card-modal .card-field {
            font-size: 1.1rem;
            margin-bottom: 12px;
        }
        .card-modal .card-field span {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
        }
        .card-modal .close-modal {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 1.5rem;
            color: #666;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        .card-modal .close-modal:hover {
            color: #000;
        }
        @keyframes cardSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes containerFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        @keyframes glow {
            0% { box-shadow: 0 0 5px rgba(245, 124, 0, 0.5); }
            50% { box-shadow: 0 0 15px rgba(245, 124, 0, 0.8); }
            100% { box-shadow: 0 0 5px rgba(245, 124, 0, 0.5); }
        }
        .pms-contract-row,
        .maintenance-geofence-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .pms-contract-row .table-sub-container,
        .maintenance-geofence-row .table-sub-container {
            flex: 1;
            min-width: 300px;
        }
        @media (max-width: 600px) {
            .card {
                min-width: 100%;
                width: 100%;
            }
            .card.card-large,
            .card.card-medium,
            .card.card-small {
                width: 100%;
                padding: 16px;
            }
            .card-field {
                font-size: 1.05rem;
            }
            .card-field label {
                min-width: 80px;
            }
            .card-header span {
                font-size: 1.1rem;
            }
            .status-badge {
                font-size: 0.9rem;
                padding: 8px 14px;
            }
            .geofence-card .card-header span,
            .geofence-card .card-field span {
                max-width: calc(100% - 40px);
            }
        }
        .chatbot-container {
            transition: opacity 0.3s ease;
        }
        .chatbot-container button:hover {
            background: #0056b3;
        }
        #chat-response {
            background: #f9f9f9;
            color: #333;
        }
        #chat-response p {
            margin: 0;
        }
        #chat-response:empty {
            display: none;
        }
        /* Floating Chat Button */
        .chat-toggle-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            z-index: 1000;
        }
        .chat-toggle-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
        }
        .chat-toggle-btn i {
            font-size: 1.5rem;
        }
        
        /* Chat Pop-Out Window */
        .chat-popup {
            position: fixed;
            bottom: 90px;
            right: 20px;
            width: 350px;
            max-width: 90%;
            background: linear-gradient(135deg, #ffffff, #f8f9fa);
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            display: none;
            flex-direction: column;
            overflow: hidden;
            z-index: 1001;
            animation: slideIn 0.3s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        .chat-popup.show {
            display: flex;
            opacity: 1;
            transform: translateY(0);
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Chat Header */
        .chat-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 12px 16px;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        .chat-header i {
            margin-right: 8px;
        }
        .chat-close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        .chat-close-btn:hover {
            color: #e0e0e0;
        }
        
        /* Chat Messages Area */
        .chat-messages {
            max-height: 300px;
            min-height: 150px;
            overflow-y: auto;
            padding: 16px;
            background: #f9f9f9;
            border-bottom: 1px solid #eee;
        }
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        .chat-messages::-webkit-scrollbar-track {
            background: #f0f0f0;
        }
        .chat-messages::-webkit-scrollbar-thumb {
            background: #007bff;
            border-radius: 3px;
        }
        .message {
            margin-bottom: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            max-width: 80%;
            font-size: 0.95rem;
            line-height: 1.4;
        }
        .message.user {
            background: #007bff;
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 2px;
        }
        .message.bot {
            background: #e9ecef;
            color: #333;
            margin-right: auto;
            border-bottom-left-radius: 2px;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            margin-right: auto;
            border-bottom-left-radius: 2px;
        }
        .message.processing {
            background: #e9ecef;
            color: #666;
            font-style: italic;
            margin-right: auto;
            border-bottom-left-radius: 2px;
        }
        
        /* Chat Input Form */
        .chat-form {
            padding: 12px;
            background: white;
            display: flex;
            gap: 8px;
        }
        .chat-input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
        }
        .chat-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
        }
        .chat-send-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            transition: background 0.3s ease;
        }
        .chat-send-btn:hover {
            background: #0056b3;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 600px) {
            .chat-popup {
                width: 90%;
                bottom: 70px;
                right: 5%;
            }
            .chat-toggle-btn {
                width: 50px;
                height: 50px;
            }
            .chat-messages {
                max-height: 200px;
            }
        }
        .typing span {
            animation: blink 1s infinite;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0; }
        }
    </style>
</head>
<body>
<!-- Toggle Button -->
<button id="toggleSidebarButton" class="sidebar-visible">
    <i class="fas fa-arrow-left"></i>
</button>

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
        <h2><center>Summary</center></h2>
        
        <!-- Chatbot Floating Button -->
        <button class="chat-toggle-btn" id="chat-toggle-btn" title="Open Chatbot">
            <i class="fas fa-comment-dots"></i>
        </button>
        
        <!-- Chatbot Pop-Out Window -->
        <div class="chat-popup" id="chat-popup">
            <div class="chat-header">
                <span><i class="fas fa-robot"></i> Dashboard Assistant</span>
                <button class="chat-close-btn" id="chat-close-btn" title="Close Chat">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="chat-messages" id="chat-messages">
                <!-- Messages will be appended here -->
            </div>
            <form class="chat-form" id="chat-form">
                <input type="text" class="chat-input" id="chat-input" placeholder="Ask about your dashboard..." required>
                <button type="submit" class="chat-send-btn">Send</button>
            </form>
        </div>
     <!-- Fleet Overview -->
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
    <div class="pms-contract-row">
        <div class="table-sub-container">
            <h2>PMS Overdue and Nearing</h2>
            <div id="pmsTableBody" class="card-container">
                <?php if (empty($dueVehicles)): ?>
                    <p class="no-results">All vehicles are up to date with PMS schedules.</p>
                <?php else: ?>
                    <?php foreach ($dueVehicles as $vehicle): ?>
                        <div class="card <?php echo strtolower($vehicle['status']); ?>" data-target-name="<?php echo htmlspecialchars($vehicle['target_name'] ?? 'N/A'); ?>" data-equipment-type="<?php echo htmlspecialchars($vehicle['equipment_type'] ?? 'N/A'); ?>" data-status="<?php echo htmlspecialchars($vehicle['status']); ?>">
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
                            <div class="status-badge"><?php echo htmlspecialchars($vehicle['status']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-sub-container">
            <h2>Contract Monitoring</h2>
            <div id="contractTableBody" class="card-container">
                <?php if (empty($contractVehicles)): ?>
                    <p class="no-results">No overdue contracts detected.</p>
                <?php else: ?>
                    <?php foreach ($contractVehicles as $vehicle): ?>
                        <div class="card <?php echo $vehicle['is_overdue'] ? 'overdue' : ''; ?> <?php echo $vehicle['source'] === 'komtrax' ? 'komtrax-vehicle' : ''; ?>" data-target-name="<?php echo htmlspecialchars($vehicle['target_name'] ?? 'N/A'); ?>" data-assignment="<?php echo htmlspecialchars($vehicle['assignment'] ?? 'N/A'); ?>" data-days-lapses="<?php echo htmlspecialchars($vehicle['days_lapses'] ?? 'N/A'); ?>" data-source="<?php echo htmlspecialchars($vehicle['source']); ?>">
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
                            <div class="status-badge"><?php echo $vehicle['is_overdue'] ? 'Overdue' : 'Active'; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="maintenance-geofence-row">
        <div class="table-sub-container">
            <h2>Maintenance Monitoring</h2>
            <div id="maintenanceTableBody" class="card-container">
                <?php if (empty($maintenanceVehicles)): ?>
                    <p class="no-results">No maintenance issues detected.</p>
                <?php else: ?>
                    <?php foreach ($maintenanceVehicles as $vehicle): ?>
                        <div class="card breakdown <?php echo $vehicle['source'] === 'komtrax' ? 'komtrax-vehicle' : ''; ?>" data-target-name="<?php echo htmlspecialchars($vehicle['target_name'] ?? 'N/A'); ?>" data-equipment-type="<?php echo htmlspecialchars($vehicle['equipment_type'] ?? 'N/A'); ?>" data-physical-status="<?php echo htmlspecialchars($vehicle['physical_status']); ?>" data-source="<?php echo htmlspecialchars($vehicle['source']); ?>">
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
                            <div class="status-badge">Breakdown</div>
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
                        <div class="card geofence-card <?php echo $vehicle['is_inside'] ? 'inside-geofence' : 'outside'; ?> <?php echo $vehicle['source'] === 'komtrax' ? 'komtrax-vehicle' : ''; ?>" data-target-name="<?php echo htmlspecialchars($vehicle['target_name'] ?? 'N/A'); ?>" data-assignment="<?php echo htmlspecialchars($vehicle['assignment'] ?? 'N/A'); ?>" data-status="<?php echo htmlspecialchars($vehicle['status'] ?? 'N/A'); ?>" data-source="<?php echo htmlspecialchars($vehicle['source']); ?>">
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
                                <span><?php echo $vehicle['is_inside'] ? htmlspecialchars($vehicle['status'] ?? 'N/A') : 'Outside Geofence'; ?></span>
                            </div>
                            <div class="status-badge"><?php echo $vehicle['is_inside'] ? 'Inside' : 'Outside'; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div id="cardModal" class="card-modal">
        <span class="close-modal">×</span>
        <div class="card-header">
            <i id="modalIcon"></i>
            <span id="modalTargetName"></span>
        </div>
        <div id="modalFields"></div>
    </div>
</div>
<script>
    let isPageActive = true;

    function toggleSettings() {
        document.getElementById("settings-menu").classList.toggle("active");
    }

    document.addEventListener("DOMContentLoaded", () => {
        console.log("DOM loaded, rendering stylish cards");

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

        // Red highlight counts for other sections
        function updateRedHighlightCounts(counts) {
            const maintenanceNav = document.querySelector('.sidebar-nav[data-href="dashboard.php"]');
            if (maintenanceNav) {
                maintenanceNav.classList.toggle('highlight', (counts.maintenance || 0) > 0);
            }            
            console.log("Red highlight counts:", counts);
            ['monitoring', 'geofence', 'pending_edits'].forEach(key => {
                const nav = document.querySelector(`.sidebar-nav[data-href="${key}.php"]`);
                if (nav) {
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
        }

        function updateTable(containerId, data, template, noResultsMsg) {
    const container = document.getElementById(containerId);
    const parent = container.closest('.table-sub-container');
    console.log(`${containerId} data:`, data);
    if (data.length) {
        container.innerHTML = data.map(template).join('');
        container.style.display = 'flex';
        parent.querySelector('.no-results')?.remove();
    } else {
        container.innerHTML = `<p class="no-results">${noResultsMsg}</p>`;
        container.style.display = 'flex';
    }
    // Attach click event listeners to cards
    const cards = container.querySelectorAll('.card');
    cards.forEach(card => {
        card.addEventListener('click', showCardModal);
    });
    // Check PMS table for maintenance highlight
    if (containerId === 'pmsTableBody') {
        const maintenanceNav = document.querySelector('.sidebar-nav[data-href="dashboard.php"]');
        if (maintenanceNav) {
            const hasCards = container.querySelector('.card') !== null;
            maintenanceNav.classList.toggle('highlight', hasCards);
        }
    }
}

        function updatePmsTable(data) {
            updateTable('pmsTableBody', data, vehicle => `
                <div class="card ${vehicle.status.toLowerCase()}" data-target-name="${vehicle.target_name || 'N/A'}" data-equipment-type="${vehicle.equipment_type || 'N/A'}" data-status="${vehicle.status}">
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
                    <div class="status-badge">${vehicle.status}</div>
                </div>
            `, 'All vehicles are up to date with PMS schedules.');
        }

        function updateContractTable(data) {
            updateTable('contractTableBody', data, vehicle => `
                <div class="card ${vehicle.is_overdue ? 'overdue' : ''} ${vehicle.source === 'komtrax' ? 'komtrax-vehicle' : ''}" data-target-name="${vehicle.target_name || 'N/A'}" data-assignment="${vehicle.assignment || 'N/A'}" data-days-lapses="${vehicle.days_lapses || 'N/A'}" data-source="${vehicle.source}">
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
                    <div class="status-badge">${vehicle.is_overdue ? 'Overdue' : 'Active'}</div>
                </div>
            `, 'No overdue contracts detected.');
        }

        function updateMaintenanceTable(data) {
            updateTable('maintenanceTableBody', data, vehicle => `
                <div class="card breakdown ${vehicle.source === 'komtrax' ? 'komtrax-vehicle' : ''}" data-target-name="${vehicle.target_name || 'N/A'}" data-equipment-type="${vehicle.equipment_type || 'N/A'}" data-physical-status="${vehicle.physical_status || 'N/A'}" data-source="${vehicle.source}">
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
                    <div class="status-badge">Breakdown</div>
                </div>
            `, 'No maintenance issues detected.');
        }

        function updateGeofenceTable(data) {
            updateTable('geofenceTableBody', data, vehicle => `
                <div class="card geofence-card ${vehicle.is_inside ? 'inside-geofence' : 'outside'} ${vehicle.source === 'komtrax' ? 'komtrax-vehicle' : ''}" data-target-name="${vehicle.target_name || 'N/A'}" data-assignment="${vehicle.assignment || 'N/A'}" data-status="${vehicle.status || 'N/A'}" data-source="${vehicle.source}">
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
                        <span>${vehicle.is_inside ? vehicle.status || 'N/A' : 'Outside Geofence'}</span>
                    </div>
                    <div class="status-badge">${vehicle.is_inside ? 'Inside' : 'Outside'}</div>
                </div>
            `, 'No geofence status issues detected.');
        }

        // Card Modal Functionality
        const modal = document.getElementById('cardModal');
        const modalIcon = document.getElementById('modalIcon');
        const modalTargetName = document.getElementById('modalTargetName');
        const modalFields = document.getElementById('modalFields');
        const closeModal = document.querySelector('.close-modal');

        function showCardModal(event) {
            const card = event.currentTarget;
            const dataset = card.dataset;
            let iconClass, fieldsHTML;

            if (card.closest('#pmsTableBody')) {
                iconClass = 'fas fa-tools';
                fieldsHTML = `
                    <div class="card-field">
                        <label>Type:</label>
                        <span>${dataset.equipmentType}</span>
                    </div>
                    <div class="card-field">
                        <label>Status:</label>
                        <span>${dataset.status}</span>
                    </div>
                `;
            } else if (card.closest('#contractTableBody')) {
                iconClass = 'fas fa-file-contract';
                fieldsHTML = `
                    <div class="card-field">
                        <label>Assignment:</label>
                        <span>${dataset.assignment}</span>
                    </div>
                    <div class="card-field">
                        <label>Days Lapsed:</label>
                        <span>${dataset.daysLapses}</span>
                    </div>
                    <div class="card-field">
                        <label>Source:</label>
                        <span>${dataset.source}</span>
                    </div>
                `;
            } else if (card.closest('#maintenanceTableBody')) {
                iconClass = 'fas fa-wrench';
                fieldsHTML = `
                    <div class="card-field">
                        <label>Type:</label>
                        <span>${dataset.equipmentType}</span>
                    </div>
                    <div class="card-field">
                        <label>Status:</label>
                        <span>${dataset.physicalStatus}</span>
                    </div>
                    <div class="card-field">
                        <label>Source:</label>
                        <span>${dataset.source}</span>
                    </div>
                `;
            } else if (card.closest('#geofenceTableBody')) {
                iconClass = 'fas fa-map-marker-alt';
                fieldsHTML = `
                    <div class="card-field">
                        <label>Assignment:</label>
                        <span>${dataset.assignment}</span>
                    </div>
                    <div class="card-field">
                        <label>Status:</label>
                        <span>${dataset.status}</span>
                    </div>
                    <div class="card-field">
                        <label>Source:</label>
                        <span>${dataset.source}</span>
                    </div>
                `;
            }

            modalIcon.className = iconClass;
            modalTargetName.textContent = dataset.targetName;
            modalFields.innerHTML = fieldsHTML;
            modal.classList.add('show');
        }

        closeModal.addEventListener('click', () => {
            modal.classList.remove('show');
        });

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target === modal) {
                modal.classList.remove('show');
            }
        });

        // Attach initial click listeners
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('click', showCardModal);
        });

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

        // SSE for PMS due
        const pmsSource = new EventSource('stream_pms_due.php');
        pmsSource.onmessage = event => {
            if (!isPageActive) return;
            try {
                const data = JSON.parse(event.data);
                if (data.success) updatePmsTable(data.dueVehicles);
                else console.error('SSE PMS error:', data.error);
            } catch (e) {
                console.error('SSE PMS parse error:', event.data);
            }
        };
        pmsSource.onerror = () => {
            console.error('SSE PMS error, polling...');
            pmsSource.close();
            setInterval(() => {
                if (!isPageActive) return;
                fetch('fetch_pms_due.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) updatePmsTable(data.dueVehicles);
                        else console.error('Fetch PMS error:', data.error);
                    })
                    .catch(error => console.error('Fetch PMS failed:', error));
            }, 10000);
        };

        // SSE for contract monitoring
        const contractSource = new EventSource('stream_contract_monitoring.php');
        contractSource.onmessage = event => {
            if (!isPageActive) return;
            try {
                const data = JSON.parse(event.data);
                if (data.success) updateContractTable(data.contractVehicles);
                else console.error('SSE contract error:', data.error);
            } catch (e) {
                console.error('SSE contract parse error:', event.data);
            }
        };
        contractSource.onerror = () => {
            console.error('SSE contract error, polling...');
            contractSource.close();
            setInterval(() => {
                if (!isPageActive) return;
                fetch('fetch_contract_monitoring.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) updateContractTable(data.contractVehicles);
                        else console.error('Fetch contract error:', data.error);
                    })
                    .catch(error => console.error('Fetch contract failed:', error));
            }, 10000);
        };

        // SSE for maintenance monitoring
        const maintenanceSource = new EventSource('stream_maintenance_monitoring.php');
        maintenanceSource.onmessage = event => {
            if (!isPageActive) return;
            try {
                const data = JSON.parse(event.data);
                if (data.success) updateMaintenanceTable(data.maintenanceVehicles);
                else console.error('SSE maintenance error:', data.error);
            } catch (e) {
                console.error('SSE maintenance parse error:', event.data);
            }
        };
        maintenanceSource.onerror = () => {
            console.error('SSE maintenance error, polling...');
            maintenanceSource.close();
            setInterval(() => {
                if (!isPageActive) return;
                fetch('fetch_maintenance_monitoring.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) updateMaintenanceTable(data.maintenanceVehicles);
                        else console.error('Fetch maintenance error:', data.error);
                    })
                    .catch(error => console.error('Fetch maintenance failed:', error));
            }, 10000);
        };

        // SSE for geofence
        const geofenceSource = new EventSource('stream_geofence.php');
        geofenceSource.onmessage = event => {
            if (!isPageActive) return;
            try {
                const data = JSON.parse(event.data);
                if (data.success) updateGeofenceTable(data.geofenceVehicles);
                else console.error('SSE geofence error:', data.error);
            } catch (e) {
                console.error('SSE geofence parse error:', event.data);
            }
        };
        geofenceSource.onerror = () => {
            console.error('SSE geofence error, polling...');
            geofenceSource.close();
            setInterval(() => {
                if (!isPageActive) return;
                fetch('fetch_geofence.php')
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) updateGeofenceTable(data.geofenceVehicles);
                        else console.error('Fetch geofence error:', data.error);
                    })
                    .catch(error => console.error('Fetch geofence failed:', error));
            }, 10000);
        };

        // Page visibility
        document.addEventListener('visibilitychange', () => {
            isPageActive = !document.hidden;
        });

        // Initial updates
        console.log("Starting stylish card updates");
        fetchRedHighlightCounts();

// Chatbot Functionality
const chatToggleBtn = document.getElementById('chat-toggle-btn');
const chatPopup = document.getElementById('chat-popup');
const chatCloseBtn = document.getElementById('chat-close-btn');
const chatForm = document.getElementById('chat-form');
const chatInput = document.getElementById('chat-input');
const chatMessages = document.getElementById('chat-messages');

// Initialize sessionId
let sessionId = localStorage.getItem("chatSessionId");
if (!sessionId) {
    sessionId = Math.random().toString(36).slice(2);
    localStorage.setItem("chatSessionId", sessionId);
}

// Toggle Chat Popup
chatToggleBtn.addEventListener('click', () => {
    chatPopup.classList.toggle('show');
    if (chatPopup.classList.contains('show')) {
        chatInput.focus();
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
});

// Close Chat Popup
chatCloseBtn.addEventListener('click', () => {
    chatPopup.classList.remove('show');
});

// Close on outside click
document.addEventListener('click', (e) => {
    if (!chatPopup.contains(e.target) && !chatToggleBtn.contains(e.target) && chatPopup.classList.contains('show')) {
        chatPopup.classList.remove('show');
        e.stopPropagation();
    }
});

// Add Message to Chat
function addMessage(content, type = 'bot') {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${type}`;
    if (type === 'processing') {
        messageDiv.innerHTML = '<span class="typing">Typing<span>.</span><span>.</span><span>.</span></span>';
        messageDiv.querySelectorAll('.typing span').forEach((span, i) => {
            span.style.animation = `blink ${0.5 + i * 0.2}s infinite`;
        });
    } else {
        messageDiv.textContent = content;
    }
    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// Chat Form Submission
let debounceTimeout;
chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = chatInput.value.trim();
    if (!message) return;

    clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(async () => {
        addMessage(message, 'user');
        chatInput.value = '';
        addMessage('Processing...', 'processing');

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000);
            const response = await fetch('handle_chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Session-ID': sessionId
                },
                body: JSON.stringify({ message })
            });
            clearTimeout(timeoutId);

            chatMessages.querySelector('.message.processing')?.remove();

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }
            const data = await response.json();
            if (data.success) {
                addMessage(data.response, 'bot');
            } else {
                addMessage(`Error: ${data.error}`, 'error');
            }
        } catch (error) {
            console.error('Chat request failed:', error);
            chatMessages.querySelector('.message.processing')?.remove();
            addMessage(`Error: Failed to connect to AI service - ${error.message}`, 'error');
        }
    }, 300); // 300ms debounce
});
});
</script>
</body>
</html>