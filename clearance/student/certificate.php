<?php
session_start();
require_once "../classes/Database.php";
require_once "../classes/Clearance.php";
require_once "../classes/Account.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

$clearance_id = $_GET['clearance_id'] ?? die("Clearance ID required.");
$student_id = $_SESSION['ref_id'];
$clearanceObj = new Clearance();
$accountObj = new Account();
$db = new Database();
$conn = $db->connect();

// --- 1. Initial Profile Data Fetch ---
$profile_data = $clearanceObj->getStudentProfileData($student_id);
$account_data = $accountObj->getAccountDetailsByRefId($student_id, 'student');
$message = "";
$messageType = "";

// --- 2. Profile Update Handler ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_profile'])) {
    $fName = trim($_POST['fName']);
    $mName = trim($_POST['mName']);
    $lName = trim($_POST['lName']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $current_pic = trim($_POST['current_profile_picture']);

    if (!empty($password) && $password !== $confirm_password) {
        $message = "❌ Password mismatch.";
        $messageType = "error";
        goto skip_profile_update;
    }

    $new_pic_filename = $current_pic;

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
        $target_dir = "../assets/img/profiles/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
        $new_pic_filename = "student_" . $student_id . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_pic_filename;

        if (!move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
            $message = "❌ Upload error."; $messageType = "error"; goto skip_profile_update;
        }
        if (!empty($current_pic) && $current_pic !== 'profile.png' && file_exists($target_dir . $current_pic)) {
            @unlink($target_dir . $current_pic);
        }
    }

    try {
        $conn->beginTransaction();
        $student_update_data = [ 'fName' => $fName, 'mName' => $mName, 'lName' => $lName, 'profile_picture' => $new_pic_filename ];
        $clearanceObj->updateStudentProfile($student_id, $student_update_data, $conn);
        $accountObj->updateAccountDetails($account_data['account_id'], $email, empty($password) ? null : $password, $conn);
        $conn->commit();
        $message = "✅ Profile updated!"; $messageType = "success";
        header("Location: certificate.php?clearance_id=$clearance_id&msg=" . urlencode($message) . "&type=$messageType");
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "❌ Error: " . $e->getMessage(); $messageType = "error";
    }
    skip_profile_update:
}

// --- 3. Re-fetch Data & Construct Name/Image ---
$profile_data = $clearanceObj->getStudentProfileData($student_id);
$account_data = $accountObj->getAccountDetailsByRefId($student_id, 'student');

$fName = empty($profile_data['fName']) ? 'Student' : $profile_data['fName'];
$mName = $profile_data['mName'] ?? '';
$lName = empty($profile_data['lName']) ? 'Name' : $profile_data['lName'];
$middle_initial = !empty($mName) ? substr($mName, 0, 1) . '. ' : '';
$student_name_display = trim("{$fName} {$middle_initial}{$lName}");

$profile_pic_name = !empty($profile_data['profile_picture']) ? $profile_data['profile_picture'] : 'profile.png';
if (strpos($profile_pic_name, 'student_') === 0) {
    $profile_pic_path = '../assets/img/profiles/' . $profile_pic_name;
} else {
    $profile_pic_path = '../assets/img/profile.png';
}

$certificate_data = $clearanceObj->getCertificateData($clearance_id);

if (!$certificate_data || $certificate_data['clearance_status'] !== 'CLEARED') {
    $status = $certificate_data['clearance_status'] ?? 'NOT_FOUND';
    header("Location: status.php?error=" . urlencode("Certificate cannot be viewed. Status is: {$status}."));
    exit;
}

$cert_student_name = htmlspecialchars($certificate_data['student_name']);
$school_id = htmlspecialchars($certificate_data['school_id']);
$course_section = htmlspecialchars($certificate_data['course_section']);
$date_issued = htmlspecialchars(date('F j, Y', strtotime($certificate_data['date_issued'])));
$dean_name = htmlspecialchars($certificate_data['dean_name']);
$dean_title = htmlspecialchars($certificate_data['dean_title']);
$status = htmlspecialchars($certificate_data['clearance_status']);

$static_school_year = "2025-2026 (1st Sem)";

