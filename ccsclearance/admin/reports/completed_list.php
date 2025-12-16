<?php
session_start();
require_once "../../classes/Database.php";
require_once "../../classes/Admin.php";
require_once "../../classes/Clearance.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    die("Access denied.");
}

$db = new Database();
$conn = $db->connect();
$adminObj = new Admin();
$clearanceObj = new Clearance();

$filter_year = trim($_GET['school_year'] ?? '');
$filter_term = trim($_GET['term'] ?? '');
$filter_dept = trim($_GET['department'] ?? '');
$filter_course = trim($_GET['course'] ?? '');
$filter_year_level = trim($_GET['year_level'] ?? '');
$filter_section = trim($_GET['section'] ?? '');

$courses = $conn->query("SELECT DISTINCT course FROM student ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);
$departments_db = $conn->query("SELECT department_id, dept_name FROM department ORDER BY dept_name")->fetchAll(PDO::FETCH_ASSOC);
$years = $conn->query("SELECT DISTINCT year_level FROM student ORDER BY year_level")->fetchAll(PDO::FETCH_COLUMN);
$sections = $conn->query("SELECT DISTINCT section FROM student ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

$cycles_query = "SELECT DISTINCT school_year, term FROM clearance WHERE school_year IS NOT NULL ORDER BY school_year DESC, FIELD(term, '1st', '2nd', 'Summer') DESC";
$all_cycles = $conn->query($cycles_query)->fetchAll(PDO::FETCH_ASSOC);

$base_query = "
    SELECT
        c.clearance_id,
        c.date_requested,
        c.date_completed,
        s.school_id,
        CONCAT_WS(' ', s.fName, s.lName) AS student_name,
        s.course, s.year_level, s.section,
        d.dept_name
    FROM clearance c
    JOIN student s ON c.student_id = s.student_id
    JOIN department d ON s.department_id = d.department_id

    LEFT JOIN clearance c2 ON c.student_id = c2.student_id AND c.clearance_id < c2.clearance_id
    WHERE c2.clearance_id IS NULL
    AND c.status IN ('Approved', 'Completed')
";

$params = [];
$required_where = "";

if (!empty($filter_year)) {
    $required_where .= " AND c.school_year = :filter_year";
    $params[':filter_year'] = $filter_year;
}
if (!empty($filter_term)) {
    $required_where .= " AND c.term = :filter_term";
    $params[':filter_term'] = $filter_term;
}
if (!empty($filter_dept)) {
    $required_where .= " AND d.department_id = :filter_dept";
    $params[':filter_dept'] = $filter_dept;
}
if (!empty($filter_course)) {
    $required_where .= " AND s.course = :filter_course";
    $params[':filter_course'] = $filter_course;
}
if (!empty($filter_year_level)) {
    $required_where .= " AND s.year_level = :filter_year_level";
    $params[':filter_year_level'] = $filter_year_level;
}
if (!empty($filter_section)) {
    $required_where .= " AND s.section = :filter_section";
    $params[':filter_section'] = $filter_section;
}

$final_query = $base_query . $required_where . " ORDER BY s.lName ASC";

$stmt = $conn->prepare($final_query);
$stmt->execute($params);
$cleared_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$report_date = date('M d, Y h:i A');
function formatDateReport($dateTimeString) {
    return empty($dateTimeString) ? 'N/A' : date('M d, Y', strtotime($dateTimeString));
}

$display_year = htmlspecialchars($filter_year ?: 'ALL');
$display_term = htmlspecialchars($filter_term ?: 'ALL');
$display_dept_name = 'ALL';
if ($filter_dept) {
    foreach ($departments_db as $d) {
        if ($d['department_id'] == $filter_dept) { $display_dept_name = $d['dept_name']; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report: Certificate Print Queue</title>
    <link rel="stylesheet" href="../../assets/css/reports_style.css">
    <style>
        h1 {
            color: var(--color-sidebar-bg);
            border-bottom: 2px solid var(--color-header-bg);
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .back-link {
            color: var(--color-sidebar-bg);
            text-decoration: none;
            font-weight: 600;
        }
        .controls-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 20px;
        }
        .filter-controls-area {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            flex-direction: row;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-form .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 0;
            min-width: 100px;
        }

        .filter-form select {
            height: 38px;
            padding: 5px;
        }

        .filter-form button, .filter-form a.btn-primary {
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0;
        }
        .audit-table th { white-space: nowrap; }
        .action-cell { width: 100px; text-align: center !important; }
        .modal {
            display: none;
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal-content-cert {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 900px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        /* PRINT STYLES */
        @media print {
            .print-header { display: block !important; }
            /* Hide screen-only elements */
            .filter-controls-area, .controls-row, .modal, .action-button { display: none !important; }

            /* Page setup */
            @page { size: A4 landscape; margin: 1.5cm 1cm; }

            body { margin: 0; padding: 20px; background: white; font-size: 11pt; }
            h1 { font-size: 18pt; margin-bottom: 15px; page-break-after: avoid; }
            .report-meta, .static-parameters { font-size: 10pt; margin-bottom: 15px; page-break-inside: avoid; }

            /* Table styling */
            .audit-table { width: 100%; border-collapse: collapse; font-size: 10pt; page-break-inside: auto; }
            .audit-table thead { display: table-header-group; }
            .audit-table tr { page-break-inside: avoid; page-break-after: auto; }
            .audit-table th { background-color: #2c5f7d !important; color: white !important; padding: 8px 5px; border: 1px solid #000; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .audit-table td { padding: 6px 5px; border: 1px solid #666; }
        }
    </style>
</head>
<body>

    <!-- PRINT-ONLY HEADER -->
    <div class="print-header" style="display: none;">
        <div style="text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 3px solid #2c5f7d;">
            <img src="../../assets/img/ccs_logo.png" alt="CCS Logo" style="height: 80px; vertical-align: middle; margin-right: 15px;">
            <div style="display: inline-block; vertical-align: middle; text-align: left;">
                <h2 style="margin: 0; font-size: 20pt; color: #2c5f7d;">College of Computing Studies</h2>
                <p style="margin: 5px 0 0 0; font-size: 12pt; color: #555;">Clearance System</p>
            </div>
        </div>
    </div>

    <div class="controls-row">
        <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
        <button class="action-button btn-primary" onclick="window.print()">🖨 Print Report List</button>
    </div>

    <h1>Completed Student</h1>

    <div class="filter-controls-area">
        <form method="GET" action="completed_list.php" class="filter-form">

            <div class="form-group">
                <label for="school_year">S.Y.:</label>
                <select id="school_year" name="school_year">
                    <option value="">— All —</option>
                    <?php
                    $unique_years = array_unique(array_column($all_cycles, 'school_year'));
                    foreach ($unique_years as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>" <?= $filter_year == $year ? 'selected' : '' ?>>
                            <?= htmlspecialchars($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="term">Term:</label>
                <select id="term" name="term">
                    <option value="">— All —</option>
                    <option value="1st" <?= $filter_term == '1st' ? 'selected' : '' ?>>1st</option>
                    <option value="2nd" <?= $filter_term == '2nd' ? 'selected' : '' ?>>2nd</option>
                    <option value="Summer" <?= $filter_term == 'Summer' ? 'selected' : '' ?>>Sum</option>
                </select>
            </div>

            <div class="form-group">
                <label for="department">Dept:</label>
                <select id="department" name="department">
                    <option value="">— All —</option>
                    <?php foreach ($departments_db as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['department_id']) ?>" <?= $filter_dept == $dept['department_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['dept_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="course">Course:</label>
                <select id="course" name="course">
                    <option value="">— All —</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= htmlspecialchars($course) ?>" <?= $filter_course == $course ? 'selected' : '' ?>>
                            <?= strtoupper(htmlspecialchars($course)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="year_level">Year:</label>
                <select id="year_level" name="year_level">
                    <option value="">— All —</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>" <?= $filter_year_level == $year ? 'selected' : '' ?>>
                            <?= htmlspecialchars($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="section">Sec:</label>
                <select id="section" name="section">
                    <option value="">— All —</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= htmlspecialchars($section) ?>" <?= $filter_section == $section ? 'selected' : '' ?>>
                            <?= htmlspecialchars($section) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn-primary">Filter</button>

            <?php if (!empty($filter_year) || !empty($filter_term) || !empty($filter_dept) || !empty($filter_course) || !empty($filter_year_level) || !empty($filter_section)): ?>
                <a href="completed_list.php" class="btn-primary" style="background-color: grey; text-decoration: none;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="static-parameters">
        <strong>Filters Applied:</strong><br>
        S.Y.: <?= $display_year ?> | Term: <?= $display_term ?> |
        Dept: <?= htmlspecialchars($display_dept_name) ?> |
        Course: <?= htmlspecialchars($filter_course ?: 'ALL') ?> |
        Year: <?= htmlspecialchars($filter_year_level ?: 'ALL') ?> |
        Section: <?= htmlspecialchars($filter_section ?: 'ALL') ?>
    </div>

    <div class="report-meta">
        <p><strong>Generated on:</strong> <?= $report_date ?></p>
        <p><strong>Total Completed:</strong> <?= count($cleared_records) ?></p>
    </div>

    <table class="audit-table">
        <thead>
            <tr>
                <th>Clearance ID</th>
                <th>Student Name</th>
                <th>ID</th>
                <th>Dept</th>
                <th>Course/Sec</th>
                <th>Requested</th>
                <th>Completed</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($cleared_records)): ?>
                <tr><td colspan="8" style="text-align: center;">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($cleared_records as $rec): ?>
                    <tr>
                        <td><?= htmlspecialchars($rec['clearance_id']) ?></td>
                        <td><?= htmlspecialchars($rec['student_name']) ?></td>
                        <td><?= htmlspecialchars($rec['school_id']) ?></td>
                        <td><?= htmlspecialchars($rec['dept_name']) ?></td>
                        <td><?= htmlspecialchars(strtoupper($rec['course']) . ' ' . $rec['year_level'] . '-' . $rec['section']) ?></td>
                        <td><?= formatDateReport($rec['date_requested']) ?></td>
                        <td><?= formatDateReport($rec['date_completed']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>