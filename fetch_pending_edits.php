<?php
require 'db_connect.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$pendingItems = [];

try {
    // Fetch pending edits
    $editQuery = "
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
    $editResult = $conn->query($editQuery);
    if ($editResult) {
        while ($row = $editResult->fetch_assoc()) {
            $row['proposed_data'] = json_decode($row['proposed_data'] ?? '{}', true) ?? [];
            $currentData = [];
            if ($stmt = $conn->prepare("SELECT * FROM {$row['target_table']} WHERE id = ?")) {
                $stmt->bind_param("i", $row['target_record_id']);
                $stmt->execute();
                $currentData = $stmt->get_result()->fetch_assoc() ?? [];
                $stmt->close();
            }
            $row['current_data'] = $currentData;
            $pendingItems[] = $row;
        }
    }

    // Fetch pending vehicles
    $vehicleQuery = "
        SELECT 
            pv.*,
            u.username,
            'vehicle' AS request_type
        FROM pending_vehicles pv
        JOIN users u ON pv.requested_by_user_id = u.id
        WHERE pv.pending_status = 'Pending'";
    $vehicleResult = $conn->query($vehicleQuery);
    if ($vehicleResult) {
        while ($row = $vehicleResult->fetch_assoc()) {
            $proposedData = [];
            foreach ($row as $key => $value) {
                if (!in_array($key, ['id', 'source_table', 'requested_by_user_id', 'submitted_at', 'pending_status', 'reviewed_by_user_id', 'reviewed_at', 'review_comments', 'username', 'request_type'])) {
                    $proposedData[$key] = $value;
                }
            }
            $row['proposed_data'] = $proposedData;
            $row['current_data'] = []; // No current data for new vehicles
            $pendingItems[] = $row;
        }
    }

    echo json_encode($pendingItems);
} catch (Exception $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();
?>