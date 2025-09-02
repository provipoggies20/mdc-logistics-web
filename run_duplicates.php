<?php
session_start();
require 'db_connect.php';

// Set PHP timezone to Manila
date_default_timezone_set('Asia/Manila');

// Set MySQL session timezone to Manila (UTC+8)
try {
    $conn->query("SET time_zone = '+08:00'");
} catch (Exception $e) {
    error_log("Failed to set MySQL timezone: " . $e->getMessage());
}

// Check if user is logged in and has the correct role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Main Admin') {
    header("Location: login.php");
    exit();
}

// Configure logging
$logs_directory = __DIR__ . '/Logs';
if (!is_dir($logs_directory)) {
    mkdir($logs_directory, 0755, true);
}
$log_file_path = $logs_directory . '/duplicate_checker.log';

function log_message($message, $level = 'INFO') {
    global $log_file_path;
    $timestamp = date('Y-m-d H:i:s T'); // Include timezone (PHT)
    $log_entry = "[$timestamp] $level: $message\n";
    file_put_contents($log_file_path, $log_entry, FILE_APPEND);
}

// Function to create or update the duplicates table
function create_duplicates_table($conn) {
    try {
        // Check if table exists
        $result = $conn->query("SHOW TABLES LIKE 'duplicates'");
        if ($result->num_rows == 0) {
            // Create table with unique constraint
            $create_table_query = "
                CREATE TABLE duplicates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    target_name VARCHAR(255),
                    similar_name VARCHAR(255),
                    similarity FLOAT,
                    detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    source_table VARCHAR(20),
                    UNIQUE KEY unique_pair (target_name, similar_name, source_table)
                )";
            $conn->query($create_table_query);
            log_message("Table 'duplicates' created with unique constraint");
        } else {
            // Check if unique constraint exists
            $result = $conn->query("SHOW INDEX FROM duplicates WHERE Key_name = 'unique_pair'");
            if ($result->num_rows == 0) {
                // Remove any duplicate entries before adding constraint
                $conn->query("
                    DELETE t1 FROM duplicates t1
                    INNER JOIN duplicates t2
                    WHERE t1.id > t2.id
                    AND t1.target_name = t2.target_name
                    AND t1.similar_name = t2.similar_name
                    AND t1.source_table = t2.source_table
                ");
                // Add unique constraint
                $conn->query("ALTER TABLE duplicates ADD UNIQUE KEY unique_pair (target_name, similar_name, source_table)");
                log_message("Added unique constraint to existing 'duplicates' table and cleaned duplicates");
            }
            // Ensure source_table column exists
            $result = $conn->query("SHOW COLUMNS FROM duplicates LIKE 'source_table'");
            if ($result->num_rows == 0) {
                $conn->query("ALTER TABLE duplicates ADD COLUMN source_table VARCHAR(20)");
                log_message("Added source_table column to duplicates table");
            }
        }
        return true;
    } catch (Exception $e) {
        log_message("Error creating/updating duplicates table: " . $e->getMessage(), "ERROR");
        return false;
    }
}

// Function to analyze differences between two strings
function analyze_differences($target_name, $similar_name) {
    $differences = [];
    
    // Check for spaces
    $target_has_space = strpos($target_name, ' ') !== false;
    $similar_has_space = strpos($similar_name, ' ') !== false;
    if ($target_has_space != $similar_has_space) {
        $differences[] = "Space difference detected";
    }
    
    // Check for special characters
    $special_chars = '!@#$%^&*()-_=+[]{}|;:,.<>?/~`';
    $escaped_special_chars = preg_quote($special_chars, '/');
    $target_special = preg_match("/[$escaped_special_chars]/", $target_name);
    $similar_special = preg_match("/[$escaped_special_chars]/", $similar_name);
    if ($target_special != $similar_special) {
        $differences[] = "Special character difference detected";
    }
    
    // Character-by-character comparison
    if (strlen($target_name) == strlen($similar_name)) {
        $char_diffs = [];
        for ($i = 0; $i < strlen($target_name); $i++) {
            if ($target_name[$i] != $similar_name[$i]) {
                $char_diffs[] = $i + 1;
            }
        }
        if ($char_diffs) {
            $differences[] = "Character differences at positions: " . implode(', ', $char_diffs);
        }
    }
    
    // Length difference
    if (strlen($target_name) != strlen($similar_name)) {
        $differences[] = "Length difference: " . strlen($target_name) . " vs " . strlen($similar_name);
    }
    
    return $differences ? $differences : ["No specific differences identified"];
}

