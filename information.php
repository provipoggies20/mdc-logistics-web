<?php
session_start();
require 'db_connect.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Assignment mapping for PHP
$assignmentMap = [
    'assignment_amlan' => 'Amlan',
    'assignment_balingueo' => 'Balingueo SS',
    'assignment_banilad' => 'Banilad SS',
    'assignment_barotac' => 'Barotac Viejo SS',
    'assignment_bayombong' => 'Bayombong SS',
    'assignment_binan' => 'Binan SS',
    'assignment_bolo' => 'Bolo',
    'assignment_botolan' => 'Botolan SS',
    'assignment_cadiz' => 'Cadiz SS',
    'assignment_calacass' => 'Calaca SS',
    'assignment_calacatl' => 'Calaca TL',
    'assignment_calatrava' => 'Calatrava SS',
    'assignment_castillejos' => 'Castillejos TL',
    'assignment_dasmarinas' => 'Dasmarinas SS',
    'assignment_dumanjug' => 'Dumanjug SS',
    'assignment_ebmagalona' => 'EB Magalona SS',
    'assignment_headoffice' => 'Head Office',
    'assignment_hermosatl' => 'Hermosa TL',
    'assignment_hermosa' => 'Hermosa SS',
    'assignment_ilijan' => 'Ilijan SS',
    'assignment_isabel' => 'Isabel SS',
    'assignment_maasin' => 'Maasin SS',
    'assignment_muntinlupa' => 'Muntinlupa SS',
    'assignment_pantabangan' => 'Pantabangan SS',
    'assignment_pinamucan' => 'Pinamucan SS',
    'assignment_quirino' => 'Quirino',
    'assignment_sanjose' => 'San Jose SS',
    'assignment_tabango' => 'Tabango SS',
    'assignment_tayabas' => 'Tayabas SS',
    'assignment_taytay' => 'Taytay SS',
    'assignment_terrasolar' => 'Terra Solar',
    'assignment_tuguegarao' => 'Tuguegarao SS',
    'assignment_tuy' => 'Tuy SS'
];

// Get columns for devices and komtrax
$devicesColumns = [];
$komtraxColumns = [];
$allColumns = [];

$devicesQuery = "SHOW COLUMNS FROM devices";
$devicesResult = $conn->query($devicesQuery);
if ($devicesResult) {
    while ($column = $devicesResult->fetch_assoc()) {
        $devicesColumns[] = $column['Field'];
        if (!in_array($column['Field'], $allColumns)) {
            $allColumns[] = $column['Field'];
        }
    }
} else {
    die("Devices columns query failed: " . $conn->error);
}

$komtraxQuery = "SHOW COLUMNS FROM komtrax";
$komtraxResult = $conn->query($komtraxQuery);
if ($komtraxResult) {
    while ($column = $komtraxResult->fetch_assoc()) {
        $komtraxColumns[] = $column['Field'];
        if (!in_array($column['Field'], $allColumns)) {
            $allColumns[] = $column['Field'];
        }
    }
} else {
    die("Komtrax columns query failed: " . $conn->error);
}

// Add source_table and assumed columns if not present
$allColumns[] = 'source_table';
if (!in_array('requested_by', $allColumns)) $allColumns[] = 'requested_by';
if (!in_array('license_plate_no', $allColumns)) $allColumns[] = 'license_plate_no';

// Define desired column order
$orderedColumns = [
    'id',
    'equipment_type',
    'target_name',
    'assignment',
    'physical_status',
    'date_transferred',
    'date_ended',
    'days_elapsed',
    'last_pms_date',
    'next_pms_date',
    'requested_by',
    'cut_address',
    'address',
    'license_plate_no'
];

// Add remaining columns (excluding already ordered ones)
$remainingColumns = array_diff($allColumns, $orderedColumns, ['days_elapsed', 'days_lapses']);
$displayColumns = array_merge($orderedColumns, $remainingColumns, ['days_contract', 'days_lapses']);

