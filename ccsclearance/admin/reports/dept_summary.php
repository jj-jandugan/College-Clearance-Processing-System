<?php
session_start();
require_once "../../classes/Database.php";
require_once "../../classes/Admin.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    die("Access denied.");
}

$db = new Database();
$conn = $db->connect();
$adminObj = new Admin();

$filter_year = trim($_GET['school_year'] ?? '');
$filter_term = trim($_GET['term'] ?? '');

$cycles_query = "SELECT DISTINCT school_year, term FROM clearance WHERE school_year IS NOT NULL ORDER BY school_year DESC, FIELD(term, '1st', '2nd', 'Summer') DESC";
$all_cycles = $conn->query($cycles_query)->fetchAll(PDO::FETCH_ASSOC);

$summary_query = "
    SELECT
        d.dept_name,
        s.course,
        s.year_level,
        s.section,
        COUNT(s.student_id) AS total_students,

        SUM(CASE WHEN c.clearance_id IS NOT NULL THEN 1 ELSE 0 END) AS total_cycles_started,
        SUM(CASE WHEN c.status = 'Completed' THEN 1 ELSE 0 END) AS total_completed,
        SUM(CASE WHEN c.status = 'Pending' THEN 1 ELSE 0 END) AS total_pending

    FROM student s
    JOIN department d ON s.department_id = d.department_id

    LEFT JOIN clearance c ON c.student_id = s.student_id
    LEFT JOIN clearance c2 ON c.student_id = c2.student_id AND c.clearance_id < c2.clearance_id
    WHERE c2.clearance_id IS NULL
";

$params = [];
$required_where = " 1=1 ";

if (!empty($filter_year)) {
    $required_where .= " AND c.school_year = :filter_year";
    $params[':filter_year'] = $filter_year;
}

if (!empty($filter_term)) {
    $required_where .= " AND c.term = :filter_term";
    $params[':filter_term'] = $filter_term;
}

$summary_query .= " AND " . $required_where . " GROUP BY s.course, s.year_level, s.section, d.dept_name
    ORDER BY d.dept_name, s.course, s.year_level, s.section";

$stmt = $conn->prepare($summary_query);
$stmt->execute($params);
$grouped_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_students_registered = $adminObj->getSystemSummary()['total_students'] ?? 0;
$report_date = date('M d, Y h:i A');

$display_year = htmlspecialchars($filter_year ?: 'ALL');
$display_term = htmlspecialchars($filter_term ?: 'ALL');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report: Department Summary</title>
    <link rel="stylesheet" href="../../assets/css/reports_style.css">
    <style>
        h1 { color: var(--color-sidebar-bg); border-bottom: 2px solid var(--color-header-bg); padding-bottom: 5px; margin-bottom: 10px; }
        .report-link { color: var(--color-sidebar-bg); text-decoration: none; font-weight: 600; }
        .controls-row { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; }
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
            min-width: 150px;
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
    </style>
</head>
<body>

    <div class="controls-row">
        <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
        <button class="action-button btn-primary" onclick="window.print()">🖨 Print Report</button>
    </div>

    <h1>Department Clearance Summary</h1>

    <div class="filter-controls-area">
        <form method="GET" action="dept_summary.php" class="filter-form">

            <div class="form-group">
                <label for="school_year">School Year:</label>
                <select id="school_year" name="school_year">
                    <option value="">— All Years —</option>
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
                    <option value="">— All Terms —</option>
                    <option value="1st" <?= $filter_term == '1st' ? 'selected' : '' ?>>1st Term</option>
                    <option value="2nd" <?= $filter_term == '2nd' ? 'selected' : '' ?>>2nd Term</option>
                    <option value="Summer" <?= $filter_term == 'Summer' ? 'selected' : '' ?>>Summer</option>
                </select>
            </div>

            <button type="submit" class="btn-primary">Filter</button>
            <?php if (!empty($filter_year) || !empty($filter_term)): ?>
                <a href="dept_summary.php" class="btn-primary" style="background-color: grey; text-decoration: none;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="static-parameters">
        <strong>Filters Applied:</strong><br>
        School Year: <strong><?= $display_year ?></strong> |
        Term: <strong><?= $display_term ?></strong>
    </div>

    <div class="report-meta">
        <p><strong>Generated on:</strong> <?= $report_date ?></p>
        <p><strong>Overall Registered Students:</strong> <?= $total_students_registered ?></p>
    </div>

    <table class="audit-table">
        <thead>
            <tr>
                <th>Department</th>
                <th>Course</th>
                <th>Year/Section</th>
                <th>Total Students</th>
                <th>Cycles Started</th>
                <th>Completed</th>
                <th>Pending</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($grouped_summary)): ?>
                <tr><td colspan="7" style="text-align: center;">No data found matching the current filters.</td></tr>
            <?php else: ?>
                <?php foreach ($grouped_summary as $rec): ?>
                    <tr>
                        <td><?= htmlspecialchars($rec['dept_name']) ?></td>
                        <td><?= htmlspecialchars(strtoupper($rec['course'])) ?></td>
                        <td><?= htmlspecialchars($rec['year_level']) . ' ' . htmlspecialchars($rec['section']) ?></td>

                        <td style="text-align: center;"><?= htmlspecialchars($rec['total_students']) ?></td>

                        <td style="text-align: center;"><?= htmlspecialchars($rec['total_cycles_started']) ?></td>

                        <td style="text-align: center;" class="status-Approved">
                            <?= htmlspecialchars($rec['total_completed']) ?>
                        </td>

                        <td style="text-align: center;" class="status-Pending">
                            <?= htmlspecialchars($rec['total_pending']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>