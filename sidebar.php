<div class="sidebar">
    <h2> </h2>
    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
    <ul>
        <?php if ($_SESSION['role'] === 'Main Admin') : ?>
        <li class="<?= ($current_page == 'pending_edits.php') ? 'active' : ''; ?>">
            <a href="pending_edits.php" style="padding-right: 35px;">
                <i class="fas fa-clipboard-check"></i>
                <span>For Approval</span>
                <?php if ($pendingCount > 0) : ?>
                <span style="
                    position: absolute;
                    right: 5px;
                    top: 50%;
                    transform: translateY(-50%);
                    background: #ff4757;
                    color: white;
                    border-radius: 15px;
                    padding: 2px 8px;
                    font-size: 10px;
                    font-weight: bold;
                    min-width: 22px;
                    text-align: center;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                "><?= $pendingCount ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endif; ?>
        <li class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <a href="dashboard.php" style="color:white;">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        <li class="<?= ($current_page == 'information.php') ? 'active' : ''; ?>">
            <a href="information.php" style="color:white;">
                <i class="fas fa-car"></i> Vehicles
            </a>
        </li>
        <li class="<?= ($current_page == 'geofence.php') ? 'active' : ''; ?>">
            <a href="geofence.php" style="color:white;">
                <i class="fas fa-map-marker-alt"></i> Geofence
            </a>
        </li>
        <li class="<?= ($current_page == 'monitoring.php') ? 'active' : ''; ?>">
            <a href="monitoring.php" style="color:white;">
                <i class="fas fa-chart-line"></i> Monitoring
            </a>
        </li>
        <li class="<?= ($current_page == 'profile.php' || $current_page == 'preferences.php' || $current_page == 'notifications.php' || $current_page == 'backup.php') ? 'active' : ''; ?>">
            <a href="#" style="color:white;" onclick="toggleSettings()">
                <i class="fas fa-cogs"></i> Settings
            </a>
            <ul id="settings-menu" class="submenu">
                <li class="<?= ($current_page == 'profile.php') ? 'active' : ''; ?>">
                    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                </li>
                <li class="<?= ($current_page == 'preferences.php') ? 'active' : ''; ?>">
                    <a href="preferences.php"><i class="fas fa-paint-brush"></i> Preferences</a>
                </li>
                <li class="<?= ($current_page == 'notifications.php') ? 'active' : ''; ?>">
                    <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
                </li>
                <li class="<?= ($current_page == 'backup.php') ? 'active' : ''; ?>">
                    <a href="backup.php"><i class="fas fa-database"></i> Backup</a>
                </li>
            </ul>
        </li>
        <li class="<?= ($current_page == 'index.php') ? 'active' : ''; ?>">
            <a href="index.php" style="color:white;">
                <i class="fas fa-home"></i> Home
            </a>
        </li>
        <li>
            <a href="logout.php" style="color:white;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </li>
    </ul>
</div>

<script>
function toggleSettings() {
    document.getElementById("settings-menu").classList.toggle("active");
}
</script>

<style>
.submenu {
    display: none;
    list-style: none;
    padding-left: 15px;
}
.submenu.active {
    display: block;
}
</style>