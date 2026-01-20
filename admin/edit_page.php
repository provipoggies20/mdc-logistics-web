<?php
session_start();
include '../includes/db.php';

// Check if the user is logged in
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Fetch the page to edit
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $result = mysqli_query($conn, "SELECT * FROM pages WHERE id='$id'");
    $page = mysqli_fetch_assoc($result);
}

// Update page functionality
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $content = $_POST['content'];
    $slug = strtolower(str_replace(' ', '-', $title));
    $query = "UPDATE pages SET title='$title', content='$content', slug='$slug' WHERE id='$id'";
    mysqli_query($conn, $query);
    header('Location: dashboard.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Page</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <h1>Edit Page</h1>
    <form method="POST" action="">
        <label for="title">Title:</label>
        <input type="text" name="title" value="<?php echo $page['title']; ?>" required>
        <label for="content">Content:</label>
        <textarea name="content" required><?php echo $page['content']; ?></textarea>
        <button type="submit">Update Page</button>
    </form>
</body>
</html>