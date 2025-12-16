<?php
session_start();
require_once "../classes/Database.php";
require_once "../classes/Admin.php";
require_once "../classes/Account.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$db = new Database();
$conn = $db->connect();
$adminObj = new Admin();

$profile_msg = "";
$profile_msg_type = "";

if (isset($_POST['update_profile'])) {
    $new_email = trim($_POST['admin_email']);
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    if (!empty($new_pass) && $new_pass !== $confirm_pass) {
        $profile_msg = "❌ Passwords do not match.";
        $profile_msg_type = "error";
    } else {
        $accountObj = new Account();
        try {
            $p_to_update = !empty($new_pass) ? $new_pass : null;

            if ($accountObj->updateAccountDetails($_SESSION['account_id'], $new_email, $p_to_update)) {
                $profile_msg = "✅ Admin profile updated successfully.";
                $profile_msg_type = "success";
            } else {
                $profile_msg = "❌ Failed to update profile. Email might be taken.";
                $profile_msg_type = "error";
            }
        } catch (Exception $e) {
            $profile_msg = "❌ Error: " . $e->getMessage();
            $profile_msg_type = "error";
        }
    }
}

$stmt_email = $conn->prepare("SELECT email FROM account WHERE account_id = :aid");
$stmt_email->execute([':aid' => $_SESSION['account_id']]);
$current_admin_email = $stmt_email->fetchColumn();

if (isset($_POST['delete_one']) && isset($_POST['mark_read_id'])) {
    $adminObj->clearNotification($_POST['mark_read_id']);
    session_write_close();
    header("Location: dashboard.php");
    exit;
}

if (isset($_POST['mark_read_id']) && !isset($_POST['delete_one'])) {
    $adminObj->markNotificationRead($_POST['mark_read_id']);
    session_write_close();
    header("Location: dashboard.php");
    exit;
}

if (isset($_POST['mark_all_read'])) {
    $adminObj->markAllNotificationsRead();
    session_write_close();
    header("Location: dashboard.php");
    exit;
}

if (isset($_POST['delete_all'])) {
    $adminObj->deleteAllNotifications();
    session_write_close();
    header("Location: dashboard.php");
    exit;
}

$system_stats = $adminObj->getSystemSummary();
$clearance_kpis = $adminObj->getClearanceKpiSummary();
$weekly_data = $adminObj->getWeeklyClearanceActivity();
$notifications = $adminObj->getAdminNotifications();

$notification_count = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) $notification_count++;
}

$card_data = [
    'total_departments' => $system_stats['total_departments'] ?? 0,
    'total_faculty' => $system_stats['total_faculty'] ?? 0,
    'active_faculty' => $system_stats['active_faculty'] ?? 0,
    'total_students' => $system_stats['total_students'] ?? 0,
    'total_requests' => $clearance_kpis['total_requests'] ?? 0,
    'approved_clearance' => $clearance_kpis['approved_clearance'] ?? 0,
    'rejected_cancelled' => $clearance_kpis['rejected_cancelled'] ?? 0,
    'faculty_in_clearance' => $clearance_kpis['faculty_in_clearance'] ?? 0,
];

