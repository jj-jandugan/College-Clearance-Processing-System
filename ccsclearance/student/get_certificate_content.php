<?php
session_start();
require_once "../classes/Clearance.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    die("Access denied.");
}

$clearance_id = filter_input(INPUT_GET, 'cid', FILTER_VALIDATE_INT);
if (!$clearance_id) {
    die('<div style="padding: 20px; text-align: center; color: red;">Error: Clearance ID required.</div>');
}

$clearanceObj = new Clearance();

$data = $clearanceObj->getCertificateData($clearance_id);

if (!$data || $data['clearance_status'] !== 'CLEARED') {
    die('<div style="padding: 20px; text-align: center; color: red;">
            Certificate not yet CLEARED or not found. Status: ' . htmlspecialchars($data['clearance_status'] ?? 'NOT FOUND') .
        '</div>');
}

$student_name = htmlspecialchars($data['student_name'] ?? 'N/A');
$school_id = htmlspecialchars($data['school_id'] ?? 'N/A');
$course_section = htmlspecialchars($data['course_section'] ?? 'N/A');
$date_issued = htmlspecialchars(date('F j, Y', strtotime($data['date_issued'] ?? 'now')));
$dean_name = htmlspecialchars($data['dean_name'] ?? 'N/A');
$dean_title = htmlspecialchars($data['dean_title'] ?? 'N/A');
$school_year = htmlspecialchars($data['school_year'] ?? 'N/A');
$term = htmlspecialchars($data['term'] ?? 'N/A');
$status = htmlspecialchars($data['clearance_status']);
?>

<div id="printable-certificate-content">
    <div style="background-color: #fff; padding: 30px; margin: 0 auto; max-width: 700px;">

        <div style="text-align: center; border-bottom: 3px double var(--color-sidebar-bg); padding-bottom: 15px; margin-bottom: 20px;">
            <h1 style="color: var(--color-sidebar-bg); font-size: 1.6em; margin: 0;">CERTIFICATE OF CLEARANCE</h1>
        </div>

        <div style="border: 3px solid var(--color-card-approved); color: var(--color-card-approved); font-size: 1.1em; font-weight: 700; text-align: center; padding: 8px; margin-bottom: 25px;">
            <?= $status ?>
        </div>

        <div style="font-size: 1em; line-height: 1.6; text-align: justify;">
            <p>
                This certifies that <strong><?= $student_name ?></strong> (Student ID: <strong><?= $school_id ?></strong>), a student of <strong><?= $course_section ?></strong>,
                has successfully complied with all the requirements for the Academic Cycle:
                <strong><?= $school_year ?> - <?= $term ?></strong>.
            </p>
            <p>
                This student is hereby declared **CLEAR** of any obligations from all departments, offices, and organizations as of the date issued.
            </p>
        </div>

        <div style="width: 100%; margin-top: 50px; text-align: right;">
            <div style="width: 250px; border-top: 1px solid #333; margin-left: auto; padding-top: 5px;">
                <p style="margin: 0; font-weight: 600;"><?= $dean_name ?></p>
                <small style="display: block; font-weight: 400; color: #555;"><?= $dean_title ?></small>
            </div>
        </div>
    </div>
</div>