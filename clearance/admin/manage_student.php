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
    $student_id = (int)($_POST['student_id'] ?? 0);

    if ($action === 'edit_student') {
        $data = [
            'school_id' => trim($_POST['school_id']),
            'fName' => trim($_POST['fName']),
            'mName' => trim($_POST['mName']),
            'lName' => trim($_POST['lName']),
            'email' => trim($_POST['email']),
            'department_id' => (int)$_POST['department_id'],
            'course' => trim($_POST['course']),
            'year_level' => trim($_POST['year_level']),
            'section_id' => trim($_POST['section_id']),
            'adviser_id' => (int)($_POST['adviser_id'] ?? 0),
            'is_verified' => (int)$_POST['is_verified']
        ];

        if ($adminObj->updateStudent($student_id, $data)) {
            $message = "✅ Student ID {$data['school_id']} successfully updated!";
            $messageType = "success";
        } else {
            $message = "❌ Failed to update student {$data['school_id']}. Email or School ID may already exist.";
            $messageType = "error";
        }

    } elseif ($action === 'soft_delete_student') {
        if ($adminObj->softDeleteStudent($student_id)) {
            $message = "✅ Student ID $student_id soft-deleted (account deactivated).";
            $messageType = "success";
        } else {
            $message = "❌ Failed to deactivate student $student_id.";
            $messageType = "error";
        }

    } elseif ($action === 'reset_clearance') {
        $clearance_id = (int)$_POST['clearance_id'];
        if ($adminObj->resetClearance($clearance_id)) {
            $message = "✅ Clearance ID $clearance_id reset to Pending with all signatures cleared.";
            $messageType = "success";
        } else {
            $message = "❌ Failed to reset clearance ID $clearance_id.";
            $messageType = "error";
        }
    }

    header("Location: manage_student.php?msg=" . urlencode($message) . "&type=$messageType");
    exit;
}

$search_term = trim($_GET['search'] ?? '');
$filter_year = $_GET['year'] ?? '';
$filter_course = $_GET['course'] ?? '';
$filter_status = $_GET['status'] ?? '';

