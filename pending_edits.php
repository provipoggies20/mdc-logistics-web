<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Main Admin') {
    header("Location: login.php");
    exit();
}

$query = "
    SELECT 
        pe.*, 
        u.username,
        COALESCE(d.target_name, k.target_name, 'Unknown') AS target_name,
        'edit' AS request_type
    FROM pending_edits pe 
    JOIN users u ON pe.requested_by_user_id = u.id 
    LEFT JOIN devices d ON pe.target_table = 'devices' AND pe.target_record_id = d.id
    LEFT JOIN komtrax k ON pe.target_table = 'komtrax' AND pe.target_record_id = k.id
    WHERE pe.status = 'pending'";
$result = $conn->query($query);
$pendingEdits = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$queryVehicles = "
    SELECT 
        pv.*,
        u.username,
        'vehicle' AS request_type
    FROM pending_vehicles pv
    JOIN users u ON pv.requested_by_user_id = u.id
    WHERE pv.pending_status = 'Pending'";
$resultVehicles = $conn->query($queryVehicles);
$pendingVehicles = $resultVehicles ? $resultVehicles->fetch_all(MYSQLI_ASSOC) : [];

$pendingItems = array_merge($pendingEdits, $pendingVehicles);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }
        .container {
            width: 90%;
            max-width: 1200px;
            padding: 25px;
            background: white;
            border-radius: 10px;
            box-shadow: 0px 4px 15px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
        }
        h2 {
            color: #2c3e50;
            margin-bottom: 25px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .pending-item {
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
            padding: 20px;
            transition: transform 0.2s;
        }
        .pending-item:hover {
            transform: translateY(-2px);
        }
        .changes-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            background: white;
            border-radius: 6px;
            overflow: hidden;
        }
        .changes-table th,
        .changes-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        .changes-table th {
            background-color: #007bff;
            color: white;
            font-weight: 600;
        }
        .changes-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .request-meta {
            color: #6c757d;
            margin-bottom: 15px;
            font-size: 0.9em;
        }
        .request-meta strong {
            color: #2c3e50;
        }
        .request-meta .target-name {
            color: #555;
            font-size: 0.85em;
            margin-left: 10px;
        }
        .actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .approve-btn {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .decline-btn {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .approve-btn:hover,
        .decline-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .no-pending {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-size: 1.1em;
        }
        .back-button, .duplicates-button {
            display: inline-block;
            margin-top: 20px;
            margin-right: 10px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .back-button:hover, .duplicates-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        @media (max-width: 768px) {
            .changes-table {
                display: block;
                overflow-x: auto;
            }
            .container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>🛠 Pending Approval Requests</h2>
        <a href="dashboard.php" class="back-button">← Back to Dashboard</a>
        <a href="run_duplicates.php" class="duplicates-button">🔍 Check Duplicates</a>
        <hr>
        <div id="pendingEditsContainer">
            <?php if (empty($pendingItems)): ?>
                <div class="no-pending">
                    <p>No pending approval requests found 🎉</p>
                </div>
            <?php else: ?>
                <?php foreach ($pendingItems as $item): ?>
                    <?php
                    $isEdit = $item['request_type'] === 'edit';
                    $currentData = [];
                    if ($isEdit) {
                        if ($stmt = $conn->prepare("SELECT * FROM {$item['target_table']} WHERE id = ?")) {
                            $stmt->bind_param("i", $item['target_record_id']);
                            $stmt->execute();
                            $currentData = $stmt->get_result()->fetch_assoc() ?? [];
                            $stmt->close();
                        }
                    }
                    $proposedData = $isEdit ? (json_decode($item['proposed_data'] ?? '{}', true) ?? []) : [];
                    if (!$isEdit) {
                        foreach ($item as $key => $value) {
                            if (!in_array($key, ['id', 'source_table', 'requested_by_user_id', 'submitted_at', 'pending_status', 'reviewed_by_user_id', 'reviewed_at', 'review_comments', 'username', 'request_type', 'target_table', 'target_name'])) {
                                $proposedData[$key] = $value;
                            }
                        }
                    }
                    ?>
                    <div class="pending-item" data-id="<?= $item['id'] ?>" data-request-type="<?= $item['request_type'] ?>">
                        <div class="request-meta">
                            <div>📋 Type: <strong><?= $isEdit ? 'Edit Request' : 'New Vehicle Addition' ?></strong></div>
                            <div>📋 Table: <strong><?= htmlspecialchars($item['target_table'] ?? 'N/A') ?></strong></div>
                            <?php if ($isEdit): ?>
                                <div>
                                    🔢 Record ID: <strong><?= htmlspecialchars($item['target_record_id'] ?? 'N/A') ?></strong>
                                    <span class="target-name">(<?= htmlspecialchars($item['target_name'] ?? 'Unknown') ?>)</span>
                                </div>
                            <?php else: ?>
                                <div>
                                    🚗 Vehicle Name: <strong><?= htmlspecialchars($item['target_name'] ?? 'Unknown') ?></strong>
                                </div>
                            <?php endif; ?>
                            <div>👤 Requested by: <strong><?= htmlspecialchars($item['username'] ?? 'N/A') ?></strong></div>
                        </div>
                        <table class="changes-table">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th><?= $isEdit ? 'Current Value' : 'Proposed Value' ?></th>
                                    <?php if ($isEdit): ?>
                                        <th>Proposed Value</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($proposedData as $field => $value): ?>
                                    <tr>
                                        <td><?= strtoupper(str_replace('_', ' ', $field)) ?></td>
                                        <td>
                                            <?php if ($isEdit): ?>
                                                <?= htmlspecialchars($currentData[$field] ?? '<em>N/A</em>') ?>
                                            <?php else: ?>
                                                <span style="color: #28a745; font-weight: 500;">
                                                    <?= htmlspecialchars($value ?? '<em>N/A</em>') ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isEdit): ?>
                                            <td>
                                                <span style="color: #28a745; font-weight: 500;">
                                                    <?= htmlspecialchars($value ?? '<em>N/A</em>') ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="actions">
                            <form action="<?= $isEdit ? 'handle_approval.php' : 'handle_vehicle_approval.php' ?>" method="POST">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button type="submit" name="action" value="approve" class="approve-btn">
                                    ✅ Approve
                                </button>
                                <button type="submit" name="action" value="decline" class="decline-btn">
                                    ❌ Decline
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
document.addEventListener('DOMContentLoaded', function () {
    console.log('Script loaded');
    const container = document.getElementById('pendingEditsContainer');

    function fetchPendingItems() {
        console.log('Fetching from fetch_pending_edits.php');
        fetch('fetch_pending_edits.php', { credentials: 'same-origin' })
            .then(response => {
                console.log('Response status:', response.status, 'OK:', response.ok);
                if (!response.ok) {
                    return response.text().then(text => {
                        console.log('Response text:', text || '[Empty]');
                        throw new Error(`HTTP ${response.status}: ${text || 'No content'}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                console.log('Data received:', data);
                container.innerHTML = '';
                if (data.error) {
                    console.error('Server error:', data.error);
                    container.innerHTML = `<div class="no-pending"><p>Error: ${data.error}</p></div>`;
                    return;
                }
                if (data.length === 0) {
                    console.log('No pending items');
                    container.innerHTML = `<div class="no-pending"><p>No pending approval requests found 🎉</p></div>`;
                    return;
                }
                data.forEach(item => {
                    const isEdit = item.request_type === 'edit';
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'pending-item';
                    itemDiv.dataset.id = item.id;
                    itemDiv.dataset.requestType = item.request_type;
                    itemDiv.innerHTML = `
                        <div class="request-meta">
                            <div>📋 Type: <strong>${isEdit ? 'Edit Request' : 'New Vehicle Addition'}</strong></div>
                            <div>📋 Table: <strong>${item.target_table || 'N/A'}</strong></div>
                            ${isEdit ? `
                                <div>
                                    🔢 Record ID: <strong>${item.target_record_id || 'N/A'}</strong>
                                    <span class="target-name">(${item.target_name || 'Unknown'})</span>
                                </div>
                            ` : `
                                <div>
                                    🚗 Vehicle Name: <strong>${item.target_name || 'Unknown'}</strong>
                                </div>
                            `}
                            <div>👤 Requested by: <strong>${item.username || 'N/A'}</strong></div>
                        </div>
                        <table class="changes-table">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>${isEdit ? 'Current Value' : 'Proposed Value'}</th>
                                    ${isEdit ? '<th>Proposed Value</th>' : ''}
                                </tr>
                            </thead>
                            <tbody>
                                ${Object.entries(item.proposed_data).map(([field, value]) => `
                                    <tr>
                                        <td>${field.replace(/_/g, ' ').toUpperCase()}</td>
                                        <td>
                                            ${isEdit ? (item.current_data[field] || '<em>N/A</em>') : `<span style="color: #28a745; font-weight: 500;">${value || '<em>N/A</em>'}</span>`}
                                        </td>
                                        ${isEdit ? `
                                            <td>
                                                <span style="color: #28a745; font-weight: 500;">
                                                    ${value || '<em>N/A</em>'}
                                                </span>
                                            </td>
                                        ` : ''}
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        <div class="actions">
                            <form action="${isEdit ? 'handle_approval.php' : 'handle_vehicle_approval.php'}" method="POST">
                                <input type="hidden" name="id" value="${item.id}">
                                <button type="submit" name="action" value="approve" class="approve-btn">
                                    ✅ Approve
                                </button>
                                <button type="submit" name="action" value="decline" class="decline-btn">
                                    ❌ Decline
                                </button>
                            </form>
                        </div>`;
                    container.appendChild(itemDiv);
                });
            })
            .catch(error => {
                console.error('Fetch error:', error);
                container.innerHTML = `<div class="no-pending"><p>Failed to fetch: ${error.message}</p></div>`;
            });
    }

    console.log('Setting up EventSource');
    const eventSource = new EventSource('stream_pending_edits.php');
    eventSource.onmessage = function (event) {
        console.log('EventSource update');
        fetchPendingItems();
    };
    eventSource.onerror = function () {
        console.error('EventSource error, switching to polling');
        eventSource.close();
        setInterval(fetchPendingItems, 5000);
    };

    window.addEventListener('focus', () => {
        console.log('Window focused, fetching');
        fetchPendingItems();
    });
    console.log('Initial fetch');
    fetchPendingItems();
});
    </script>
</body>
</html>