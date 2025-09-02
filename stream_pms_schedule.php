<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

require 'db_connect.php';

while (true) {
    try {
        if ($conn->connect_error) {
            throw new Exception("Database connection failed: " . $conn->connect_error);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $filter = trim($_GET['filter'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $itemsPerPage = 10;
        $offset = ($page - 1) * $itemsPerPage;

        // Base query
        $query = "
            SELECT 
                target_name,
                equipment_type,
                COALESCE(last_pms_date, '1970-01-01') AS last_pms_date,
                pms_interval,
                CASE 
                    WHEN pms_interval > 0 THEN DATE_ADD(
                        COALESCE(last_pms_date, CURDATE()), 
                        INTERVAL pms_interval DAY
                    )
                    ELSE NULL
                END AS next_pms_date,
                source
            FROM (
                SELECT 
                    target_name,
                    TRIM(equipment_type) AS equipment_type,
                    last_pms_date,
                    pms_interval,
                    'devices' AS source
                FROM devices
                WHERE equipment_type IS NOT NULL
                UNION
                SELECT 
                    target_name,
                    TRIM(equipment_type) AS equipment_type,
                    last_pms_date,
                    pms_interval,
                    'komtrax' AS source
                FROM komtrax
                WHERE equipment_type IS NOT NULL
            ) AS combined
            WHERE 1=1";

        $params = [];
        $types = '';

        if (!empty($filter)) {
            $query .= " AND LOWER(TRIM(equipment_type)) = LOWER(?)";
            $params[] = $filter;
            $types .= 's';
        }

        if (!empty($search)) {
            $query .= " AND LOWER(target_name) LIKE LOWER(?)";
            $params[] = "%$search%";
            $types .= 's';
        }

        // Count query
        $countQuery = "SELECT COUNT(*) as total FROM ($query) AS subquery";
        $stmt = $conn->prepare($countQuery);
        if (!$stmt) {
            throw new Exception("Count query preparation failed: " . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception("Count query execution failed: " . $stmt->error);
        }

        $countResult = $stmt->get_result();
        $totalCount = $countResult->fetch_assoc()['total'] ?? 0;
        $totalPages = max(1, ceil($totalCount / $itemsPerPage));
        $stmt->close();

        // Main data query
        $query .= " ORDER BY next_pms_date ASC, target_name LIMIT ? OFFSET ?";
        $params[] = $itemsPerPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Main query preparation failed: " . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception("Main query execution failed: " . $stmt->error);
        }

        $result = $stmt->get_result();
        $vehicles = [];
        while ($row = $result->fetch_assoc()) {
            $row['last_pms_date'] = $row['last_pms_date'] === '1970-01-01' ? null : $row['last_pms_date'];
            $row['next_pms_date'] = $row['next_pms_date'] === null ? null : $row['next_pms_date'];
            $vehicles[] = $row;
        }

        $stmt->close();

        $data = [
            'success' => true,
            'vehicles' => $vehicles,
            'totalCount' => $totalCount,
            'totalPages' => $totalPages
        ];

        echo "data: " . json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        ob_flush();
        flush();
    } catch (Throwable $e) {
        error_log("Stream PMS Schedule Error: " . $e->getMessage());
        echo "data: " . json_encode([
            'success' => false,
            'error' => 'Server error occurred'
        ]) . "\n\n";
        ob_flush();
        flush();
    }

    sleep(5);
}
?>