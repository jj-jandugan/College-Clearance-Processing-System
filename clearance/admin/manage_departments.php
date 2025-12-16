<?php
session_start();
require_once "../classes/Database.php";
require_once "../classes/Admin.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$db = new Database();
$conn = $db->connect();
$adminObj = new Admin();

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

$notifications = $adminObj->getAdminNotifications();
$notification_count = 0;
foreach ($notifications as $n) { if (!$n['is_read']) $notification_count++; }

$message = "";
$messageType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['admin_action'] ?? '';

    if ($action === 'add_dept') {
        $dept_name = trim($_POST['dept_name']);
        if ($adminObj->addDepartment($dept_name)) {
            $message = "✅ Department added successfully!";
            $messageType = "success";
        } else {
            $message = "❌ Error adding department. Name may already exist.";
            $messageType = "error";
        }
    } elseif ($action === 'edit_dept') {
        $dept_id = (int)$_POST['dept_id'];
        $dept_name = trim($_POST['dept_name_edit']);
        if ($adminObj->updateDepartment($dept_id, $dept_name)) {
            $message = "✅ Department ID $dept_id successfully updated!";
            $messageType = "success";
        } else {
            $message = "❌ Failed to update department ID $dept_id. Name may already exist.";
            $messageType = "error";
        }
    } elseif ($action === 'delete_dept') {
        $dept_id = (int)$_POST['dept_id'];
        if ($adminObj->deleteDepartment($dept_id)) {
            $message = "✅ Department ID $dept_id successfully deleted. Faculty assignments were adjusted.";
            $messageType = "success";
        } else {
            $message = "❌ Failed to delete department ID $dept_id. Check system dependencies.";
            $messageType = "error";
        }
    } elseif ($action === 'update_special_faculty') {
        $fid = (int)$_POST['faculty_id'];
        $current = $adminObj->getFacultyEditDetails($fid);

        $data = [
            'fName' => trim($_POST['fName']),
            'mName' => trim($_POST['mName']),
            'lName' => trim($_POST['lName']),
            'email' => trim($_POST['email']),
            'position' => trim($_POST['position']),
            'department_id' => $current['department_id'],
            'course_assigned' => null,
            'is_verified' => (int)$_POST['is_verified']
        ];

        if ($adminObj->updateFaculty($fid, $data)) {
            $message = "✅ Faculty member details updated.";
            $messageType = "success";
        } else {
            $message = "❌ Failed to update faculty member.";
            $messageType = "error";
        }
    } elseif ($action === 'toggle_special_status') {
        $fid = (int)$_POST['faculty_id'];
        $target_active = (int)$_POST['target_active'];

        if ($target_active) {
            $success = $adminObj->activateFaculty($fid);
            $msg_text = "activated";
        } else {
            $success = $adminObj->deactivateFaculty($fid);
            $msg_text = "deactivated";
        }

        if ($success) {
            $message = "✅ Faculty member successfully $msg_text.";
            $messageType = "success";
        } else {
            $message = "❌ Error changing faculty status.";
            $messageType = "error";
        }
    }

    header("Location: manage_departments.php?msg=" . urlencode($message) . "&type=$messageType");
    exit;
}

$stats = $adminObj->getSystemSummary();
$departments = $adminObj->getAllDepartmentsWithSummary();
$special_faculty = $adminObj->getSpecialFaculty();

