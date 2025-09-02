<?php
require 'db_connect.php';

$tables = ['devices', 'komtrax'];

foreach ($tables as $table) {
    $query = "SELECT id, date_transferred, date_ended FROM $table WHERE date_transferred IS NOT NULL AND date_transferred != '0000-00-00'";
    $result = $conn->query($query);

    if (!$result) {
        error_log("Error fetching records from $table: " . $conn->error);
        continue;
    }

    $current_date = new DateTime();

    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $date_transferred = $row['date_transferred'];
        $date_ended = $row['date_ended'];

        try {
            $start = new DateTime($date_transferred);
            // Calculate days_elapsed
            $interval_elapsed = $current_date->diff($start);
            $days_elapsed = $interval_elapsed->days;

            // Calculate days_lapses
            $days_lapses = 0;
            if ($date_ended && $date_ended !== '0000-00-00') {
                $end = new DateTime($date_ended);
                if ($current_date > $end) {
                    $interval_lapses = $current_date->diff($end);
                    $days_lapses = $interval_lapses->days;
                }
            }

            // Update table
            $update_query = "UPDATE $table SET days_elapsed = ?, days_lapses = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            if (!$stmt) {
                error_log("Prepare failed for updating $table ID $id: " . $conn->error);
                continue;
            }
            $stmt->bind_param("iii", $days_elapsed, $days_lapses, $id);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error updating days for $table ID $id: " . $e->getMessage());
        }
    }
    $result->free();
}

$conn->close();
?>