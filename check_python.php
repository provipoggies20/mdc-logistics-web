<?php
/**
 * Python Installation Checker
 * Run this file directly in your browser to check Python installation
 * Example: http://localhost/your-project/check_python.php
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Python Installation Checker</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .test-item {
            margin: 20px 0;
            padding: 15px;
            border-left: 4px solid #ddd;
            background: #f9f9f9;
        }
        .success {
            border-left-color: #28a745;
            background: #d4edda;
        }
        .error {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        .info {
            border-left-color: #17a2b8;
            background: #d1ecf1;
        }
        .command {
            background: #333;
            color: #0f0;
            padding: 10px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background: #007bff;
            color: white;
        }
        .check-mark {
            color: #28a745;
            font-weight: bold;
        }
        .cross-mark {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🐍 Python Installation Checker</h1>
        
        <?php
        // Test different Python paths
        $pythonPaths = [
            'python',
            'python3',
            'C:\\Users\\GPSX2\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
            'C:\\Python313\\python.exe',
            'C:\\Python311\\python.exe',
            'C:\\Python310\\python.exe',
            'C:\\Python39\\python.exe',
            '/usr/bin/python3',
            '/usr/bin/python',
            '/usr/local/bin/python3',
            '/usr/local/bin/python'
        ];
        
        $foundPython = null;
        $results = [];
        
        echo '<h2>Testing Python Executables</h2>';
        echo '<table>';
        echo '<tr><th>Path</th><th>Status</th><th>Version</th></tr>';
        
        foreach ($pythonPaths as $path) {
            $exists = false;
            $version = '';
            $status = '';
            
            // Check if file exists (for absolute paths)
            if (strpos($path, ':\\') !== false || strpos($path, '/') === 0) {
                $exists = file_exists($path);
                if (!$exists) {
                    echo '<tr>';
                    echo '<td><code>' . htmlspecialchars($path) . '</code></td>';
                    echo '<td><span class="cross-mark">✗</span> File not found</td>';
                    echo '<td>-</td>';
                    echo '</tr>';
                    continue;
                }
            }
            
            // Try to execute
            $command = escapeshellarg($path) . ' --version 2>&1';
            $output = @shell_exec($command);
            
            if ($output && (strpos($output, 'Python') !== false)) {
                $version = trim($output);
                $status = '<span class="check-mark">✓</span> Working';
                
                if (!$foundPython) {
                    $foundPython = $path;
                }
                
                echo '<tr style="background: #d4edda;">';
            } else {
                $status = '<span class="cross-mark">✗</span> Not working';
                echo '<tr>';
            }
            
            echo '<td><code>' . htmlspecialchars($path) . '</code></td>';
            echo '<td>' . $status . '</td>';
            echo '<td>' . htmlspecialchars($version) . '</td>';
            echo '</tr>';
            
            $results[$path] = [
                'working' => $output ? true : false,
                'version' => $version
            ];
        }
        
        echo '</table>';
        
        // Show found Python
        if ($foundPython) {
            echo '<div class="test-item success">';
            echo '<h3>✓ Python Found!</h3>';
            echo '<p><strong>Recommended path:</strong> <code>' . htmlspecialchars($foundPython) . '</code></p>';
            echo '<p><strong>Version:</strong> ' . htmlspecialchars($results[$foundPython]['version']) . '</p>';
            echo '</div>';
            
            // Test required packages
            echo '<h2>Testing Required Python Packages</h2>';
            $packages = ['mysql.connector', 'pandas', 'openpyxl'];
            
            echo '<table>';
            echo '<tr><th>Package</th><th>Status</th><th>Version</th></tr>';
            
            foreach ($packages as $package) {
                $importName = $package === 'mysql.connector' ? 'mysql.connector' : $package;
                $command = escapeshellarg($foundPython) . ' -c "import ' . $importName . '; print(' . $importName . '.__version__)" 2>&1';
                $output = @shell_exec($command);
                
                if ($output && !stripos($output, 'Error') && !stripos($output, 'No module')) {
                    echo '<tr style="background: #d4edda;">';
                    echo '<td><code>' . htmlspecialchars($package) . '</code></td>';
                    echo '<td><span class="check-mark">✓</span> Installed</td>';
                    echo '<td>' . htmlspecialchars(trim($output)) . '</td>';
                } else {
                    echo '<tr style="background: #f8d7da;">';
                    echo '<td><code>' . htmlspecialchars($package) . '</code></td>';
                    echo '<td><span class="cross-mark">✗</span> Not installed</td>';
                    echo '<td>-</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
            
            // Show installation command
            echo '<div class="test-item info">';
            echo '<h3>📦 Install Missing Packages</h3>';
            echo '<p>If any packages are missing, run this command:</p>';
            echo '<div class="command">';
            echo htmlspecialchars($foundPython) . ' -m pip install mysql-connector-python pandas openpyxl';
            echo '</div>';
            echo '</div>';
            
            // Test database connection
            echo '<h2>Testing Database Connection</h2>';
            $testScript = <<<PYTHON
import sys
import json
try:
    import mysql.connector
    conn = mysql.connector.connect(
        host='localhost',
        database='mdc',
        user='root',
        password='',
        connection_timeout=10
    )
    if conn.is_connected():
        print(json.dumps({'success': True, 'message': 'Database connection successful'}))
        conn.close()
    else:
        print(json.dumps({'success': False, 'message': 'Connection failed'}))
except Exception as e:
    print(json.dumps({'success': False, 'message': str(e)}))
PYTHON;
            
            $tempFile = tempnam(sys_get_temp_dir(), 'db_test_') . '.py';
            file_put_contents($tempFile, $testScript);
            
            $command = escapeshellarg($foundPython) . ' ' . escapeshellarg($tempFile) . ' 2>&1';
            $output = @shell_exec($command);
            unlink($tempFile);
            
            $dbResult = json_decode($output, true);
            
            if ($dbResult && $dbResult['success']) {
                echo '<div class="test-item success">';
                echo '<h3>✓ Database Connection Successful</h3>';
                echo '<p>Python can connect to your MySQL database.</p>';
                echo '</div>';
            } else {
                echo '<div class="test-item error">';
                echo '<h3>✗ Database Connection Failed</h3>';
                echo '<p><strong>Error:</strong> ' . htmlspecialchars($dbResult['message'] ?? $output) . '</p>';
                echo '</div>';
            }
            
            // Show configuration for PHP
            echo '<div class="test-item info">';
            echo '<h3>📝 Update Your PHP Configuration</h3>';
            echo '<p>Add this to your <code>generate_assignment_report.php</code> at the top of the Python paths array:</p>';
            echo '<div class="command">';
            echo "'" . htmlspecialchars($foundPython) . "'";
            echo '</div>';
            echo '</div>';
            
        } else {
            echo '<div class="test-item error">';
            echo '<h3>✗ No Working Python Installation Found</h3>';
            echo '<p>Please install Python 3.x from <a href="https://www.python.org/downloads/" target="_blank">python.org</a></p>';
            echo '<p><strong>Important:</strong> During installation, make sure to check "Add Python to PATH"</p>';
            echo '</div>';
        }
        
        // Show system information
        echo '<h2>System Information</h2>';
        echo '<div class="test-item info">';
        echo '<table>';
        echo '<tr><th>Property</th><th>Value</th></tr>';
        echo '<tr><td>PHP Version</td><td>' . phpversion() . '</td></tr>';
        echo '<tr><td>Operating System</td><td>' . php_uname() . '</td></tr>';
        echo '<tr><td>Server Software</td><td>' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . '</td></tr>';
        echo '<tr><td>Current Directory</td><td>' . __DIR__ . '</td></tr>';
        echo '<tr><td>Script Directory</td><td>' . dirname(__FILE__) . '</td></tr>';
        echo '</table>';
        echo '</div>';
        ?>
        
        <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
            <h3>💡 Quick Troubleshooting</h3>
            <ol>
                <li>If no Python is found, install Python from <a href="https://www.python.org/downloads/" target="_blank">python.org</a></li>
                <li>During installation, check "Add Python to PATH"</li>
                <li>After installation, restart your web server (Apache/Nginx)</li>
                <li>Run this page again to verify the installation</li>
                <li>Install required packages using the command shown above</li>
            </ol>
        </div>
    </div>
</body>
</html>