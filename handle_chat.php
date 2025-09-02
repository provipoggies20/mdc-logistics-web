<?php
session_start();

if (!file_exists('vendor/autoload.php')) {
    error_log("Composer autoload file not found in C:\\xampp\\htdocs\\vendor\\autoload.php");
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server error: Composer dependencies not installed']);
    exit;
}

require_once 'vendor/autoload.php';
use Dotenv\Dotenv;

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Dotenv load error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server error: Failed to load environment variables']);
    exit;
}

header('Content-Type: application/json');

function getDbConnection() {
    $conn = new mysqli(
        $_ENV['DB_HOST'],
        $_ENV['DB_USER'],
        $_ENV['DB_PASSWORD'],
        $_ENV['DB_NAME']
    );
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        return null;
    }
    return $conn;
}

function generateDynamicQuery($message, $conn, $vehicle_data) {
    $message_lower = strtolower($message);
    if (strpos($message_lower, 'total') !== false) {
        return "The dashboard reports a total of {$vehicle_data['totalVehicles']} vehicles.";
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error in input: " . json_last_error_msg());
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input: ' . json_last_error_msg()]);
        exit;
    }

    $message = trim($input['message'] ?? '');
    $session_id = $input['session_id'] ?? session_id();
    $vehicle_data = $input['vehicle_data'] ?? [];

    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'No message provided']);
        exit;
    }

    $conn = getDbConnection();
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Database unavailable']);
        exit;
    }

    $response = generateDynamicQuery($message, $conn, $vehicle_data);
    if ($response) {
        echo json_encode(['success' => true, 'response' => $response]);
    } else {
        $ch = curl_init('http://localhost:5000/chat');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'message' => $message,
            'session_id' => $session_id,
            'vehicle_data' => $vehicle_data,
            'history' => []
        ]));
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10-second timeout

        $flask_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($flask_response === false || $http_code !== 200) {
            rewind($verbose);
            $verbose_log = stream_get_contents($verbose);
            fclose($verbose);
            error_log("cURL error: $curl_error, HTTP Code: $http_code, Response: " . substr($flask_response, 0, 500));
            echo json_encode(['success' => false, 'error' => "Failed to connect to AI service: HTTP $http_code, $curl_error"]);
            exit;
        }

        $response_data = json_decode($flask_response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg() . ", Response: " . substr($flask_response, 0, 500));
            echo json_encode(['success' => false, 'error' => 'Invalid response from AI service: ' . json_last_error_msg()]);
            exit;
        }

        if (isset($response_data['chart'])) {
            echo json_encode([
                'success' => true,
                'response' => $response_data['response'],
                'chart' => $response_data['chart']
            ]);
        } else {
            echo json_encode([
                'success' => $response_data['success'],
                'response' => $response_data['response']
            ]);
        }
    }

    $logQuery = "INSERT INTO logs (user_id, action, details) VALUES (?, 'chat_query', ?)";
    $stmt = $conn->prepare($logQuery);
    $userId = $_SESSION['user_id'] ?? 0;
    $stmt->bind_param("is", $userId, $message);
    $stmt->execute();
    $stmt->close();
    $conn->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>