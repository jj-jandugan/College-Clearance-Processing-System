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

$dept_id = filter_input(INPUT_GET, 'dept_id', FILTER_VALIDATE_INT);
if (!$dept_id) {
    header("Location: manage_departments.php");
    exit;
}

$department_info = $adminObj->getDepartmentById($dept_id);
$dept_name = htmlspecialchars($department_info['dept_name'] ?? 'Unknown Department');

$message = "";
$messageType = "";

$fName = $mName = $lName = $temp_password = $position = $course_assigned = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fName = trim($_POST['fName'] ?? '');
    $mName = trim($_POST['mName'] ?? '');
    $lName = trim($_POST['lName'] ?? '');
    $temp_password = trim($_POST['temp_password'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $course_assigned = trim($_POST['course_assigned'] ?? '');

    $course_required = ($position == "Adviser");

    if (empty($fName) || empty($lName) || empty($temp_password) || empty($position)) {
        $message = "❌ First Name, Last Name, Temporary Password, and Position are required.";
        $messageType = "error";
    } elseif ($course_required && empty($course_assigned)) {
        $message = "❌ Advisers must be assigned a course.";
        $messageType = "error";
    } elseif (!empty($course_assigned) && !preg_match('/^[A-Za-z0-9 _-]{1,50}$/', $course_assigned)) {
        $message = "❌ Course contains invalid characters or is too long (max 50 chars). Use letters, numbers, spaces, dash or underscore.";
        $messageType = "error";
    } else {
        $course_assigned = $course_assigned !== '' ? strtolower($course_assigned) : null;
        $result = $adminObj->provisionFaculty($fName, $mName, $lName, $dept_id, $position, $temp_password, $course_assigned);
        if ($result === true) {
            $message = "✅ Faculty account for $fName $lName provisioned with Temporary Password" . htmlspecialchars($temp_password) . ". The user must now log in to finalize their account setup.";
            $messageType = "success";
            $fName = $mName = $lName = $temp_password = $position = $course_assigned = '';
        } else {
            $message = "❌ Provisioning failed: $result";
            $messageType = "error";
        }
    }
}

$courses_query = $conn->query("SELECT DISTINCT course FROM student ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);
$courses_query = array_filter($courses_query, function($c) { return trim((string)$c) !== ''; });

$default_courses_by_dept = [
    'cs' => ['cs', 'act'],
    'computer' => ['cs', 'act'],
    'it' => ['it', 'networking', 'appdev'],
    'information' => ['it', 'networking', 'appdev'],
];

$dept_key = strtolower($dept_name);
$defaults_to_add = [];
foreach ($default_courses_by_dept as $key => $defaults) {
    if (stripos($dept_key, $key) !== false) {
        $defaults_to_add = $defaults;
        break;
    }
}

$courses_normalized = array_map('strtolower', $courses_query);
$courses_normalized = array_filter($courses_normalized, function($c) { return trim((string)$c) !== ''; });
foreach ($defaults_to_add as $d) {
    if (!in_array(strtolower($d), $courses_normalized)) {
        $courses_normalized[] = strtolower($d);
    }
}

sort($courses_normalized);

$courses_list = array_values($courses_normalized);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Provision Faculty: <?= $dept_name ?></title>
    <link rel="stylesheet" href="../assets/css/admin_style.css">
</head>
<body>
    <div class="sidebar">
        <h2>Admin Panel</h2>
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_clearance.php">Clearance Control</a>
        <a href="manage_departments.php" class="active">Departments</a>
        <a href="manage_student.php">Students</a>
        <a href="manage_orgs.php">Organizations</a>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1>Provision Faculty: <?= $dept_name ?></h1>
            <a href="../index.php" class="log-out-btn">LOG OUT</a>
        </div>

        <div class="page-content-wrapper">
            <a href="faculty_list.php?dept_id=<?= $dept_id ?>" class="action-link" style="margin-bottom: 20px; display: inline-block;">← Back to Faculty List</a>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
            <?php endif; ?>

            <div class="container">
                <h3>Add New Faculty Member</h3>
                <p style="font-size: 0.9em; color: var(--color-text-dark);">
                    Phase 1 Setup: Enter the user's details and a temporary password. The user will be forced to set their official email and permanent password upon first login.
                </p>

                <form method="POST" action="add_faculty.php?dept_id=<?= $dept_id ?>">
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">

                        <div class="form-group" style="flex: 1 1 30%;"><label for="fName">First Name:</label><input type="text" name="fName" id="fName" value="<?= htmlspecialchars($fName) ?>" required></div>
                        <div class="form-group" style="flex: 1 1 30%;"><label for="mName">M. Name:</label><input type="text" name="mName" id="mName" value="<?= htmlspecialchars($mName) ?>"></div>
                        <div class="form-group" style="flex: 1 1 30%;"><label for="lName">Last Name:</label><input type="text" name="lName" id="lName" value="<?= htmlspecialchars($lName) ?>" required></div>

                        <div class="form-group" style="flex: 1 1 45%;"><label for="temp_password">Temporary Password:</label><input type="text" name="temp_password" id="temp_password" value="<?= htmlspecialchars($temp_password) ?>" placeholder="e.g. Password123" required></div>

                        <div class="form-group" style="flex: 1 1 45%;">
                            <label for="position">Position:</label>
                            <select name="position" id="position" onchange="toggleCourseAssignedField(this.value)" required>
                                <option value="" disabled <?= empty($position) ? 'selected' : '' ?>>Select Position</option>
                                <option value="Adviser" <?= $position == "Adviser" ? "selected" : "" ?>>Adviser</option>
                                <option value="Department Head" <?= $position == "Department Head" ? "selected" : "" ?>>Department Head</option>
                                <option value="Dean" <?= $position == "Dean" ? "selected" : "" ?>>Dean</option>
                                <option value="Librarian" <?= $position == "Librarian" ? "selected" : "" ?>>Librarian</option>
                                <option value="Registrar" <?= $position == "Registrar" ? "selected" : "" ?>>Registrar</option>
                                <option value="SA Coordinator" <?= $position == "SA Coordinator" ? "selected" : "" ?>>SA Coordinator</option>
                            </select>
                        </div>

                        <div class="form-group" id="course_assigned_group" style="flex-basis: 100%; display: none;">
                            <label for="course_assigned">Course Assigned (Adviser Only):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <select name="course_assigned" id="course_assigned">
                                    <option value="">Select Course</option>
                                    <?php if (empty($courses_list)): ?>
                                        <option value="" disabled>No courses available — click Add Course</option>
                                    <?php else: ?>
                                        <?php foreach ($courses_list as $course): ?>
                                            <option value="<?= htmlspecialchars($course) ?>" <?= $course_assigned == $course ? "selected" : "" ?>>
                                                <?= strtoupper(htmlspecialchars($course)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <button type="button" id="add_course_btn" style="padding:6px 10px; background:#efefef; border:1px solid #cfcfcf; cursor:pointer; border-radius:4px;" onclick="promptAddCourse()">Add Course</button>
                            </div>
                            <small style="color: var(--color-text-dark);">Click "Add Course" to add a course code (stored only in this form unless persisted).</small>
                        </div>

                    </div>

                    <button type="submit" class="btn-primary" style="width: 100%; max-width: 200px; margin-top: 20px;">
                        Add Account
                    </button>
                </form>
            </div>
        </div>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const currentPosition = document.getElementById('position').value;
        toggleCourseAssignedField(currentPosition);
    });

    function toggleCourseAssignedField(position) {
        const group = document.getElementById('course_assigned_group');
        const courseSelect = document.getElementById('course_assigned');
        if (position === 'Adviser') {
            group.style.display = 'block';
            courseSelect.required = true;
        } else {
            group.style.display = 'none';
            courseSelect.required = false;
        }
    }

    function promptAddCourse() {
        const input = prompt('Enter new course code or name (e.g. cs, act, it):');
        if (!input) return;
        const newCourse = input.trim().toLowerCase();
        if (newCourse === '') return;
        const valid = /^[a-z0-9 _-]{1,50}$/.test(newCourse);
        if (!valid) {
            alert('Invalid course. Use letters, numbers, spaces, dash or underscore (max 50 chars).');
            return;
        }
        addCourseToSelect(newCourse);
    }

    function addCourseToSelect(courseVal) {
        const select = document.getElementById('course_assigned');
        Array.from(select.options).forEach(opt => {
            if (opt.disabled && (opt.value === '' || /no courses/i.test(opt.text))) opt.remove();
        });
        const exists = Array.from(select.options).some(opt => opt.value.toLowerCase() === courseVal.toLowerCase());
        if (exists) {
            select.value = courseVal;
            return;
        }
        const opt = document.createElement('option');
        opt.value = courseVal;
        opt.text = courseVal.toUpperCase();
        select.appendChild(opt);
        select.value = courseVal;
    }
</script>
</body>
</html>