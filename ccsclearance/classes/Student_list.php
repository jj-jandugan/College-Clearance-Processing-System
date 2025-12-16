<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty') {
    header("Location: ../index.php");
    exit;
}

require_once "../classes/Faculty.php";
$faculty_id = $_SESSION['ref_id'];
$facultyObj = new Faculty();
$details = $facultyObj->getFacultyDetails($faculty_id);
$position = $details['position'];

if ($position != 'Adviser') {
    echo "Access denied.";
    exit;
}

$search_term = $_GET['search'] ?? '';-

$assigned_students = $facultyObj->getAssignedStudents($faculty_id, $search_term);

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adviser Student List</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .search-container { margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>Adviser Student List Management</h1>
    <nav style="margin-bottom:10px;">
        <a href="dashboard.php">Dashboard</a> |
        <a href="pending.php">Pending Request</a> |
        <a href="history.php">History</a> |
        <a href="../logout.php">Logout</a>
    </nav>
    <hr>

    <?php if ($message): ?>
        <p style="color: green;"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <h2>My Advisees (<?= htmlspecialchars(strtoupper($details['course_assigned'] ?? '')) ?>)</h2>

    <div class="search-container">
        <form method="GET" action="student_list.php" style="display: inline-block;">
            <input type="text" name="search" placeholder="Search Student Name or ID..." value="<?= htmlspecialchars($search_term) ?>" style="padding: 5px; width: 300px;">
            <button type="submit" style="padding: 5px 10px;">Search</button>
            <?php if ($search_term): ?>
                <a href="student_list.php" style="margin-left: 10px;">Clear Search</a>
            <?php endif; ?>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>School ID</th>
                <th>Name</th>
                <th>Course</th>
                <th>Level/Section</th>
                <th>Action (Management)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($assigned_students)): ?>
                <?php foreach ($assigned_students as $student): ?>
                    <tr>
                        <td><?= htmlspecialchars($student['school_id'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($student['name']) ?></td>
                        <td><?= htmlspecialchars(strtoupper($student['course'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($student['year_level'] ?? 'N/A') ?> / <?= htmlspecialchars($student['section_id'] ?? 'N/A') ?></td>
                        <td>
                            <button onclick="alert('Simulate: Edit/View detailed info for <?= htmlspecialchars($student['name']) ?>')">View Details</button>
                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Remove student <?= $student['school_id'] ?>?');">
                                <input type="hidden" name="student_id" value="<?= htmlspecialchars($student['student_id']) ?>">
                                <button type="submit" name="action" value="remove_advisee" style="color: red;">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                 <tr>
                    <td colspan="5" style="text-align:center;">No students found assigned to your course/section.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>