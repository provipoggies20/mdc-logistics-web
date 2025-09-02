<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

// Check if the logged-in user is the Main Admin
$is_main_admin = isset($_SESSION['username']) && $_SESSION['username'] === 'Lxoric';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome | Maxipro Development Corporation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: url('assets/images/Designer.jpeg') no-repeat center center fixed;
            background-size: cover;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .container {
            text-align: center;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.2);
            animation: fadeIn 1s ease-in-out;
            width: 400px;
            position: relative;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 15px;
        }

        .logo-container img {
            width: 110px;
            animation: lightReflection 2.5s infinite alternate ease-in-out;
        }

        @keyframes lightReflection {
            from { filter: brightness(1); }
            to { filter: brightness(1.2); }
        }

        h1 {
            color: #007bff;
            font-size: 22px;
            margin-top: 10px;
        }

        p {
            font-size: 18px;
            color: #333;
        }

        .btn-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            width: 90%;
        }

        .btn i {
            margin-right: 8px;
        }

        .dashboard-btn {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
        }

        .dashboard-btn:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: scale(1.05);
        }

        .logout-btn {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }

        .logout-btn:hover {
            background: linear-gradient(135deg, #c82333, #a71d2a);
            transform: scale(1.05);
        }

        .admin-btn {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: black;
        }

        .admin-btn:hover {
            background: linear-gradient(135deg, #e0a800, #c69500);
            transform: scale(1.05);
        }

        /* Notification Styles */
        #notificationWrapper {
            position: absolute;
            top: 15px;
            right: 15px;
        }

        #notificationIcon {
            font-size: 24px;
            cursor: pointer;
            position: relative;
        }

        #notificationCount {
            position: absolute;
            top: -10px;
            right: -10px;
            background-color: red;
            color: white;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 50%;
            display: none;
        }

        #notificationDropdown {
            display: none;
            position: absolute;
            top: 30px;
            right: 0;
            background: white;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            width: 300px;
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
            font-size: 14px;
            color: #333;
        }

        #notificationDropdown a {
            display: block;
            padding: 10px;
            text-decoration: none;
            color: #333;
        }

        #notificationDropdown a.unread {
            font-weight: bold;
            border-left: 3px solid #ff4757;
            background-color: #fff5f5;
        }

        #notificationDropdown a.read {
            color: #666;
        }

        #notificationDropdown a:hover {
            background-color: #f0f0f0;
        }

        /* Responsive adjustments */
        @media (max-width: 450px) {
            .container {
                width: 90%;
                padding: 20px;
            }
            #notificationWrapper {
                top: 10px;
                right: 10px;
            }
            #notificationDropdown {
                width: 250px;
                right: -10px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Notification Bell Icon -->
    <?php if ($_SESSION['role'] === 'Main Admin') : ?>
    <div id="notificationWrapper">
        <div id="notificationIcon" style="cursor: pointer; position: relative;">
            🔔
            <span id="notificationCount">0</span>
        </div>
        <div id="notificationDropdown"></div>
    </div>
    <?php endif; ?>

    <div class="logo-container">
        <img src="resized_logo.jpg" alt="Company Logo">
    </div>
    <h1>Maxipro Development Corporation</h1>
    <p>Welcome, <b><?php echo htmlspecialchars($_SESSION['username']); ?></b>!</p>

    <div class="btn-container">
        <a href="pms_due_summary.php" class="btn dashboard-btn"><i class="fas fa-chart-line"></i> Go to Dashboard</a>
        <?php if ($is_main_admin): ?>
            <a href="admin_panel.php" class="btn admin-btn"><i class="fas fa-user-shield"></i> User Management</a>
        <?php endif; ?>
        <a href="logout.php" class="btn logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Notification Handling (Main Admin Only)
    const bellIcon = document.getElementById('notificationIcon');
    const dropdown = document.getElementById('notificationDropdown');
    const countSpan = document.getElementById('notificationCount');
    let lastNotificationId = 0; // Track the last processed notification ID

    if (bellIcon && dropdown && countSpan) {
        function updateNotifications(notifications) {
            dropdown.innerHTML = '';
            let unreadCount = 0;

            notifications.forEach(notification => {
                const isUnread = notification.read_status === 0;
                if (isUnread) unreadCount++;
                dropdown.innerHTML += `
                    <a href="information.php?target_name=${notification.target_name}" 
                       class="${isUnread ? 'unread' : 'read'}">
                        ${notification.message} - ${notification.target_name} (${notification.equipment_type})
                    </a>`;
                lastNotificationId = Math.max(lastNotificationId, notification.id);
            });

            countSpan.textContent = unreadCount;
            countSpan.style.display = unreadCount > 0 ? 'inline' : 'none';
        }

        // Fetch notifications via HTTP (fallback)
        function fetchNotifications() {
            fetch('fetch_notifications.php')
                .then(response => response.json())
                .then(data => updateNotifications(data))
                .catch(error => console.error('Error fetching notifications:', error));
        }

        // Set up Server-Sent Events
        const eventSource = new EventSource('stream_notifications.php');
        eventSource.onmessage = function (event) {
            const notifications = JSON.parse(event.data);
            if (notifications.length > 0) {
                updateNotifications(notifications);
            }
        };
        eventSource.onerror = function () {
            console.error('SSE error, falling back to polling');
            eventSource.close();
            // Fallback to polling
            setInterval(fetchNotifications, 10000);
        };

        bellIcon.addEventListener('click', function (event) {
            event.stopPropagation();
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            fetch('mark_notification_viewed.php', { method: 'POST' })
                .then(response => response.json())
                .then(result => {
                    if (result.status === 'success') {
                        fetchNotifications();
                    }
                })
                .catch(error => console.error('Error marking notifications as read:', error));
        });

        document.addEventListener('click', function (event) {
            if (!bellIcon.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        });

        // Initial fetch
        fetchNotifications();
    }
});
</script>

</body>
</html>