$request_counts = array_column($weekly_data, 'requests');
$completed_counts = array_column($weekly_data, 'completed');
$max_value = max(max($request_counts), max($completed_counts));
$max_value = $max_value > 0 ? $max_value : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card-image { background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); cursor: pointer; transition: transform 0.2s; }
        .kpi-card-image:hover { transform: translateY(-3px); }
        .metric-value { font-size: 2em; font-weight: 700; color: var(--color-sidebar-bg); margin-bottom: 5px; }
        .metric-label { font-size: 0.9em; color: #555; }
        .bar-chart-container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); cursor: pointer; }
        .bar-chart-placeholder { height: 250px; background: linear-gradient(to top, #e8e8e8, #f8f8f8); border: 1px solid #ccc; border-radius: 4px; display: flex; align-items: flex-end; justify-content: space-around; padding: 10px; font-size: 0.8em; color: #444; position: relative; }
        .bar-group { width: 10%; text-align: center; margin-bottom: 5px; }
        .bar-group div { margin-top: 5px; }
        .bar { width: 30%; transition: height 0.5s; border-radius: 2px 2px 0 0; margin: 0 auto; }
        .bar.requests { background-color: var(--color-header-bg); }
        .bar.completed { background-color: var(--color-accent-green); }
        .bar.failed { background-color: var(--color-card-rejected); }
        .report-link-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 15px; }
        .report-link { background-color: #f0f0f0; border: 1px solid #ccc; padding: 15px; border-radius: 6px; text-decoration: none; color: var(--color-sidebar-bg); font-weight: 600; transition: background-color 0.2s; }
        .report-link:hover { background-color: #e0e0e0; text-decoration: underline; }
        .admin-profile-trigger {
            cursor: pointer;
            transition: color 0.2s;
        }
        .admin-profile-trigger:hover {
            color: var(--color-accent-green);
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2 class="admin-profile-trigger" onclick="toggleAdminModal()" title="Edit Admin Profile">
            Admin Panel <i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i>
        </h2>
        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="manage_clearance.php">Clearance Control</a>
        <a href="manage_departments.php">Departments</a>
        <a href="manage_student.php">Students</a>
        <a href="manage_orgs.php">Organizations</a>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1>Admin Dashboard</h1>
            <div style="display: flex; align-items: center;">

                <div class="notification-icon-container">
                    <button class="notification-bell-btn" onclick="toggleNotif()">
                        <i class="fas fa-bell"></i>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?= $notification_count ?></span>
                        <?php endif; ?>
                    </button>

                    <div id="notifDropdown" class="notification-dropdown-content">
                        <div class="notification-header">System Monitor (<?= $notification_count ?> New)</div>

                        <?php if (!empty($notifications)): ?>
                            <div class="notification-header-actions">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="mark_all_read" value="1">
                                    <button type="submit" class="header-action-btn text-green">Mark All Read</button>
                                </form>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="delete_all" value="1">
                                    <button type="submit" class="header-action-btn text-red">Clear All</button>
                                </form>
                            </div>

                            <?php foreach($notifications as $n): ?>
                                <div class="notification-item <?= $n['is_read'] ? 'read' : 'unread' ?>">

                                    <div style="display:flex; align-items: flex-start; flex-grow: 1;">
                                        <i class="fas <?= $n['icon'] ?> notif-icon" style="color: <?= $n['color'] ?>; margin-top: 3px;"></i>
                                        <div class="notif-content">
                                            <div><?= $n['title'] ?></div>
                                            <?= $n['message'] ?>
                                            <br><small><?= date('M d, H:i', strtotime($n['date'])) ?></small>
                                        </div>
                                    </div>

                                    <div class="action-group">
                                        <?php if (!$n['is_read']): ?>
                                            <form method="POST" action="dashboard.php">
                                                <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                                <button type="submit" class="mark-read-btn-icon" title="Mark as Read">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" action="dashboard.php">
                                            <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                            <input type="hidden" name="delete_one" value="1">
                                            <button type="submit" class="delete-btn-icon" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>

                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 15px; text-align: center; color: #777;">No active notifications.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="../index.php" class="log-out-btn">LOG OUT</a>
            </div>
        </div>

        <div class="page-content-wrapper">
            <?php if (!empty($profile_msg)): ?>
                <div class="alert <?= ($profile_msg_type == 'success') ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 20px;">
                    <?= htmlspecialchars($profile_msg) ?>
                </div>
            <?php endif; ?>

            <div class="kpi-grid">

                <div class="kpi-card-image" onclick="window.location.href='manage_departments.php'" style="cursor: pointer;">
                    <div class="metric-value"><?= htmlspecialchars($card_data['total_departments']) ?></div>
                    <div class="metric-label">Total Departments</div>
                </div>

                <div class="kpi-card-image" onclick="window.location.href='manage_student.php'" style="cursor: pointer;">
                    <div class="metric-value"><?= htmlspecialchars($card_data['total_faculty']) ?></div>
                    <div class="metric-label">Total Faculty</div>
                </div>

                <div class="kpi-card-image" onclick="window.location.href='manage_student.php'" style="cursor: pointer;">
                    <div class="metric-value"><?= htmlspecialchars($card_data['active_faculty']) ?></div>
                    <div class="metric-label">Active Faculty</div>
                </div>

                <div class="kpi-card-image" onclick="window.location.href='manage_student.php'" style="cursor: pointer;">
                    <div class="metric-value"><?= htmlspecialchars($card_data['total_students']) ?></div>
                    <div class="metric-label">Total Students</div>
                </div>

                <div class="kpi-card-image" onclick="window.location.href='manage_clearance.php'" style="cursor: pointer;">
                    <div class="metric-value"><?= htmlspecialchars($card_data['total_requests']) ?></div>
                    <div class="metric-label">Total Clearance Requests</div>
                </div>

                <div class="kpi-card-image" onclick="window.location.href='manage_clearance.php?status=Approved'" style="cursor: pointer;">
                    <div class="metric-value"><?= htmlspecialchars($card_data['approved_clearance']) ?></div>
                    <div class="metric-label">Approved Clearance</div>
                </div>

                <div class="kpi-card-image" onclick="window.location.href='manage_student.php'" style="cursor: pointer;">
                    <div class="metric-value"><?= htmlspecialchars($card_data['faculty_in_clearance']) ?></div>
                    <div class="metric-label">Faculty in Clearance</div>
                </div>

                <div class="kpi-card-image" onclick="window.location.href='manage_clearance.php?status=Rejected'" style="cursor: pointer;">
                    <div class="metric-value"><?= htmlspecialchars($card_data['rejected_cancelled']) ?></div>
                    <div class="metric-label">Rejected / Cancelled Clearance</div>
                </div>

            </div>

            <div class="bar-chart-container" onclick="window.location.href='manage_clearance.php'" style="cursor: pointer;">
                <h2>Weekly Clearance Activity</h2>
                <p style="font-size: 0.9em; color: #555;">Clearance Cycles Started vs. Completed Clearances.</p>

                <div class="bar-chart-placeholder">
                    <div style="position: absolute; top: 10px; right: 30px; font-size: 0.9em;">
                        <span style="color: var(--color-header-bg);">■ Requests (Started)</span> |
                        <span style="color: var(--color-accent-green);">■ Completed (Approved)</span>
                    </div>

                    <?php
                    foreach ($weekly_data as $data):
                        $requests = (int)$data['requests'];
                        $completed = (int)$data['completed'];

                        $req_height = ($requests / $max_value) * 90;
                        $comp_height = ($completed / $max_value) * 90;
                        $failed = (int)($data['failed'] ?? 0);
                        $fail_height = ($failed / $max_value) * 90;
                    ?>
                        <div class="bar-group">
                            <div style="display: flex; height: 180px; align-items: flex-end; justify-content: center; gap: 4px;">
                                <div class="bar requests" style="height: <?= $req_height ?>%;"></div>
                                <div class="bar completed" style="height: <?= $comp_height ?>%;"></div>
                                <div class="bar failed" style="height: <?= $fail_height ?>%;"></div>
                            </div>
                            <div><?= htmlspecialchars($data['day']) ?></div>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>

            <div class="container" style="margin-top: 30px;">
                <h2>Printable Reports</h2>
                <div class="report-link-container">

                    <a href="reports/completed_list.php" class="report-link">
                        ✅ Completed Student
                    </a>

                    <a href="reports/pending_list.php" class="report-link">
                        ⏳Pending Clearance List
                    </a>

                    <a href="reports/dept_summary.php" class="report-link">
                        🏛️ Department Clearance Summary
                    </a>

                    <a href="reports/faculty_report.php" class="report-link">
                        👤 Faculty / Signer Report
                    </a>

                </div>
            </div>

        </div>
    </div>

    <div id="adminProfileModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="toggleAdminModal()">&times;</span>
            <h2 style="color: var(--color-sidebar-bg); border-bottom: 2px solid var(--color-accent-green); padding-bottom: 10px; margin-bottom: 20px;">
                <i class="fas fa-user-shield"></i> Edit Admin Profile
            </h2>

            <form method="POST" action="dashboard.php">
                <input type="hidden" name="update_profile" value="1">

                <div class="form-group">
                    <label>Admin Email</label>
                    <input type="email" name="admin_email" value="<?= htmlspecialchars($current_admin_email) ?>" required>
                </div>

                <div class="form-group">
                    <label>New Password <small style="color:#777; font-weight:normal;">(Leave blank to keep current)</small></label>
                    <input type="password" name="new_password" placeholder="Enter new password">
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" placeholder="Confirm new password">
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn-primary" onclick="toggleAdminModal()" style="background-color: #777; margin-right: 10px;">Cancel</button>
                    <button type="submit" class="btn-primary">Update Profile</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function toggleNotif() {
            document.getElementById('notifDropdown').classList.toggle('show');
        }
       function toggleAdminModal() {
            var modal = document.getElementById('adminProfileModal');
            if (modal.style.display === "flex") {
                modal.style.display = "none";
            } else {
                modal.style.display = "flex";
            }
        }

        window.onclick = function(e) {
            if (!e.target.closest('.notification-bell-btn') && !e.target.closest('.notification-dropdown-content')) {
                var d = document.getElementById('notifDropdown');
                if (d.classList.contains('show')) d.classList.remove('show');
            }
            var modal = document.getElementById('adminProfileModal');
            if (e.target == modal) {
                modal.style.display = "none";
            }
        }
    </script>
</body>
</html>