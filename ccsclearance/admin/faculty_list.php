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
$notifications = $adminObj->getAdminNotifications();

$dept_id = filter_input(INPUT_GET, 'dept_id', FILTER_VALIDATE_INT);
if (!$dept_id) {
    header("Location: manage_departments.php");
    exit();
}

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

$department_info = $adminObj->getDepartmentById($dept_id);
if (!$department_info) {
    die("Department not found.");
}
$dept_name = htmlspecialchars($department_info['dept_name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $faculty_action = $_POST['faculty_action'] ?? '';

    if ($faculty_action === 'remove') {
        $fid = (int)$_POST['faculty_id'];
        if ($adminObj->deactivateFaculty($fid)) {
            $message = "✅ Faculty member successfully deactivated/removed.";
            $messageType = "success";
        } else {
            $message = "❌ Error deactivating faculty member.";
            $messageType = "error";
        }
    } elseif ($faculty_action === 'activate') {
        $fid = (int)$_POST['faculty_id'];
        if ($adminObj->activateFaculty($fid)) {
            $message = "✅ Faculty member successfully activated.";
            $messageType = "success";
        } else {
            $message = "❌ Error activating faculty member.";
            $messageType = "error";
        }
    } elseif ($faculty_action === 'transfer_faculty') {
        $fid = (int)$_POST['faculty_id'];
        $new_dept_id = (int)$_POST['new_department_id'];
        if ($adminObj->transferFaculty($fid, $new_dept_id)) {
            $message = "✅ Faculty member successfully transferred to another department.";
            $messageType = "success";
        } else {
            $message = "❌ Error transferring faculty member.";
            $messageType = "error";
        }
    } elseif ($faculty_action === 'edit_faculty') {
        $fid = (int)$_POST['faculty_id'];
        $position = trim($_POST['position']);

        $data = [
            'fName' => trim($_POST['fName']),
            'mName' => trim($_POST['mName']),
            'lName' => trim($_POST['lName']),
            'email' => trim($_POST['email']),
            'position' => $position,
            'department_id' => (int)$_POST['department_id'],
            'course_assigned' => trim($_POST['course_assigned'] ?? ''),
            'is_verified' => (int)$_POST['is_verified']
        ];

        if ($adminObj->updateFaculty($fid, $data)) {
            $message = "✅ Faculty member ID $fid successfully updated.";
            $messageType = "success";
        } else {
            $message = "❌ Failed to update faculty member ID $fid. Email may already exist.";
            $messageType = "error";
        }
    } elseif ($faculty_action === 'add_faculty') {
        $fName = trim($_POST['fName']);
        $mName = trim($_POST['mName']);
        $lName = trim($_POST['lName']);
        $temp_password = trim($_POST['temp_password']);
        $position = trim($_POST['position']);
        $course_assigned = trim($_POST['course_assigned'] ?? '');
        $course_required = ($position == "Adviser");

        if (empty($fName) || empty($lName) || empty($temp_password) || empty($position)) {
            $message = "❌ Missing required fields.";
            $messageType = "error";
        } elseif ($course_required && empty($course_assigned)) {
            $message = "❌ Advisers must be assigned a course.";
            $messageType = "error";
        } else {
            $res = $adminObj->provisionFaculty($fName, $mName, $lName, $dept_id, $position, $temp_password, $course_assigned);
            if ($res === true) {
                $message = "✅ New faculty member $fName $lName added successfully.";
                $messageType = "success";
            } else {
                $message = "❌ Error adding faculty: $res";
                $messageType = "error";
            }
        }
    }


    header("Location: faculty_list.php?dept_id=$dept_id&msg=" . urlencode($message) . "&type=$messageType");
    exit;
}

$faculty_list = $adminObj->getFacultyByDepartment($dept_id);

foreach ($faculty_list as &$faculty) {
    if (isset($faculty['name'])) {
        $nameParts = explode(' ', trim($faculty['name']));
        $faculty['fName'] = $nameParts[0] ?? '';
        $faculty['lName'] = end($nameParts) ?? '';
        $faculty['mName'] = (count($nameParts) > 2) ? implode(' ', array_slice($nameParts, 1, -1)) : '';
    }
}
unset($faculty);

$all_departments = $adminObj->getAllDepartmentsWithSummary();
$courses_query = $conn->query("SELECT DISTINCT course FROM student ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);

$dept_name_lower = strtolower($dept_name);
if (empty($courses_query)) {
    if (strpos($dept_name_lower, 'computer science') !== false || strpos($dept_name_lower, 'cs') !== false) {
        $courses_query = ['CS', 'ACT'];
    } elseif (strpos($dept_name_lower, 'information technology') !== false || strpos($dept_name_lower, 'it') !== false) {
        $courses_query = ['IT', 'Networking', 'AppDev'];
    } else {
        $courses_query = ['CS', 'IT'];
    }
}

$all_positions = ['Adviser', 'Department Head', 'Dean', 'SA Coordinator', 'Librarian', 'Registrar'];


if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
    $messageType = htmlspecialchars($_GET['type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Faculty: <?= $dept_name ?></title>
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .status-active { color: var(--color-accent-green); }
        .status-inactive { color: var(--color-card-rejected); }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); justify-content: center; align-items: center; }
        .modal-content { background-color: #fefefe; padding: 20px; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 8px; position: relative; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; }
        .close:hover, .close:focus { color: black; text-decoration: none; cursor: pointer; }

        .pos-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .pos-table th, .pos-table td { border-bottom: 1px solid #ddd; padding: 8px; text-align: left; }
        .pos-table th { background-color: #f2f2f2; }
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
            <h1><?= $dept_name ?> Faculty Management</h1>
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
            <a href="manage_departments.php" class="action-link" style="margin-bottom: 20px; display: inline-block;">← Back to Departments</a>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
            <?php endif; ?>

            <div class="container">
                <h3>Faculty Members (<?= count($faculty_list) ?>)</h3>

                <div class="form-group" style="margin-bottom: 25px; display: flex; gap: 10px;">
                    <button class="btn-primary" onclick="openAddFacultyModal()">+ Add New Faculty</button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Faculty ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($faculty_list)): ?>
                            <tr><td colspan="6" style="text-align: center;">No faculty members found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($faculty_list as $faculty):
                            $status_text = $faculty['is_active'] ? 'Active' : 'Inactive';
                            $status_class = $faculty['is_active'] ? 'status-active' : 'status-inactive';
                        ?>
                            <tr>
                                <td data-fid="<?= $faculty['faculty_id'] ?>"><?= htmlspecialchars($faculty['faculty_id']) ?></td>
                                <td data-fname="<?= htmlspecialchars($faculty['fName']) ?>" data-lname="<?= htmlspecialchars($faculty['lName']) ?>"><?= htmlspecialchars($faculty['fName'] . ' ' . $faculty['lName']) ?></td>
                                <td data-email="<?= htmlspecialchars($faculty['email']) ?>"><?= htmlspecialchars($faculty['email']) ?></td>
                                <td data-position="<?= htmlspecialchars($faculty['position']) ?>"><?= htmlspecialchars($faculty['position']) ?></td>
                                <td class="<?= $status_class ?>" data-active="<?= $faculty['is_active'] ?>"><?= $status_text ?></td>
                                <td>
                                    <a href="#" onclick="fetchFacultyForEdit(<?= $faculty['faculty_id'] ?>)" class="action-link" title="Edit Faculty">✏️</a>
                                    <a href="#" onclick="openTransferModal(<?= $faculty['faculty_id'] ?>, '<?= htmlspecialchars($faculty['fName'] . ' ' . $faculty['lName'], ENT_QUOTES) ?>')" class="action-link" title="Transfer Department" style="color: #FF9800;">⇄</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Confirm action for <?= htmlspecialchars($faculty['lName']) ?>?');">
                                        <input type="hidden" name="faculty_id" value="<?= $faculty['faculty_id'] ?>">
                                        <?php if ($faculty['is_active']): ?>
                                            <input type="hidden" name="faculty_action" value="remove">
                                            <button type="submit" class="action-link" title="Remove/Deactivate" style="color: var(--color-card-rejected); border: none; background: none; cursor: pointer;">❌</button>
                                        <?php else: ?>
                                            <input type="hidden" name="faculty_action" value="activate">
                                            <button type="submit" class="action-link" title="Activate" style="color: var(--color-accent-green); border: none; background: none; cursor: pointer;">✅</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <div id="addFacultyModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addFacultyModal')">&times;</span>
            <h3 style="margin-top: 5px;">Add New Faculty</h3>
            <form method="POST" action="faculty_list.php?dept_id=<?= $dept_id ?>">
                <input type="hidden" name="faculty_action" value="add_faculty">

                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <div class="form-group" style="flex: 1;"><label for="add_fName">First Name:</label><input type="text" name="fName" id="add_fName" required></div>
                    <div class="form-group" style="flex: 1;"><label for="add_mName">MI:</label><input type="text" name="mName" id="add_mName"></div>
                </div>
                <div class="form-group"><label for="add_lName">Last Name:</label><input type="text" name="lName" id="add_lName" required></div>

                <div class="form-group"><label for="add_temp_password">Temp Password:</label><input type="text" name="temp_password" id="add_temp_password" required></div>

                <div class="form-group">
                    <label for="add_position">Position:</label>
                    <select name="position" id="add_position" onchange="toggleCourseAssignedField('add_position', 'add_course_assigned_group')" required>
                        <option value="">Select Position</option>
                        <?php foreach ($all_positions as $pos): ?>
                            <option value="<?= htmlspecialchars($pos) ?>"><?= htmlspecialchars($pos) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="add_course_assigned_group" style="display: none;">
                    <label for="add_course_assigned">Course Assigned (Adviser Only):</label>
                    <select name="course_assigned" id="add_course_assigned">
                        <option value="">Select Course</option>
                        <?php foreach ($courses_query as $course): ?>
                            <option value="<?= htmlspecialchars($course) ?>"><?= strtoupper(htmlspecialchars($course)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('addFacultyModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>

    <div id="transferFacultyModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeModal('transferFacultyModal')">&times;</span>
            <h3 style="margin-top: 5px;">Transfer Faculty</h3>
            <p>Transfer <strong id="transfer_faculty_name"></strong> to:</p>
            <form method="POST" action="faculty_list.php?dept_id=<?= $dept_id ?>">
                <input type="hidden" name="faculty_action" value="transfer_faculty">
                <input type="hidden" name="faculty_id" id="transfer_faculty_id">

                <div class="form-group">
                    <label for="new_department_id">New Department:</label>
                    <select name="new_department_id" id="new_department_id" required>
                        <option value="">Select Department</option>
                        <?php foreach ($all_departments as $dept): ?>
                            <?php if ($dept['department_id'] != $dept_id):?>
                                <option value="<?= $dept['department_id'] ?>"><?= htmlspecialchars($dept['dept_name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('transferFacultyModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary" style="background-color: #FF9800;">Transfer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editFacultyModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editFacultyModal')">&times;</span>
            <h3 style="margin-top: 5px;">Edit Faculty Member: <span id="edit_faculty_name"></span></h3>
            <form method="POST" action="faculty_list.php?dept_id=<?= $dept_id ?>">
                <input type="hidden" name="faculty_action" value="edit_faculty">
                <input type="hidden" name="faculty_id" id="edit_faculty_id">
                <input type="hidden" name="department_id" value="<?= $dept_id ?>" id="edit_department_id">

                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <div class="form-group" style="flex: 1;"><label for="edit_fName">First Name:</label><input type="text" name="fName" id="edit_fName" required></div>
                    <div class="form-group" style="flex: 1;"><label for="edit_lName">Last Name:</label><input type="text" name="lName" id="edit_lName" required></div>
                </div>

                <div class="form-group"><label for="edit_email">Email:</label><input type="email" name="email" id="edit_email" required></div>

                <div class="form-group">
                    <label for="edit_position">Position:</label>
                    <select name="position" id="edit_position" onchange="toggleCourseAssignedField('edit_position', 'edit_course_assigned_group')" required>
                        <?php foreach ($all_positions as $pos): ?>
                            <option value="<?= htmlspecialchars($pos) ?>"><?= htmlspecialchars($pos) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="edit_course_assigned_group" style="display: none;">
                    <label for="edit_course_assigned">Course Assigned (Adviser Only):</label>
                    <select name="course_assigned" id="edit_course_assigned">
                        <option value="">Select Course</option>
                        <?php foreach ($courses_query as $course): ?>
                            <option value="<?= htmlspecialchars($course) ?>"><?= strtoupper(htmlspecialchars($course)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_is_active">Active Status:</label>
                    <select name="is_verified" id="edit_is_active">
                        <option value="1">✅ Active</option>
                        <option value="0">❌ Inactive</option>
                    </select>
                </div>

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('editFacultyModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
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

    function openAddFacultyModal() {
        document.getElementById('addFacultyModal').style.display = 'flex';
        toggleCourseAssignedField('add_position', 'add_course_assigned_group');
    }

    function openTransferModal(id, name) {
        document.getElementById('transfer_faculty_id').value = id;
        document.getElementById('transfer_faculty_name').innerText = name;
        document.getElementById('transferFacultyModal').style.display = 'flex';
    }

    function fetchFacultyForEdit(facultyId) {
        const facultyList = <?= json_encode($faculty_list) ?>;
        const faculty = facultyList.find(f => parseInt(f.faculty_id) === parseInt(facultyId));

        if (faculty) {
            document.getElementById('edit_faculty_id').value = faculty.faculty_id;
            document.getElementById('edit_faculty_name').innerText = `${faculty.fName} ${faculty.lName}`;
            document.getElementById('edit_fName').value = faculty.fName;
            document.getElementById('edit_lName').value = faculty.lName;
            if (document.getElementById('edit_mName')) {
                document.getElementById('edit_mName').value = faculty.mName || '';
            }
            document.getElementById('edit_email').value = faculty.email;
            document.getElementById('edit_position').value = faculty.position;
            document.getElementById('edit_is_active').value = faculty.is_active;

            const courseAssignedSelect = document.getElementById('edit_course_assigned');
            if (courseAssignedSelect) {
                courseAssignedSelect.value = faculty.course_assigned || '';
            }

            toggleCourseAssignedField('edit_position', 'edit_course_assigned_group');
            document.getElementById('editFacultyModal').style.display = 'flex';
        } else {
            alert('Error: Faculty record not found.');
        }
    }

    function toggleCourseAssignedField(selectId, groupId) {
        const positionSelect = document.getElementById(selectId);
        const group = document.getElementById(groupId);
        const val = positionSelect.value;
        if (val === 'Adviser') {
            group.style.display = 'block';
        } else {
            group.style.display = 'none';
        }
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