// Function to check for lookalike duplicates for a single target_name
function check_for_lookalikes($conn, $target_name, $all_names, $start_index, $source_table) {
    $duplicates_found = [];
    try {
        for ($i = $start_index; $i < count($all_names); $i++) {
            $name = $all_names[$i];
            if ($name != $target_name) {
                similar_text($target_name, $name, $similarity);
                $similarity = round($similarity, 2);
                if ($similarity > 85) {
                    $differences = analyze_differences($target_name, $name);
                    log_message("Duplicate found in $source_table: '$target_name' similar to '$name' ($similarity%). Differences: " . implode(', ', $differences));
                    $duplicates_found[] = [
                        'similar_name' => $name,
                        'similarity' => $similarity,
                        'differences' => $differences
                    ];
                }
            }
        }
        return $duplicates_found;
    } catch (Exception $e) {
        log_message("Error checking for duplicates in $source_table: " . $e->getMessage(), "ERROR");
        return [];
    }
}

// Function to insert duplicate record
function insert_duplicate($conn, $target_name, $similar_name, $similarity, $source_table) {
    try {
        // Check if duplicate pair already exists
        $check_query = "SELECT id FROM duplicates WHERE target_name = ? AND similar_name = ? AND source_table = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("sss", $target_name, $similar_name, $source_table);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            log_message("Duplicate pair '$target_name' and '$similar_name' in $source_table already exists, skipping insertion");
            return false;
        }
        
        // Insert new duplicate
        $insert_query = "INSERT INTO duplicates (target_name, similar_name, similarity, source_table) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("ssds", $target_name, $similar_name, $similarity, $source_table);
        $stmt->execute();
        log_message("Inserted duplicate in $source_table: '$target_name' similar to '$similar_name' ($similarity%)");
        return true;
    } catch (Exception $e) {
        log_message("Error inserting duplicate in $source_table: " . $e->getMessage(), "ERROR");
        return false;
    }
}

// Function to clean up duplicates
function cleanup_duplicates($conn) {
    try {
        $delete_query = "
            DELETE FROM duplicates
            WHERE (source_table = 'devices' AND target_name NOT IN (SELECT target_name FROM devices))
            OR (source_table = 'komtrax' AND target_name NOT IN (SELECT target_name FROM komtrax))";
        $conn->query($delete_query);
        log_message("Cleaned up outdated duplicates from devices and komtrax");
        return true;
    } catch (Exception $e) {
        log_message("Error cleaning up duplicates: " . $e->getMessage(), "ERROR");
        return false;
    }
}

