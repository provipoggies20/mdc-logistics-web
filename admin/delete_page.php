<?php
session_start();
include '../includes/db.php';

// Check if the user is logged in
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Delete page functionality
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $query = "DELETE FROM pages WHERE id='$id'";
    mysqli_query($conn, $query);
    header('Location: dashboard.php');
    exit;
}
?>