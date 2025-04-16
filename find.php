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
			width: 70%; /* Increase width from 50% to 70% */
			max-width: 800px; /* Ensure it doesn’t get too wide */
			margin: auto;
			background: white;
			padding: 20px;
			border-radius: 5px;
			box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
			max-height: 80vh;
			overflow-y: auto;
		}
        .find-form {
            display: grid;
            grid-template-columns: 150px auto;
            gap: 10px;
        }
        .find-form label {
            text-align: right;
            font-weight: bold;
        }
        .find-form input {
            width: 100%;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .find-form button {
            grid-column: span 2;
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
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
            <?php
            $columnsResult = $conn->query("SHOW COLUMNS FROM devices");
            while ($column = $columnsResult->fetch_assoc()) {
                echo "<label>" . strtoupper(str_replace('_', ' ', $column['Field'])) . ":</label>";
                echo "<input type='text' name='{$column['Field']}' autocomplete='off'>";
            }
            ?>
            <button type="submit">Search</button>
        </form>
    </div>

    <script>
        document.getElementById("searchForm").addEventListener("submit", function(event) {
            event.preventDefault(); // Prevent form from refreshing page

            let formData = new FormData(this);
            let params = new URLSearchParams(formData).toString();

            // Redirect the main page to information.php with search parameters
            window.parent.location.href = "information.php?" + params;

            // Close modal
            if (window.parent) {
                window.parent.closeFindModal();
            }
        });
    </script>

</body>
</html>

<?php $conn->close(); ?>
