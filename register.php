<?php
require 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST["first_name"]);
    $last_name = trim($_POST["last_name"]);
    $username = trim($_POST["username"]);
    $password = password_hash($_POST["password"], PASSWORD_BCRYPT); // Encrypt password

    // Assign role based on username
    $role = ($username === "Lxoric") ? "Main Admin" : "User";

    // Insert user into database
    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, username, password, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $first_name, $last_name, $username, $password, $role);

    if ($stmt->execute()) {
        header("Location: login.html"); // Redirect to login after successful registration
        exit();
    } else {
        echo "Error: Could not register user.";
    }
}
?>
