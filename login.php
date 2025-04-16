<?php
session_start();
require 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($user_id, $db_username, $hashed_password);
            $stmt->fetch();

            if (password_verify($password, $hashed_password)) {
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $db_username; // Store username in session
				$_SESSION['role'] = $user['role']; // Store role in session
                header("Location: index.php");
                exit();
            } else {
                echo "<script>alert('Invalid username or password'); window.location.href = 'login.html';</script>";
            }
        } else {
            echo "<script>alert('Invalid username or password'); window.location.href = 'login.html';</script>";
        }
        $stmt->close();
    } else {
        echo "<script>alert('Please fill in all fields'); window.location.href = 'login.html';</script>";
    }
}
$conn->close();
?>
