<?php
session_start();
require_once "../../classes/Clearance.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

$clearance_id = $_GET['clearance_id'] ?? die("Clearance ID required.");
$clearanceObj = new Clearance();

$certificate_data = $clearanceObj->getCertificateData($clearance_id);

if (!$certificate_data || $certificate_data['clearance_status'] !== 'CLEARED') {
    $status = $certificate_data['clearance_status'] ?? 'NOT_FOUND';
    header("Location: status.php?error=" . urlencode("Certificate cannot be viewed. Status is: {$status}."));
    exit;
}


$student_name = htmlspecialchars($certificate_data['student_name']);
$school_id = htmlspecialchars($certificate_data['school_id']);
$course_section = htmlspecialchars($certificate_data['course_section']);
$date_issued = htmlspecialchars(date('F j, Y', strtotime($certificate_data['date_issued'])));
$dean_name = htmlspecialchars($certificate_data['dean_name']);
$dean_title = htmlspecialchars($certificate_data['dean_title']);
$status = htmlspecialchars($certificate_data['clearance_status']);

$static_school_year = "2025-2026 (1st Sem)";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clearance Certificate #<?= $clearance_id ?></title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/certificate_style.css">
</head>
<body>
<div class="sidebar">
    <div class="sidebar-profile">
        <div class="profile-icon"><img src="../assets/img/profile.png" alt="Profile"></div>
        <?= htmlspecialchars($student_name) ?>
    </div>
    <a href="status.php" class="active">Back to Status</a>
</div>

<div class="main-content">
    <div class="page-header">
        <div class="logo-text">Clearance Certificate</div>
        <a href="../index.php" class="log-out-btn">LOG OUT</a>
    </div>

    <div class="page-content-wrapper">

        <div class="print-btn-container" style="text-align: right; margin-bottom: 20px;">
            <button onclick="window.print()" class="submit-modal-btn" style="background-color: #6495ED; padding: 10px 20px;">
                🖨️ Print / Download PDF
            </button>
        </div>

        <div class="certificate-container">

            <div class="cert-seal"></div>

            <h1 class="cert-main-title">CERTIFICATE OF CLEARANCE</h1>

            <div class="cert-content-block">
                This is to certify that:

                <span class="dynamic-data"><?= $student_name ?></span>
                (Student ID: <span class="dynamic-data" style="display: inline;"><?= $school_id ?></span>)

                <p style="margin-top: 20px;">
                a student registered for the School Year <?= htmlspecialchars($static_school_year) ?>
                has successfully complied with all the academic, administrative, and financial requirements of the
                College of Computing Studies for Clearance ID:
                <span class="dynamic-data" style="display: inline;"><?= $clearance_id ?></span>.
                </p>

                <p>
                    This student is hereby declared CLEAR of any obligations from all departments, offices, and organization as of the date issuance below.
                </p>

                <p style="margin-top: 40px; font-size: 1em;">
                    Issued this <?= $date_issued ?>.
                </p>
            </div>

            <div class="signature-area">
                <div class="signature-line"></div>
                <p><?= $dean_name ?></p>
                <small><?= $dean_title ?></small>
            </div>

        </div>
        </div>
</div>
</body>
</html>