if (isset($_GET['msg'])) { $message = htmlspecialchars($_GET['msg']); $messageType = htmlspecialchars($_GET['type']); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clearance Certificate #<?= $clearance_id ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/certificate_style.css">
    <style>
        /* Modal Profile Styles */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); display: flex; justify-content: center; align-items: center; }
        .modal-content-profile { background-color: #fefefe; padding: 25px; border: 1px solid #888; width: 80%; max-width: 550px; border-radius: 8px; position: relative; box-shadow: 0 5px 15px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto; text-align: left; }
        .close { color: #aaa; position: absolute; right: 15px; top: 10px; font-size: 1.5em; cursor: pointer; }
        .form-group-profile { margin-bottom: 15px; }
        .form-group-profile input, .form-group-profile select { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .profile-name { cursor: pointer; }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-profile">
        <div class="profile-icon"><img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile"></div>
        <div class="profile-name" onclick="openProfileModal('student')" style="font-weight:700; margin-bottom:5px;"><?= htmlspecialchars($student_name_display) ?></div>
        <small>Student</small>
    </div>
    <a href="history.php" class="active">Back to History</a>
</div>

<div class="main-content">
    <div class="page-header">
        <div class="logo-text">Clearance Certificate</div>
        <a href="../index.php" class="log-out-btn">LOG OUT</a>
    </div>

    <div class="page-content-wrapper">
        <?php if ($message): ?>
            <div style="background-color: <?= $messageType == 'error' ? '#f8d7da' : '#d4edda' ?>; color: <?= $messageType == 'error' ? '#721c24' : '#155724' ?>; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                <?= $message ?>
            </div>
        <?php endif; ?>

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
                <span class="dynamic-data"><?= $cert_student_name ?></span>
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

<div id="profileModalStudent" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Student Profile</h3>
        <form method="POST" action="certificate.php?clearance_id=<?= $clearance_id ?>" enctype="multipart/form-data">
            <input type="hidden" name="update_profile" value="1">
            <input type="hidden" name="current_profile_picture" value="<?= htmlspecialchars($profile_data['profile_picture'] ?? 'profile.png') ?>">
            <div class="form-group-profile" style="text-align: center;">
                <label>Profile Picture:</label><br>
                <img id="profile_pic_preview" src="<?= htmlspecialchars($profile_pic_path) ?>" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; border: 2px solid var(--color-sidebar-bg);">
                <input type="file" name="profile_picture" id="profile_picture" accept="image/*" onchange="previewImage(event)">
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div class="form-group-profile" style="flex: 1 1 45%;"><label for="fName">First Name:</label><input type="text" name="fName" id="fName" value="<?= htmlspecialchars($profile_data['fName'] ?? '') ?>" ></div>
                <div class="form-group-profile" style="flex: 1 1 45%;"><label for="lName">Last Name:</label><input type="text" name="lName" id="lName" value="<?= htmlspecialchars($profile_data['lName'] ?? '') ?>" ></div>
                <div class="form-group-profile" style="flex: 1 1 95%;"><label for="mName">M. Name (Opt):</label><input type="text" name="mName" id="mName" value="<?= htmlspecialchars($profile_data['mName'] ?? '') ?>"></div>
            </div>
            <div class="form-group-profile"><label for="email">Email Address:</label><input type="email" name="email" id="email" value="<?= htmlspecialchars($account_data['email'] ?? '') ?>" required></div>
            <hr>
            <p style="font-size: 0.9em; color: #555;">Leave password fields blank if you do not want to change your password.</p>
            <div class="form-group-profile"><label for="password">New Password:</label><input type="password" name="password" id="password"></div>
            <div class="form-group-profile"><label for="confirm_password">Confirm New Password:</label><input type="password" name="confirm_password" id="confirm_password"></div>
            <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closeProfileModal()" class="cancel-modal-btn">Cancel</button>
                <button type="submit" class="submit-modal-btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function closeProfileModal() { document.getElementById('profileModalStudent').style.display = 'none'; }
    function openProfileModal(role) {
        if (role === 'student') {
            const currentFName = '<?= htmlspecialchars(addslashes($profile_data['fName'] ?? '')) ?>';
            const currentMName = '<?= htmlspecialchars(addslashes($profile_data['mName'] ?? '')) ?>';
            const currentLName = '<?= htmlspecialchars(addslashes($profile_data['lName'] ?? '')) ?>';
            const currentEmail = '<?= htmlspecialchars(addslashes($account_data['email'] ?? '')) ?>';
            const currentPicPath = '<?= htmlspecialchars(addslashes($profile_pic_path)) ?>';
            document.getElementById('fName').value = currentFName;
            document.getElementById('mName').value = currentMName;
            document.getElementById('lName').value = currentLName;
            document.getElementById('email').value = currentEmail;
            document.getElementById('profile_pic_preview').src = currentPicPath;
            document.getElementById('profileModalStudent').style.display = 'flex';
        }
    }
    function previewImage(event) {
        const reader = new FileReader();
        reader.onload = function() { const output = document.getElementById('profile_pic_preview'); output.src = reader.result; };
        if (event.target.files[0]) { reader.readAsDataURL(event.target.files[0]); }
    }
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
</script>
</body>
</html>