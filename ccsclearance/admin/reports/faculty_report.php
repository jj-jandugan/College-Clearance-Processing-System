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

$base_query = "
    SELECT
        f.faculty_id,
        CONCAT_WS(' ', f.fName, f.lName) AS faculty_name,
        f.position,
        d.dept_name,

        -- Aggregate Clearance Statuses for the filtered cycle
        COUNT(cs.signature_id) AS total_processed,
        SUM(CASE WHEN cs.signed_status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN cs.signed_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count

    FROM faculty f
    JOIN department d ON f.department_id = d.department_id

    -- LEFT JOIN to include faculty who haven't processed clearances yet (Total Handled = 0)
    LEFT JOIN clearance_signature cs ON f.signer_entity_id = cs.signer_entity_id
    LEFT JOIN clearance c ON cs.clearance_id = c.clearance_id

    WHERE 1=1
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

$final_query = $base_query . " AND " . $required_where . "
    GROUP BY f.faculty_id, f.fName, f.lName, f.position, d.dept_name
    ORDER BY f.lName ASC";

$stmt = $conn->prepare($final_query);
$stmt->execute($params);
$faculty_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
$report_date = date('M d, Y h:i A');

$display_year = htmlspecialchars($filter_year ?: 'ALL');
$display_term = htmlspecialchars($filter_term ?: 'ALL');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report: Faculty / Signer Performance</title>
    <link rel="stylesheet" href="../../assets/css/reports_style.css">
    <style>
        h1 { color: var(--color-sidebar-bg); border-bottom: 2px solid var(--color-header-bg); padding-bottom: 5px; margin-bottom: 10px; }
        .back-link { color: var(--color-sidebar-bg); text-decoration: none; font-weight: 600; }
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
        .audit-table th:nth-child(5), .audit-table th:nth-child(6), .audit-table th:nth-child(7),
        .audit-table td:nth-child(5), .audit-table td:nth-child(6), .audit-table td:nth-child(7) {
            text-align: center;
        }

        /* PRINT STYLES */
        @media print {
            /* Show print header */
            .print-header {
                display: block !important;
            }

            /* Hide screen-only elements */
            .controls-row,
            .filter-controls-area,
            .action-button {
                display: none !important;
            }

            /* Page setup */
            @page {
                size: A4 landscape;
                margin: 1.5cm 1cm;
            }

            body {
                margin: 0;
                padding: 20px;
                background: white;
                font-size: 11pt;
            }

            /* Header styling */
            h1 {
                font-size: 18pt;
                margin-bottom: 15px;
                page-break-after: avoid;
            }

            /* Report metadata */
            .report-meta,
            .static-parameters {
                font-size: 10pt;
                margin-bottom: 15px;
                page-break-inside: avoid;
            }

            /* Table styling */
            .audit-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10pt;
                page-break-inside: auto;
            }

            .audit-table thead {
                display: table-header-group; /* Repeat header on each page */
            }

            .audit-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            .audit-table th {
                background-color: #2c5f7d !important;
                color: white !important;
                padding: 8px 5px;
                border: 1px solid #000;
                font-weight: bold;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .audit-table td {
                padding: 6px 5px;
                border: 1px solid #666;
            }

            /* Status colors for print */
            .status-Approved {
                background-color: #d4edda !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .status-Pending {
                background-color: #fff3cd !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* Add page header */
            .report-meta::before {
                content: "CCS Clearance System - Faculty Report";
                display: block;
                font-size: 12pt;
                font-weight: bold;
                text-align: center;
                margin-bottom: 10px;
                padding-bottom: 5px;
                border-bottom: 2px solid #000;
            }
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
        <button class="action-button btn-primary" onclick="window.print()">🖨 Print Report</button>
    </div>

    <h1>Faculty / Signer Report</h1>

    <div class="filter-controls-area">
        <form method="GET" action="faculty_report.php" class="filter-form">

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
                <a href="faculty_report.php" class="btn-primary" style="background-color: grey; text-decoration: none;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="static-parameters">
        <strong>Filtered Cycle:</strong><br>
        School Year: <strong><?= $display_year ?></strong> |
        Term: <strong><?= $display_term ?></strong>
    </div>

    <div class="report-meta">
        <p><strong>Generated on:</strong> <?= $report_date ?></p>
        <p><strong>Total Faculty Listed:</strong> <?= count($faculty_records) ?></p>
    </div>

    <table class="audit-table">
        <thead>
            <tr>
                <th>Faculty ID</th>
                <th>Faculty Name</th>
                <th>Department</th>
                <th>Position</th>
                <th>Total Clearances Handled</th>
                <th>Approved</th>
                <th>Pending</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($faculty_records)): ?>
                <tr><td colspan="7" style="text-align: center;">No faculty members found matching the filters.</td></tr>
            <?php else: ?>
                <?php foreach ($faculty_records as $rec): ?>
                    <tr>
                        <td><?= htmlspecialchars($rec['faculty_id']) ?></td>
                        <td><?= htmlspecialchars($rec['faculty_name']) ?></td>
                        <td><?= htmlspecialchars($rec['dept_name']) ?></td>
                        <td><?= htmlspecialchars($rec['position']) ?></td>

                        <td><?= htmlspecialchars($rec['total_processed']) ?></td>

                        <td class="status-Approved">
                            <?= htmlspecialchars($rec['approved_count']) ?>
                        </td>

                        <td class="status-Pending">
                            <?= htmlspecialchars($rec['pending_count']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>