// Prepare UNION ALL query with simplified search
$whereClauseDevices = "";
$whereClauseKomtrax = "";
$searchParams = [];

$searchFields = ['target_name', 'equipment_type', 'assignment', 'physical_status', 'address', 'cut_address', 'requested_by'];

if (!empty($_GET['search_term'])) {
    $searchTerm = trim($conn->real_escape_string($_GET['search_term']));
    $conditionsDevices = [];
    $conditionsKomtrax = [];
    
    foreach ($searchFields as $field) {
        if (in_array($field, $devicesColumns) || $field === 'requested_by') {
            $conditionsDevices[] = "LOWER(`$field`) LIKE LOWER(?)";
        }
        if (in_array($field, $komtraxColumns) || $field === 'requested_by') {
            $conditionsKomtrax[] = "LOWER(`$field`) LIKE LOWER(?)";
        }
    }
    
    if ($conditionsDevices) {
        $whereClauseDevices = " WHERE (" . implode(" OR ", $conditionsDevices) . ")";
        $searchParams = array_fill(0, count($conditionsDevices), "%$searchTerm%");
    }
    if ($conditionsKomtrax) {
        $whereClauseKomtrax = " WHERE (" . implode(" OR ", $conditionsKomtrax) . ")";
        $searchParams = array_merge($searchParams, array_fill(0, count($conditionsKomtrax), "%$searchTerm%"));
    }
}

// Build SELECT clauses
$devicesSelect = [];
$komtraxSelect = [];
foreach ($displayColumns as $col) {
    if ($col === 'source_table') {
        $devicesSelect[] = "'devices' AS source_table";
        $komtraxSelect[] = "'komtrax' AS source_table";
    } elseif ($col === 'days_elapsed') {
        $devicesSelect[] = "DATEDIFF(CURDATE(), date_transferred) AS days_elapsed";
        $komtraxSelect[] = "DATEDIFF(CURDATE(), date_transferred) AS days_elapsed";
    } elseif ($col === 'days_lapses') {
        $devicesSelect[] = "GREATEST(0, DATEDIFF(CURDATE(), date_ended)) AS days_lapses";
        $komtraxSelect[] = "GREATEST(0, DATEDIFF(CURDATE(), date_ended)) AS days_lapses";
    } else {
        $devicesSelect[] = in_array($col, $devicesColumns) ? "`$col`" : "NULL AS `$col`";
        $komtraxSelect[] = in_array($col, $komtraxColumns) ? "`$col`" : "NULL AS `$col`";
    }
}

$devicesSql = "SELECT " . implode(", ", $devicesSelect) . " FROM devices" . $whereClauseDevices;
$komtraxSql = "SELECT " . implode(", ", $komtraxSelect) . " FROM komtrax" . $whereClauseKomtrax;
$sql = "($devicesSql) UNION ALL ($komtraxSql)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

if ($searchParams) {
    $types = str_repeat("s", count($searchParams));
    $stmt->bind_param($types, ...$searchParams);
}

$stmt->execute();
$result = $stmt->get_result();

// Fetch overdue vehicles for notifications
$overdueVehicles = [];
$overdueQuery = "SELECT target_name, 
                        DATEDIFF(CURDATE(), date_transferred) AS days_elapsed, 
                        days_contract, 
                        GREATEST(0, DATEDIFF(CURDATE(), date_ended)) AS days_lapses, 
                        'devices' AS source_table 
                 FROM devices 
                 WHERE date_ended IS NOT NULL AND DATEDIFF(CURDATE(), date_ended) > 0
                 UNION ALL
                 SELECT target_name, 
                        DATEDIFF(CURDATE(), date_transferred) AS days_elapsed, 
                        days_contract, 
                        GREATEST(0, DATEDIFF(CURDATE(), date_ended)) AS days_lapses, 
                        'komtrax' AS source_table 
                 FROM komtrax 
                 WHERE date_ended IS NOT NULL AND DATEDIFF(CURDATE(), date_ended) > 0";