if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
    $messageType = htmlspecialchars($_GET['type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Departments & Admin</title>
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .status-active { color: var(--color-accent-green); }
        .status-inactive { color: var(--color-card-rejected); }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin Panel<i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></h2>
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_clearance.php">Clearance Control</a>
        <a href="manage_departments.php" class="active">Departments</a>
        <a href="manage_student.php">Students</a>
        <a href="manage_orgs.php">Organizations</a>

    </div>

    <div class="main-content">
        <div class="page-header">
            <h1>Department & Faculty Management</h1>
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
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                                <button type="submit" class="mark-read-btn-icon" title="Mark as Read"><i class="fas fa-check-circle"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                            <input type="hidden" name="delete_one" value="1">
                                            <button type="submit" class="delete-btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
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
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
            <?php endif; ?>

            <div class="container">
                <h3>Add New Department</h3>
                <form method="POST" action="manage_departments.php">
                    <input type="hidden" name="admin_action" value="add_dept">
                    <div class="cycle-controls" style="gap: 15px;">
                        <div class="form-group" style="flex-grow: 1;">
                            <input type="text" name="dept_name" placeholder="Department Name (e.g., Computer Science)" required>
                        </div>
                        <button type="submit" class="btn-primary" style="width: 150px;">Add Department</button>
                    </div>
                </form>
            </div>

            <div class="container" style="margin-top: 25px;">
                <h3>Administrative Faculty (Dean / SA Coordinator)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($special_faculty)): ?>
                            <tr><td colspan="5" style="text-align: center;">No administrative faculty found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($special_faculty as $sf):
                            $status_text = $sf['is_active'] ? 'Active' : 'Inactive';
                            $status_class = $sf['is_active'] ? 'status-active' : 'status-inactive';
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($sf['fName'] . ' ' . $sf['lName']) ?></td>
                                <td><?= htmlspecialchars($sf['email']) ?></td>
                                <td><?= htmlspecialchars($sf['position']) ?></td>
                                <td class="<?= $status_class ?>"><?= $status_text ?></td>
                                <td>
                                    <a href="#" onclick="openEditSpecialModal(<?= htmlspecialchars(json_encode($sf)) ?>)" class="action-link" title="Edit Details">✏️</a>

                                    <form method="POST" action="manage_departments.php" style="display:inline;" onsubmit="return confirm('Confirm action for <?= htmlspecialchars($sf['lName']) ?>?');">
                                        <input type="hidden" name="admin_action" value="toggle_special_status">
                                        <input type="hidden" name="faculty_id" value="<?= $sf['faculty_id'] ?>">

                                        <?php if ($sf['is_active']): ?>
                                            <input type="hidden" name="target_active" value="0">
                                            <button type="submit" class="action-link" title="Deactivate / Remove" style="color: var(--color-card-rejected); border: none; background: none; cursor: pointer;">❌</button>
                                        <?php else: ?>
                                            <input type="hidden" name="target_active" value="1">
                                            <button type="submit" class="action-link" title="Activate" style="color: var(--color-accent-green); border: none; background: none; cursor: pointer;">✅</button>
                                        <?php endif; ?>
                                    </form>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="container" style="margin-top: 25px;">
                <h3>Department List</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Department ID</th>
                            <th>Department Name</th>
                            <th>Head</th>
                            <th>Total Faculty</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($departments)): ?>
                            <tr><td colspan="5" style="text-align: center;">No departments found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($departments as $dept): ?>
                            <tr>
                                <td data-label="id"><?= htmlspecialchars($dept['department_id']) ?></td>
                                <td data-label="name"><?= htmlspecialchars($dept['dept_name']) ?></td>
                                <td><?= htmlspecialchars($dept['head_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($dept['total_faculty'] ?? 0) ?></td>
                                <td>
                                    <a href="faculty_list.php?dept_id=<?= $dept['department_id'] ?>" class="action-link" title="View Faculty">👁</a> /
                                    <a href="#" onclick="openEditDeptModal(<?= $dept['department_id'] ?>, '<?= htmlspecialchars($dept['dept_name'], ENT_QUOTES) ?>')" class="action-link" title="Edit Department">✏️</a> /
                                    <a href="#"
                                       onclick="openDeleteDeptModal(<?= $dept['department_id'] ?>, '<?= htmlspecialchars($dept['dept_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($dept['total_faculty'] ?? 0, ENT_QUOTES) ?>')"
                                       class="action-link"
                                       title="Delete Department"
                                       style="color: var(--color-card-rejected);">
                                       🗑
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="editDeptModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeModal('editDeptModal')">&times;</span>
            <h3 style="margin-top: 5px;">Edit Department</h3>
            <form method="POST" action="manage_departments.php">
                <input type="hidden" name="admin_action" value="edit_dept">
                <input type="hidden" name="dept_id" id="edit_dept_id">
                <div class="form-group" style="margin-top: 15px;">
                    <label for="dept_name_edit">Department Name:</label>
                    <input type="text" name="dept_name_edit" id="dept_name_edit" required>
                </div>
                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('editDeptModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteDeptModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeModal('deleteDeptModal')">&times;</span>
            <h3 style="margin-top: 5px; color: var(--color-card-rejected);">Delete Department</h3>
            <p>You are about to PERMANENTLY DELETE the department: <strong id="delete_dept_name"></strong> (ID: <span id="delete_dept_id_display"></span>).</p>
            <p style="color: red; font-weight: 600;">⚠️ This action is irreversible and will affect <strong id="delete_dept_faculty_count"></strong> faculty records!</p>
            <form id="deleteDeptForm" method="POST" action="manage_departments.php">
                <input type="hidden" name="admin_action" value="delete_dept">
                <input type="hidden" name="dept_id" id="delete_dept_id_input">
                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('deleteDeptModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary" style="background-color: var(--color-card-rejected);" onclick="return confirm('FINAL CONFIRMATION: Are you absolutely sure?');">
                        🗑 Permanently Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="editSpecialModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close" onclick="closeModal('editSpecialModal')">&times;</span>
            <h3 style="margin-top: 5px;">Edit Administrative Faculty</h3>
            <form method="POST" action="manage_departments.php">
                <input type="hidden" name="admin_action" value="update_special_faculty">
                <input type="hidden" name="faculty_id" id="sf_id">

                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <div class="form-group" style="flex: 1;"><label>First Name:</label><input type="text" name="fName" id="sf_fName" required></div>
                    <div class="form-group" style="flex: 1;"><label>Last Name:</label><input type="text" name="lName" id="sf_lName" required></div>
                </div>
                <div class="form-group"><label>Middle Name:</label><input type="text" name="mName" id="sf_mName" placeholder="Optional"></div>
                <div class="form-group"><label>Email:</label><input type="email" name="email" id="sf_email" required></div>

                <div class="form-group">
                    <label>Position:</label>
                    <select name="position" id="sf_position" required>
                        <option value="Dean">Dean</option>
                        <option value="SA Coordinator">SA Coordinator</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Status:</label>
                    <select name="is_verified" id="sf_status">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('editSpecialModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>

<script>
    function toggleNotif() {
        var d = document.getElementById('notifDropdown');
        d.classList.toggle('show');
    }

function openEditDeptModal(id, name) {
    document.getElementById('edit_dept_id').value = id;
    document.getElementById('dept_name_edit').value = name;
    document.getElementById('editDeptModal').style.display = 'flex';
}

function openDeleteDeptModal(id, name, count) {
    document.getElementById('delete_dept_id_display').innerText = id;
    document.getElementById('delete_dept_name').innerText = name;
    document.getElementById('delete_dept_id_input').value = id;
    document.getElementById('delete_dept_faculty_count').innerText = count;
    document.getElementById('deleteDeptModal').style.display = 'flex';
}

function openEditSpecialModal(data) {
    document.getElementById('sf_id').value = data.faculty_id;
    document.getElementById('sf_fName').value = data.fName;
    document.getElementById('sf_lName').value = data.lName;
    document.getElementById('sf_mName').value = data.mName || '';
    document.getElementById('sf_email').value = data.email;
    document.getElementById('sf_position').value = data.position;
    document.getElementById('sf_status').value = data.is_active;
    document.getElementById('editSpecialModal').style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

window.onclick = function(event) {
        if (event.target.classList.contains('modal')) { event.target.style.display = 'none'; }
        if (!event.target.closest('.notification-bell-btn') && !event.target.closest('.notification-dropdown-content')) {
            var d = document.getElementById('notifDropdown');
            if (d && d.classList.contains('show')) d.classList.remove('show');
        }
    }
</script>
</body>
</html>