// Main function to check duplicates
function check_all_duplicates($conn) {
    $output = [];
    try {
        if (!create_duplicates_table($conn)) {
            $output[] = "Failed to create duplicates table";
            return ['success' => false, 'output' => $output];
        }

        // Process devices table
        $result = $conn->query("SELECT target_name FROM devices");
        $devices_names = [];
        while ($row = $result->fetch_assoc()) {
            $devices_names[] = $row['target_name'];
        }
        if (empty($devices_names)) {
            log_message("No records found in 'devices' table", "WARNING");
            $output[] = "No records found in 'devices' table";
        } else {
            $output[] = "Found " . count($devices_names) . " target_name entries in devices table";
            $devices_duplicate_count = 0;

            // Clear existing duplicates
            cleanup_duplicates($conn);

            // Compare each target_name in devices
            for ($i = 0; $i < count($devices_names); $i++) {
                $target_name = $devices_names[$i];
                $duplicates = check_for_lookalikes($conn, $target_name, $devices_names, $i + 1, 'devices');
                foreach ($duplicates as $duplicate) {
                    if (insert_duplicate($conn, $target_name, $duplicate['similar_name'], $duplicate['similarity'], 'devices')) {
                        $devices_duplicate_count++;
                        $output[] = "Duplicate found and stored in devices: '$target_name' similar to '{$duplicate['similar_name']}' ({$duplicate['similarity']}%). Differences: " . implode(', ', $duplicate['differences']);
                    }
                }
            }

            if ($devices_duplicate_count > 0) {
                $output[] = "Found and stored $devices_duplicate_count duplicate(s) in devices at " . date('Y-m-d h:i:s A T');
            } else {
                $output[] = "No duplicates found in devices table at " . date('Y-m-d h:i:s A T');
            }
        }

        // Process komtrax table
        $result = $conn->query("SELECT target_name FROM komtrax");
        $komtrax_names = [];
        while ($row = $result->fetch_assoc()) {
            $komtrax_names[] = $row['target_name'];
        }
        if (empty($komtrax_names)) {
            log_message("No records found in 'komtrax' table", "WARNING");
            $output[] = "No records found in 'komtrax' table";
        } else {
            $output[] = "Found " . count($komtrax_names) . " target_name entries in komtrax table";
            $komtrax_duplicate_count = 0;

            // Compare each target_name in komtrax
            for ($i = 0; $i < count($komtrax_names); $i++) {
                $target_name = $komtrax_names[$i];
                $duplicates = check_for_lookalikes($conn, $target_name, $komtrax_names, $i + 1, 'komtrax');
                foreach ($duplicates as $duplicate) {
                    if (insert_duplicate($conn, $target_name, $duplicate['similar_name'], $duplicate['similarity'], 'komtrax')) {
                        $komtrax_duplicate_count++;
                        $output[] = "Duplicate found and stored in komtrax: '$target_name' similar to '{$duplicate['similar_name']}' ({$duplicate['similarity']}%). Differences: " . implode(', ', $duplicate['differences']);
                    }
                }
            }

            if ($komtrax_duplicate_count > 0) {
                $output[] = "Found and stored $komtrax_duplicate_count duplicate(s) in komtrax at " . date('Y-m-d h:i:s A T');
            } else {
                $output[] = "No duplicates found in komtrax table at " . date('Y-m-d h:i:s A T');
            }
        }

        return ['success' => true, 'output' => $output];

    } catch (Exception $e) {
        log_message("Error in duplicate checking process: " . $e->getMessage(), "ERROR");
        $output[] = "Error occurred: " . $e->getMessage();
        return ['success' => false, 'output' => $output];
    }
}

$result = check_all_duplicates($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duplicates Check Result</title>
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
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
        }
        .output {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        .output p {
            margin: 5px 0;
            color: #2c3e50;
        }
        .output p.devices {
            color: #2c3e50;
            font-weight: bold;
        }
        .output p.komtrax {
            color: #d9534f;
            font-weight: bold;
        }
        .back-button {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔍 Duplicates Check Result</h2>
        <a href="pending_edits.php" class="back-button">← Back to Pending Approvals</a>
        <div class="output">
            <?php foreach ($result['output'] as $line): ?>
                <?php
                $class = '';
                if (strpos($line, 'in devices') !== false) {
                    $class = 'devices';
                } elseif (strpos($line, 'in komtrax') !== false) {
                    $class = 'komtrax';
                }
                ?>
                <p class="<?php echo $class; ?>"><?php echo htmlspecialchars($line); ?></p>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>