$student_records = $adminObj->getStudentManagementRecords($search_term, $filter_year, $filter_course, $filter_status);
$courses = $conn->query("SELECT DISTINCT course FROM student ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);
$years = $conn->query("SELECT DISTINCT year_level FROM student ORDER BY year_level")->fetchAll(PDO::FETCH_COLUMN);
$departments = $conn->query("SELECT department_id, dept_name FROM department ORDER BY dept_name")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['msg']);
    $messageType = htmlspecialchars($_GET['type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Students</title>
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .status-active, .status-pending { color: var(--color-card-pending); font-weight: 700; }
        .status-approved { color: var(--color-card-approved); font-weight: 700; }
        .status-rejected, .status-cancelled { color: var(--color-card-rejected); font-weight: 700; }
        .status-notstarted { color: #888; font-style: italic; }
        .action-link { margin-right: 10px; text-decoration: none; color: var(--color-sidebar-bg); font-size: 1.1em; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); justify-content: center; align-items: center; }
        .modal-content { background-color: #fefefe; padding: 25px; border: 1px solid #888; width: 80%; max-width: 650px; border-radius: 8px; position: relative; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; }
        .close:hover, .close:focus { color: black; text-decoration: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin Panel<i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></h2>
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_clearance.php">Clearance Control</a>
        <a href="manage_departments.php">Departments</a>
        <a href="manage_student.php" class="active">Students</a>
        <a href="manage_orgs.php">Organizations</a>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1>Student Management</h1>
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
                <h3>Student Records (<?= count($student_records) ?>)</h3>

                <div class="filter-controls" style="margin-bottom: 20px;">
                    <form method="GET" action="manage_student.php" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                        <div class="form-group" style="flex: 2;">
                            <label for="search">🔎 Search Name/ID/Email:</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="Name, ID, or Email">
                        </div>
                        <div class="form-group">
                            <label for="year">Year:</label>
                            <select id="year" name="year">
                                <option value="">— Any —</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>>Year <?= $year ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="course">Course:</label>
                            <select id="course" name="course">
                                <option value="">— Any —</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= $course ?>" <?= $filter_course == $course ? 'selected' : '' ?>><?= strtoupper($course) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="status">Status:</label>
                            <select id="status" name="status">
                                <option value="">— All —</option>
                                <?php
                                $statuses = ['Pending', 'Approved', 'Rejected', 'Cancelled', 'Not Started'];
                                foreach ($statuses as $s): ?>
                                    <option value="<?= $s ?>" <?= $filter_status == $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-primary" style="height: 38px; align-self: flex-end;">Filter/Search</button>
                        <a href="manage_student.php" class="btn-primary" style="height: 38px; align-self: flex-end; background-color: grey; text-decoration: none; display: flex; align-items: center;">Reset</a>
                    </form>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Course / Dept</th>
                            <th>Year / Section</th>
                            <th>Clearance ID</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($student_records)): ?>
                            <tr><td colspan="9" style="text-align: center;">No student records found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($student_records as $student):
                            $display_status = $student['clearance_status'] ?? 'Not Started';
                            $status_class = strtolower(str_replace(' ', '', $display_status));
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($student['school_id']) ?></td>
                                <td><?= htmlspecialchars($student['student_name']) ?></td>
                                <td><?= htmlspecialchars($student['email']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars(strtoupper($student['course'])) ?></strong><br>
                                    <small><?= htmlspecialchars($student['dept_name']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($student['year_level']) . ' / ' . htmlspecialchars($student['section_id']) ?></td>
                                <td><?= htmlspecialchars($student['clearance_id'] ?? '—') ?></td>
                                <td class="status-<?= $status_class ?>"><?= htmlspecialchars($display_status) ?></td>
                                <td><?= !empty($student['last_updated_date']) ? date('Y-m-d', strtotime($student['last_updated_date'])) : '-' ?></td>
                                <td>
                                    <a href="#" onclick="fetchStudentForEdit(<?= $student['student_id'] ?>)" class="action-link" title="Edit Student">✏️</a>
                                    <a href="#" onclick="openSoftDeleteStudentModal(<?= $student['student_id'] ?>, '<?= htmlspecialchars($student['student_name'], ENT_QUOTES) ?>')" class="action-link" title="Deactivate Student Account" style="color: red;">🗑</a>

                                    <?php if (!empty($student['clearance_id'])): ?>
                                        <a href="#" onclick="openResetClearanceModal(<?= $student['clearance_id'] ?>, '<?= htmlspecialchars($student['student_name'], ENT_QUOTES) ?>')" class="action-link" title="Reset Current Clearance" style="color: orange;">🔄</a>
                                    <?php endif; ?>

                                    <?php if ($display_status === 'Approved'): ?>
                                        <a href="print_certificate.php?cid=<?= $student['clearance_id'] ?>" target="_blank" class="action-link" title="Print Certificate" style="color: green;">🖨</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="editStudentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editStudentModal')">&times;</span>
            <h3 style="margin-top: 5px;">Edit Student: <span id="edit_student_name"></span></h3>
            <form id="editStudentForm" method="POST" action="manage_student.php">
                <input type="hidden" name="admin_action" value="edit_student">
                <input type="hidden" name="student_id" id="edit_student_id">
                <input type="hidden" name="adviser_id" id="edit_adviser_id" value="0"> <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px;">
                    <div class="form-group" style="flex: 1 1 45%;"><label for="edit_school_id">Student ID:</label><input type="text" name="school_id" id="edit_school_id" required></div>
                    <div class="form-group" style="flex: 1 1 45%;"><label for="edit_email">Email:</label><input type="email" name="email" id="edit_email" required></div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <div class="form-group" style="flex: 1 1 30%;"><label for="edit_fName">First Name:</label><input type="text" name="fName" id="edit_fName" required></div>
                    <div class="form-group" style="flex: 1 1 30%;"><label for="edit_mName">M. Name:</label><input type="text" name="mName" id="edit_mName"></div>
                    <div class="form-group" style="flex: 1 1 30%;"><label for="edit_lName">Last Name:</label><input type="text" name="lName" id="edit_lName" required></div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <div class="form-group" style="flex: 1 1 45%;">
                        <label for="edit_department_id">Department:</label>
                        <select name="department_id" id="edit_department_id" required>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>"><?= htmlspecialchars($dept['dept_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1 1 45%;">
                        <label for="edit_course">Course:</label>
                        <select name="course" id="edit_course" required>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course ?>"><?= strtoupper(htmlspecialchars($course)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <div class="form-group" style="flex: 1 1 30%;"><label for="edit_year_level">Year Level:</label><input type="text" name="year_level" id="edit_year_level" required></div>
                    <div class="form-group" style="flex: 1 1 30%;"><label for="edit_section_id">Section:</label><input type="text" name="section_id" id="edit_section_id" required></div>
                    <div class="form-group" style="flex: 1 1 30%;">
                        <label for="edit_is_active">Status:</label>
                        <select name="is_verified" id="edit_is_active">
                            <option value="1">✅ Active</option>
                            <option value="0">❌ Deactivated</option>
                        </select>
                    </div>
                </div>

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px; display: flex; gap: 10px;">
                    <button type="button" onclick="closeModal('editStudentModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="softDeleteStudentModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeModal('softDeleteStudentModal')">&times;</span>
            <h3 style="margin-top: 5px; color: var(--color-card-rejected);">Deactivate Student Account</h3>
            <p>You are about to soft-delete (deactivate login for) student: <strong id="soft_delete_student_name"></strong>.</p>
            <p style="color: var(--color-card-rejected); font-weight: 600;">⚠️ The student will not be able to log in until an admin reactivates their account.</p>

            <form id="softDeleteStudentForm" method="POST" action="manage_student.php">
                <input type="hidden" name="admin_action" value="soft_delete_student">
                <input type="hidden" name="student_id" id="soft_delete_student_id">

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px; display: flex; gap: 10px;">
                    <button type="button" onclick="closeModal('softDeleteStudentModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary" style="background-color: var(--color-card-rejected);">
                        🗑 Deactivate Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="resetClearanceModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeModal('resetClearanceModal')">&times;</span>
            <h3 style="margin-top: 5px; color: orange;">Reset Clearance</h3>
            <p>Are you sure you want to RESET clearance ID <strong id="reset_clearance_id_display"></strong> for <strong id="reset_student_name"></strong>?</p>
            <p style="color: orange; font-weight: 600;">This will clear all current signatures and set the status back to "Pending".</p>

            <form id="resetClearanceForm" method="POST" action="manage_student.php">
                <input type="hidden" name="admin_action" value="reset_clearance">
                <input type="hidden" name="clearance_id" id="reset_clearance_id_input">

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px; display: flex; gap: 10px;">
                    <button type="button" onclick="closeModal('resetClearanceModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary" style="background-color: orange;">
                        🔄 Reset Clearance
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleNotif() {
            var d = document.getElementById('notifDropdown');
            d.classList.toggle('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function openSoftDeleteStudentModal(id, name) {
            document.getElementById('soft_delete_student_name').innerText = name;
            document.getElementById('soft_delete_student_id').value = id;
            document.getElementById('softDeleteStudentModal').style.display = 'flex';
        }

        function openResetClearanceModal(clearanceId, studentName) {
            document.getElementById('reset_clearance_id_display').innerText = clearanceId;
            document.getElementById('reset_clearance_id_input').value = clearanceId;
            document.getElementById('reset_student_name').innerText = studentName;
            document.getElementById('resetClearanceModal').style.display = 'flex';
        }

        function fetchStudentForEdit(studentId) {
            const modal = document.getElementById('editStudentModal');
            document.getElementById('edit_student_name').innerText = 'Loading...';
            modal.style.display = 'flex';

            const studentRecords = <?= json_encode($student_records) ?>;
            const student = studentRecords.find(s => parseInt(s.student_id) === parseInt(studentId));

            if (student) {
                document.getElementById('edit_student_id').value = student.student_id;
                document.getElementById('edit_student_name').innerText = student.student_name;
                document.getElementById('edit_school_id').value = student.school_id;
                document.getElementById('edit_email').value = student.email;

                if (student.fName && student.lName) {
                    document.getElementById('edit_fName').value = student.fName;
                    document.getElementById('edit_mName').value = student.mName || '';
                    document.getElementById('edit_lName').value = student.lName;
                } else {
                    const parts = student.student_name.split(' ');
                    document.getElementById('edit_fName').value = parts[0] || '';
                    document.getElementById('edit_lName').value = parts.length > 1 ? parts[parts.length - 1] : '';
                    document.getElementById('edit_mName').value = '';
                }

                if (student.department_id) {
                    document.getElementById('edit_department_id').value = student.department_id;
                }

                document.getElementById('edit_course').value = student.course;
                document.getElementById('edit_year_level').value = student.year_level;
                document.getElementById('edit_section_id').value = student.section_id;

                document.getElementById('edit_is_active').value = (student.is_verified !== undefined) ? student.is_verified : 1;

            } else {
                alert("Error: Student record not found in local data.");
                closeModal('editStudentModal');
            }
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
            if (!event.target.closest('.notification-bell-btn') && !event.target.closest('.notification-dropdown-content')) {
                var d = document.getElementById('notifDropdown');
                if (d && d.classList.contains('show')) d.classList.remove('show');
            }
        }
    </script>
</body>
</html>