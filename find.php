```php
<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Data</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f4f4f4;
        }
        h2 {
            text-align: center;
        }
        .container {
            width: 70%;
            max-width: 800px;
            margin: auto;
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
            max-height: 80vh;
            overflow-y: auto;
        }
        .find-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .find-form label {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .find-form input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .find-form button {
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        .find-form button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Find Data</h2>
    <form id="searchForm" class="find-form">
        <label for="search_term">Search Term:</label>
        <input type="text" name="search_term" id="search_term" autocomplete="off" placeholder="Enter vehicle name, equipment type, etc.">
        <button type="submit">Search</button>
    </form>
</div>

<script>
document.getElementById("searchForm").addEventListener("submit", function(event) {
    event.preventDefault(); // Prevent form from refreshing page

    const formData = new FormData(this);
    const params = new URLSearchParams(formData).toString();

    // Redirect the main page to information.php with search parameters
    window.opener.location.href = "information.php?" + params;

    // Close the modal window
    window.close();
});
</script>

</body>
</html>

<?php $conn->close(); ?>
```