$overdueResult = $conn->query($overdueQuery);

if ($overdueResult) {
    while ($row = $overdueResult->fetch_assoc()) {
        $overdueVehicles[] = $row;
    }
} else {
    die("Overdue query failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device Information</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        body { 
            font-family: 'Roboto', Arial, sans-serif; 
            background-color: #f4f4f4; 
            margin: 0; 
            padding: 20px; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
        }
        
        h2 {
            color: #007bff;
            margin-bottom: 15px;
            font-weight: 500;
        }

        .search-container {
            width: 100%;
            max-width: 95vw;
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
        }

        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 600px;
        }

        .search-form input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            background-color: #f9f9f9;
            transition: border-color 0.3s;
        }

        .search-form input:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 5px rgba(0,123,255,0.5);
            background-color: #fff;
        }

        .search-form button {
            padding: 10px 20px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease-in-out;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
        }

        .search-form button:hover {
            background: linear-gradient(135deg, #0056b3, #003d80);
            transform: scale(1.05);
        }

        .table-container {
            width: 100%;
            max-width: 95vw;
            max-height: 700px;
            overflow-x: auto;
            overflow-y: auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            padding: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th, td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.9rem;
        }

        th {
            background-color: #007bff;
            color: white;
            position: sticky;
            top: 0;
            z-index: 2;
            font-weight: 500;
            cursor: pointer;
        }

        th:hover {
            background-color: #0056b3;
        }

        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tbody tr:hover {
            background-color: #e8f0fe;
            cursor: pointer;
        }

        tbody tr.overdue {
            border-left: 4px solid #ff9800;
        }

        td {
            color: #333;
        }

        td em {
            color: #777;
            font-style: italic;
        }

        .btn-container {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 20px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease-in-out;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
        }

        .back-button {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            border: none;
        }

        .back-button:hover {
            background: linear-gradient(135deg, #1e7e34, #155d27);
            transform: scale(1.05);
        }

        .pending-button {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: white;
            border: none;
        }

        .pending-button:hover {
            background: linear-gradient(135deg, #e0a800, #c69500);
            transform: scale(1.05);
        }

        #notificationWrapper {
            position: fixed;
            top: 20px;
            right: 30px;
            z-index: 1000;
        }

        #notificationIcon {
            cursor: pointer;
            position: relative;
            font-size: 24px;
        }

        #notificationCount {
            position: absolute;
            top: -10px;
            right: -10px;
            background-color: #dc3545;
            color: white;
            font-size: 10px;
            padding: 3px 6px;
            border-radius: 50%;
            display: none;
        }

        #notificationDropdown {
            display: none;
            position: absolute;
            top: 25px;
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
        }

        #notificationDropdown a {
            display: block;
            padding: 10px;
            text-decoration: none;
            color: #333;
        }

        #notificationDropdown a.unread {
            font-weight: bold;
            border-left: 3px solid #ff4757;
            background-color: #fff5f5;
        }

        #notificationDropdown a.read {
            color: #666;
        }

        #notificationDropdown a:hover {
            background-color: #f0f0f0;
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
            display: inline-block;
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

            .table-container {
                overflow-x: auto;
            }

            th, td {
                font-size: 0.8rem;
                padding: 10px;
            }

            .search-form {
                flex-direction: column;
                padding: 10px;
            }

            .search-form input {
                width: 100%;
            }

            .search-form button {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<!-- Notification Bell Icon (Main Admin Only) -->
<?php if ($_SESSION['role'] === 'Main Admin') : ?>
<div id="notificationWrapper">
    <div id="notificationIcon">
        <i class="fas fa-bell"></i>
        <span id="notificationCount">0</span>
    </div>
    <div id="notificationDropdown"></div>
</div>
<?php endif; ?>

<h2>Device Information</h2>

<!-- Search Form -->
<div class="search-container">
    <form class="search-form" method="GET" action="information.php">
        <input type="text" name="search_term" id="search_term" autocomplete="off" placeholder="Enter vehicle name, equipment type, etc." value="<?php echo isset($_GET['search_term']) ? htmlspecialchars($_GET['search_term']) : ''; ?>">
        <button type="submit">Search</button>
    </form>
</div>

<div class="btn-container">
    <a href="dashboard.php" class="btn back-button">🏠 Home</a>
    <?php if ($_SESSION['role'] === 'Main Admin'): ?>
        <a href="pending_edits.php" class="btn pending-button">📋 Pending Edits</a>
    <?php endif; ?>
    <?php if ($_SESSION['role'] !== 'User') : ?>
        <div class="filter-section" style="margin: 0;">
            <button id="addVehicleButton" class="edit-button">Add Vehicle</button>
        </div>
    <?php endif; ?>
</div>

<div class="table-container">
    <table id="vehicleTable">
        <thead>
            <tr>
                <?php
                // Define display names
                $columnDisplayNames = [
                    'id' => ['ID'],
                    'equipment_type' => ['Equipment Type'],
                    'target_name' => ['Vehicle Name'],
                    'assignment' => ['Assignment'],
                    'physical_status' => ['Status'],
                    'date_transferred' => ['Transfer Date'],
                    'date_ended' => ['End Date'],
                    'days_elapsed' => ['Days Elapsed'],
                    'last_pms_date' => ['Last PMS'],
                    'next_pms_date' => ['Next PMS'],
                    'requested_by' => ['Requested By'],
                    'cut_address' => ['Cut Address'],
                    'address' => ['Address'],
                    'license_plate_no' => ['License Plate No'],
                    'days_contract' => ['Contract Days'],
                    'days_lapses' => ['Days Overdue'],
                    'last_updated' => ['Last Updated'],
                    'position_time' => ['Position Time'],
                    'latitude' => ['Latitude'],
                    'longitude' => ['Longitude'],
                    'speed' => ['Speed'],
                    'direction' => ['Direction'],
                    'total_mileage' => ['Mileage'],
                    'status' => ['GPS Status'],
                    'type' => ['Vehicle Type'],
                    'speed_limit' => ['Speed Limit'],
                    'days_no_gps' => ['No GPS Days'],
                    'pms_interval' => ['PMS Interval'],
                    'tag' => ['Tag'],
                    'specs' => ['Specs'],
                    'last_assignment' => ['Last Assignment'],
                    'last_days_contract' => ['Last Contract Days'],
                    'last_date_transferred' => ['Last Transfer'],
                    'last_date_ended' => ['Last End Date'],
                    'last_days_elapsed' => ['Last Days Elapsed'],
                    'remarks' => ['Remarks'],
                    'source_table' => ['Source']
                ];

                foreach ($displayColumns as $column) {
                    $displayName = $columnDisplayNames[$column][0] ?? str_replace('_', ' ', ucwords($column));
                    echo "<th data-column='$column'>$displayName</th>";
                }
                ?>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): 
                $daysLapses = (int)($row['days_lapses'] ?? 0);
                $rowClass = $daysLapses > 0 ? 'overdue' : '';
            ?>
                <tr class="<?php echo $rowClass; ?>">
                    <?php foreach ($displayColumns as $column): ?>
                        <td data-column="<?php echo $column; ?>">
                            <?php 
                            $value = $row[$column] ?? '';
                            if ($column === 'assignment') {
                                $value = isset($assignmentMap[$value]) ? $assignmentMap[$value] : ($value === '' ? '<em>N/A</em>' : htmlspecialchars($value));
                            } else if ($column === 'last_assignment') {
                                $value = isset($assignmentMap[$value]) ? $assignmentMap[$value] : ($value === '' ? '<em>N/A</em>' : htmlspecialchars($value));
                            }
                            else {
                                $value = $value === '0000-00-00' || $value === '' ? '<em>N/A</em>' : htmlspecialchars($value);
                            }
                            echo $value;
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- Modal Popup for Vehicle Information and Editing -->
<div id="vehicleModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">×</span>
        <h3 id="modalTitle">Vehicle Information</h3>
        <div id="messageContainer"></div>
        <div id="vehicleInfo"></div>
        <div id="editForm" style="display: none;"></div>
        <div class="modal-buttons" id="modalButtons">
            <?php if ($_SESSION['role'] !== 'User') : ?>
                <button id="editButton" class="edit-button">Edit</button>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Main Admin') : ?>
                <button id="deleteButton" class="delete-button">Delete</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Assignment mapping for JavaScript
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

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('vehicleModal');
        if (event.target === modal) {
            closeModal();
        }
    }

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

    function fetchVehicleInfo(targetName) {
        targetName = targetName.trim();
        console.log('Fetching vehicle info for target_name:', targetName);
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
                    const currentDate = new Date('2025-07-13T23:07:00-07:00'); // Updated to July 13, 2025, 11:07 PM PST
                    let daysElapsed = 0;
                    let daysLapses = 0;

                    if (data.date_transferred) {
                        try {
                            const dateTransferred = new Date(data.date_transferred);
                            const diffTime = currentDate.getTime() - dateTransferred.getTime();
                            daysElapsed = Math.floor(diffTime / (1000 * 60 * 60 * 24));
                        } catch (e) {
                            console.error("Error calculating days_elapsed:", e);
                        }
                    }

                    if (data.date_ended) {
                        try {
                            const dateEnded = new Date(data.date_ended);
                            const diffTime = currentDate.getTime() - dateEnded.getTime();
                            daysLapses = Math.max(0, Math.floor(diffTime / (1000 * 60 * 60 * 24)));
                        } catch (e) {
                            console.error("Error calculating days_lapses:", e);
                        }
                    }

                    data.days_elapsed = daysElapsed;
                    data.days_lapses = daysLapses;
                    data.source_table = data.source;

                    const displayFields = [
                        'id', 'target_name', 'equipment_type', 'physical_status', 'assignment',
                        'date_transferred', 'days_contract', 'days_elapsed', 'days_lapses', 'date_ended',
                        'last_updated', 'position_time', 'address', 'latitude', 'longitude', 'speed',
                        'direction', 'total_mileage', 'status', 'type', 'speed_limit', 'days_no_gps',
                        'last_pms_date', 'next_pms_date', 'pms_interval', 'tag', 'specs', 'cut_address',
                        'last_assignment', 'last_days_contract', 'last_date_transferred', 'last_date_ended',
                        'last_days_elapsed', 'requested_by'
                    ];

                    let formHTML = '<div class="info-group">';
                    displayFields.forEach(col => {
                        let value = data[col] ?? '';
                        if (value === '0000-00-00' || value === '') {
                            value = '<em>N/A</em>';
                        } else {
                            if (col === 'assignment' || col === 'last_assignment') {
                                value = assignmentMap[value] || value;
                            }
                            value = value.toString().replace(/</g, '<').replace(/>/g, '>');
                        }
                        formHTML += `
                            <div class="info-item">
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
                displayFields.forEach(field => {
                    if (field === 'id') return;
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
                                    ${Object.entries(assignmentMap).map(([key, value]) => 
                                        `<option value="${key}" ${inputValue === key ? 'selected' : ''}>${value}</option>`
                                    ).join('')}
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
                    const now = new Date('2025-07-13T23:07:00-07:00'); // Updated to July 13, 2025, 11:07 PM PST
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

    document.querySelectorAll('tbody tr').forEach(row => {
        row.addEventListener('click', () => {
            const targetName = row.cells[2].textContent.trim();
            console.log('Clicked row with target_name:', targetName);
            fetchVehicleInfo(targetName);
        });
    });

    const table = document.getElementById('vehicleTable');
    const headers = table.querySelectorAll('th');
    let sortDirection = {};

    headers.forEach(header => {
        const column = header.dataset.column;
        if (['target_name', 'days_elapsed', 'days_contract'].includes(column)) {
            header.addEventListener('click', () => {
                sortTable(column);
            });
        }
    });

    function sortTable(column) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const isNumeric = ['days_elapsed', 'days_contract'].includes(column);
        sortDirection[column] = !sortDirection[column];

        rows.sort((a, b) => {
            let aValue = a.querySelector(`td[data-column="${column}"]`).textContent;
            let bValue = b.querySelector(`td[data-column="${column}"]`).textContent;

            if (aValue === 'N/A') aValue = isNumeric ? -Infinity : '';
            if (bValue === 'N/A') bValue = isNumeric ? -Infinity : '';

            if (isNumeric) {
                aValue = parseFloat(aValue) || 0;
                bValue = parseFloat(bValue) || 0;
                return sortDirection[column] ? aValue - bValue : bValue - aValue;
            } else {
                return sortDirection[column] ?
                    aValue.localeCompare(bValue) :
                    bValue.localeCompare(aValue);
            }
        });

        tbody.innerHTML = '';
        rows.forEach(row => tbody.appendChild(row));
    }

    const addVehicleButton = document.getElementById('addVehicleButton');
    if (addVehicleButton) {
        addVehicleButton.addEventListener('click', () => {
            openAddVehicleModal();
        });
    }

    const userRole = '<?php echo htmlspecialchars($_SESSION['role'] ?? 'User'); ?>';
    function openAddVehicleModal() {
        const vehicleInfoDiv = document.getElementById('vehicleInfo');
        const editForm = document.getElementById('editForm');
        const modalTitle = document.getElementById('modalTitle');
        const modalButtons = document.getElementById('modalButtons');
        const messageContainer = document.getElementById('messageContainer');

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
            } else if (field === 'assignment') {
                inputType = 'select';
                requiredAttr = ' required';
                options = `
                    <option value="">Select Assignment...</option>
                    ${Object.entries(assignmentMap).map(([key, value]) => 
                        `<option value="${key}">${value}</option>`
                    ).join('')}
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
            const now = new Date('2025-07-13T23:07:00-07:00'); // Updated to July 13, 2025, 11:07 PM PST
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
                    messageContainer.innerHTML = '<div class="message error">Error submitting vehicle addition: ' + error.message + '</div>';
                });
            });
        }
        if (cancelAddButton) {
            cancelAddButton.addEventListener('click', closeModal);
        }

        showModal();
    }

    const bellIcon = document.getElementById('notificationIcon');
    const dropdown = document.getElementById('notificationDropdown');
    const countSpan = document.getElementById('notificationCount');

    if (bellIcon && dropdown && countSpan) {
        function fetchNotifications() {
            fetch('fetch_notifications.php')
                .then(response => response.json())
                .then(data => {
                    dropdown.innerHTML = '';
                    let unreadCount = 0;

                    data.forEach(notification => {
                        const isUnread = notification.read_status === 0;
                        if (isUnread) unreadCount++;
                        dropdown.innerHTML += `
                            <a href="information.php?target_name=${notification.target_name}" 
                               class="${isUnread ? 'unread' : 'read'}">
                                ${notification.message} - ${notification.target_name} (${notification.equipment_type})
                            </a>`;
                    });

                    countSpan.textContent = unreadCount;
                    countSpan.style.display = unreadCount > 0 ? 'inline' : 'none';
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }

        bellIcon.addEventListener('click', function (event) {
            event.stopPropagation();
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            fetch('mark_notification_viewed.php', { method: 'POST' })
                .then(response => response.json())
                .then(result => {
                    if (result.status === 'success') {
                        fetchNotifications();
                    }
                })
                .catch(error => console.error('Error marking notifications as read:', error));
        });
// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
            if (!dropdown.contains(event.target) && !bellIcon.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        });

        // Initial fetch of notifications
        fetchNotifications();

        // Periodically refresh notifications
        setInterval(fetchNotifications, 60000); // Refresh every 60 seconds
    }
});
</script>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
