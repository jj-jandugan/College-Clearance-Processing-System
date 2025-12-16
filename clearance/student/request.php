<?php
session_start();
require_once "../classes/Database.php";
require_once "../classes/Clearance.php";
require_once "../classes/Account.php";

// ⚠️ SECURITY: Check role and session
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'student') {
    header("Location: ../index.php");
    exit();
}

$student_id = $_SESSION['ref_id'];
$clearanceObj = new Clearance();
$accountObj = new Account(); // Need Account object for profile updates

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
        $message = "❌ Password and Confirm Password do not match.";
        $messageType = "error";
        goto skip_profile_update;
    }

    $new_pic_filename = $current_pic;

    // Handle Image Upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
        $target_dir = "../assets/img/profiles/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
        $new_pic_filename = "student_" . $student_id . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_pic_filename;

        if (!move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
            $message = "❌ Error uploading new profile picture.";
            $messageType = "error";
            goto skip_profile_update;
        }
        if (!empty($current_pic) && $current_pic !== 'profile.png' && file_exists($target_dir . $current_pic)) {
            @unlink($target_dir . $current_pic);
        }
    }

    try {
        $conn->beginTransaction();

        $student_update_data = [
            'fName' => $fName,
            'mName' => $mName,
            'lName' => $lName,
            'profile_picture' => $new_pic_filename
        ];
        $clearanceObj->updateStudentProfile($student_id, $student_update_data, $conn);
        $accountObj->updateAccountDetails($account_data['account_id'], $email, empty($password) ? null : $password, $conn);

        $conn->commit();
        $message = "✅ Profile updated successfully!";
        $messageType = "success";

        header("Location: request.php?msg=" . urlencode($message) . "&type=$messageType");
        exit;

    } catch (Exception $e) {
        $conn->rollBack();
        $message = "❌ Database error during update: " . $e->getMessage();
        $messageType = "error";
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
$student_name = trim("{$fName} {$middle_initial}{$lName}");

$profile_pic_name = !empty($profile_data['profile_picture']) ? $profile_data['profile_picture'] : 'profile.png';
if (strpos($profile_pic_name, 'student_') === 0) {
    $profile_pic_path = '../assets/img/profiles/' . $profile_pic_name;
} else {
    $profile_pic_path = '../assets/img/profile.png';
}

// --- NEW HANDLE DELETE ALL ACTION (Sets is_read = 2) ---
if (isset($_POST['delete_all'])) {
    $clearanceObj->deleteAllNotifications($student_id);
    header("Location: request.php");
    exit;
}

// --- NEW HANDLE DELETE ONE ACTION (Sets is_read = 2) ---
if (isset($_POST['delete_one']) && isset($_POST['mark_read_id']) && isset($_POST['mark_read_type'])) {
    $clearanceObj->clearNotification($_POST['mark_read_id'], $_POST['mark_read_type']);
    header("Location: request.php");
    exit;
}

// --- NEW HANDLE MARK ALL ACTION (Sets is_read = 1) ---
if (isset($_POST['mark_all_read'])) {
    $clearanceObj->markAllNotificationsRead($student_id);
    header("Location: request.php");
    exit;
}

// --- HANDLE MARK AS READ ACTION (Sets is_read = 1) ---
if (isset($_POST['mark_read_id']) && isset($_POST['mark_read_type']) && !isset($_POST['delete_one'])) {
    // Assuming markNotificationRead is added to Clearance.php from previous steps
    $clearanceObj->markNotificationRead($_POST['mark_read_id'], $_POST['mark_read_type']);

    // Check if a specific redirect link was passed (Fix for direct link click)
    $redirect_link = $_POST['redirect_link'] ?? 'request.php';

    // Redirect to the correct page or history.php
    header("Location: " . $redirect_link);
    exit;
}

// --- 1. Fetch Student Details ---
$student_details_query = "SELECT adviser_id, department_id, CONCAT_WS(' ', fName, lName) as full_name FROM student WHERE student_id = :sid";
$student_stmt = $conn->prepare($student_details_query);
$student_stmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
$student_stmt->execute();
$student_details = $student_stmt->fetch(PDO::FETCH_ASSOC);

$adviser_id = $student_details['adviser_id'] ?? null;
$student_dept_id = $student_details['department_id'] ?? null;
$student_name = $student_details['full_name'] ?? 'Student Name Not Found';


// --- NOTIFICATION LOGIC: Filter to only show UNREAD items in the dropdown list ---
$recent_requests = $clearanceObj->getRecentClearanceRequests($student_id, 10);
$notifications = [];
$notification_count = 0; // Tracks only UNREAD items
$is_completed_notified = false;

foreach ($recent_requests as $r) {
    $signer_status = $r['signer_status'] ?? '';
    $master_status = $r['status'] ?? '';
    $c_id = $r['clearance_id'];
    $sig_id = $r['signature_id'] ?? 0;

    $is_read_master = (bool)($r['master_is_read'] ?? 0);
    $is_read_signature = (bool)($r['signature_is_read'] ?? 0);
    $is_deleted_master = ($r['master_is_read'] ?? 0) == 2;
    $is_deleted_signature = ($r['signature_is_read'] ?? 0) == 2;

    // 1. CHECK FOR MASTER COMPLETION (Only include if NOT deleted/hidden)
    if ($master_status === 'Completed' && !$is_completed_notified && !$is_deleted_master) {
        $notifications[] = [
            'id' => $c_id,
            'type' => 'completion',
            'message' => "🎉 <strong>CLEARANCE COMPLETE!</strong> Your certificate is ready.",
            'class'   => 'approved',
            'link'    => "certificate.php?clearance_id=$c_id",
            'is_read' => $is_read_master
        ];
        if (!$is_read_master) { // Count if is_read = 0
            $notification_count++;
        }
        $is_completed_notified = true;
    }

    // 2. CHECK INDIVIDUAL SIGNER ACTIONS (Only include if NOT deleted/hidden)
    if (in_array($signer_status, ['Approved', 'Rejected', 'Cancelled']) && !$is_deleted_signature) {
        $signer_name = htmlspecialchars($r['signer_name'] ?? $r['signer_type']);
        $date = date('M d', strtotime($r['signed_date'] ?? $r['date_requested']));
        $alert_class = strtolower($signer_status) === 'approved' ? 'approved' : 'rejected';

        $notifications[] = [
            'id' => $sig_id,
            'type' => 'signature',
            'message' => "<strong>{$signer_name}</strong> {$signer_status} your request on {$date}.",
            'class'   => $alert_class,
            'link'    => "status.php?cid=$c_id", // Changed redirect to status.php
            'is_read' => $is_read_signature
        ];
        if (!$is_read_signature) { // Count if is_read = 0
            $notification_count++;
        }
    }
}
// --- END NOTIFICATION LOGIC ---


// --- 2. Fetch Organizations ---
$org_query = "SELECT org_id, org_name, requirements FROM organization ORDER BY org_name";
$org_stmt = $conn->query($org_query);
$org_result = $org_stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. Fetch Faculty (Signers) ---
$faculty_query = "
    SELECT
        faculty_id,
        CONCAT_WS(' ', fName, lName) AS faculty_name,
        position,
        requirements,
        department_id
    FROM
        faculty
    WHERE
        requirements IS NOT NULL
        OR position IN ('Dean', 'SA Coordinator', 'Adviser', 'Department Head', 'Registrar', 'Librarian')

    ORDER BY
        FIELD(position, 'Dean', 'Department Head', 'Adviser', 'SA Coordinator', 'Registrar', 'Librarian'), lName, fName";

$faculty_stmt = $conn->prepare($faculty_query);
$faculty_stmt->execute();
$all_faculty_members = $faculty_stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Filter Faculty ---
$faculty_members = [];
foreach ($all_faculty_members as $member) {
    $include = false;
    if ($member['position'] !== 'Department Head' && $member['position'] !== 'Adviser') { $include = true; }
    if ($member['position'] === 'Adviser' && $member['faculty_id'] == $adviser_id) { $include = true; }
    if ($member['position'] === 'Department Head' && $member['department_id'] == $student_dept_id) { $include = true; }
    if ($include) { $faculty_members[] = $member; }
}

// --- 4. Fetch Current Clearance Status ---
$most_recent_clearance = $clearanceObj->getStudentHistory($student_id, null)[0] ?? null;
$clearance_id = null;
$currentSignatures = [];

if ($most_recent_clearance) {
    $status = $most_recent_clearance['status'];
    if ($status !== 'Approved') {
        $clearance_id = $most_recent_clearance['clearance_id'];
        $currentSignatures = $clearanceObj->getSignaturesByClearance($clearance_id);
    }
}

function getSignerStatus($type, $ref_id, $signatures) {
    foreach ($signatures as $sig) {
        if ($sig['signer_type'] == $type && $sig['signer_ref_id'] == $ref_id) {
            return $sig['signed_status'];
        }
    }
    return 'New';
}

// --- 5. Handle POST Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_clearance'])) {
    $signer_type = trim($_POST['signer_type']);
    $signer_ref_id = trim($_POST['signer_ref_id']);
    $uploaded_file = null;
    $target_dir = "../uploads/";

    if (!$clearance_id) {
        $clearance_id = $clearanceObj->createClearanceRequest($student_id, null);
    }

    if ($clearance_id) {
        $target_file = null;
        if (isset($_FILES['requirement_file']) && $_FILES['requirement_file']['error'] == 0) {
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $file_extension = pathinfo($_FILES["requirement_file"]["name"], PATHINFO_EXTENSION);
            $safe_ref_id = is_numeric($signer_ref_id) ? $signer_ref_id : 'unk';
            $new_filename = $student_id . "_" . $clearance_id . "_" . $safe_ref_id . "_" . time() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;

            if (move_uploaded_file($_FILES["requirement_file"]["tmp_name"], $target_file)) {
                $uploaded_file = $new_filename;
            } else {
                $message = "❌ Error uploading file.";
                $messageType = "error";
                goto end_post_processing;
            }
        }

        $sign_order = 10;
        if ($signer_type === 'Faculty') {
            foreach ($faculty_members as $member) {
                if ($member['faculty_id'] == $signer_ref_id) {
                    $pos = $member['position'] ?? '';
                    $order_map = ['SA Coordinator'=>20, 'Adviser'=>30, 'Department Head'=>40, 'Dean'=>50, 'Registrar'=>60];
                    $sign_order = $order_map[$pos] ?? 10;
                    break;
                }
            }
        }

        $ok = $clearanceObj->submitSignatureUpload($clearance_id, $signer_type, $signer_ref_id, $uploaded_file, $sign_order);

        if ($ok) {
            header("Location: request.php?msg=" . urlencode("✅ Request submitted.") . "&type=success");
            exit;
        } else {
            if ($uploaded_file && file_exists($target_file)) unlink($target_file);
            $message = "❌ Could not submit request. A Pending or Approved request already exists for this signer.";
            $messageType = "error";
        }
    } else {
        $message = "❌ Could not create or find a clearance record.";
        $messageType = "error";
    }
    end_post_processing:
}

if (isset($_GET['msg'])) { $message = htmlspecialchars($_GET['msg']); $messageType = htmlspecialchars($_GET['type']); }

function formatDateTime($dateTimeString) {
    if (empty($dateTimeString)) return 'N/A';
    return date('M d, Y h:i A', strtotime($dateTimeString));
}

$org_query = "SELECT org_id, org_name, requirements, profile_picture FROM organization ORDER BY org_name";
$org_result = $conn->query($org_query)->fetchAll(PDO::FETCH_ASSOC);

$faculty_query = "SELECT faculty_id, CONCAT_WS(' ', fName, lName) AS faculty_name, position, requirements, department_id, profile_picture
    FROM faculty WHERE requirements IS NOT NULL OR position IN ('Dean', 'SA Coordinator', 'Adviser', 'Department Head', 'Registrar', 'Librarian')
    ORDER BY FIELD(position, 'Dean', 'Department Head', 'Adviser', 'SA Coordinator', 'Registrar', 'Librarian'), lName, fName";
$all_faculty_members = $conn->query($faculty_query)->fetchAll(PDO::FETCH_ASSOC);

$faculty_members = [];
foreach ($all_faculty_members as $member) {
    $include = false;
    if ($member['position'] !== 'Department Head' && $member['position'] !== 'Adviser') $include = true;
    if ($member['position'] === 'Adviser' && $member['faculty_id'] == $adviser_id) $include = true;
    if ($member['position'] === 'Department Head' && $member['department_id'] == $student_dept_id) $include = true;
    if ($include) $faculty_members[] = $member;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Request Clearance</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* --- HEADER & NOTIFICATION STYLES --- */
    .header-actions { display: flex; align-items: center; gap: 20px; }
    .notification-icon-container { position: relative; display: inline-block; }
    .notification-bell-btn {
        background: none; border: none; color: var(--color-text-light); font-size: 1.5em; cursor: pointer; padding: 0; margin: 0; line-height: 1; position: relative; transition: color 0.2s;
    }
    .notification-bell-btn:hover { color: var(--color-accent-green); }
    .notification-badge {
        position: absolute; top: -5px; right: -10px; background-color: var(--color-card-rejected); color: white; border-radius: 50%; padding: 2px 6px; font-size: 0.7em; line-height: 1; font-weight: 700;
    }
    .notification-dropdown-content {
        display: none; position: absolute; right: 0; top: 40px; background-color: #f9f9f9; min-width: 320px; max-height: 400px; overflow-y: auto; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 200; border-radius: 5px; padding: 15px; border: 1px solid #ddd; text-align: left;
    }
    .notification-dropdown-content.show { display: block; }

    /* New Notification Item Styles - Focused on fixing dimmed text */
    .notification-item {
        display: flex; justify-content: space-between; align-items: center; padding: 12px; margin-top: 5px; text-decoration: none; font-size: 0.9em; border-radius: 4px; transition: background-color 0.2s; border-bottom: 1px solid #eee;
    }
    /* FIX: Ensure dark text color for all links within the item */
    .notification-item a { color: var(--color-text-dark); text-decoration: none; }

    .notification-item:hover { background-color: #e9ecef; }
    .notification-item.unread {
        background-color: #fff;
        border-left: 4px solid var(--color-accent-green);
        font-weight: 600; /* Bold unread items */
    }
    .notification-item.read {
        background-color: #f4f4f4;
        color: #777; /* Dim the overall item */
        border-left: 4px solid #ccc;
    }
    /* Fix for text color in read state */
    .notification-item.read a { color: #777; }

    .notif-text { flex-grow: 1; margin-right: 10px; }
    .mark-read-btn {
        background: none; border: none; color: #aaa; cursor: pointer; font-size: 1.2em; padding: 0 5px; transition: color 0.2s;
    }
    .mark-read-btn:hover { color: var(--color-accent-green); }
    /* New style for the individual delete button */
    .delete-btn {
        background: none; border: none; color: #C0392B; cursor: pointer; font-size: 1.2em; padding: 0 5px; transition: color 0.2s; margin-left: 5px;
    }
    .delete-btn:hover { color: #8C2A1E; }
    .action-group {
        display: flex; align-items: center;
    }

    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);justify-content: center; align-items: center; }
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
        <div class="profile-icon">
            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile">
        </div>
        <div class="profile-name" onclick="openProfileModal('student')" style="font-weight: 700; margin-bottom: 5px;"><?= htmlspecialchars($student_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php">Dashboard</a>
    <a href="request.php" class="active">Request</a>
    <a href="status.php">Status</a>
    <a href="history.php">History</a>
</div>

<div class="main-content">
    <div class="page-header">
        <div class="logo-text">CCS Clearance - Request</div>

        <div class="header-actions">
            <div class="notification-icon-container">
                <button class="notification-bell-btn" onclick="toggleNotificationDropdown()">
                    <i class="fas fa-bell"></i>
                    <?php if ($notification_count > 0): ?>
                        <span class="notification-badge"><?= $notification_count ?></span>
                    <?php endif; ?>
                </button>

                <div id="notification-dropdown" class="notification-dropdown-content">
                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ddd; padding-bottom:8px; margin-bottom:5px;">
                        <h4 style="margin:0; color:var(--color-sidebar-bg);">Notifications</h4>
                        <span style="font-size:0.8em; color:#777;"><?= $notification_count ?> new</span>
                    </div>

                    <?php if (count($notifications) > 0): ?>
                        <div style="display: flex; justify-content: space-between; margin: 5px 0 10px 0; border-bottom: 1px dashed #ddd; padding-bottom: 8px;">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="mark_all_read" value="1">
                                <button type="submit" style="background: none; border: none; color: #666; cursor: pointer; font-size: 0.85em; text-decoration: underline;">
                                    Mark all as read
                                </button>
                            </form>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="delete_all" value="1">
                                <button type="submit" style="background: none; border: none; color: #C0392B; cursor: pointer; font-size: 0.85em; text-decoration: underline;">
                                    Clear All (<?= count($notifications) ?>)
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $n): ?>
                            <div class="notification-item <?= $n['is_read'] ? 'read' : 'unread' ?>">
                                <form method="POST" style="margin:0; display:none;" id="form-read-<?= $n['id'] ?>-<?= $n['type'] ?>">
                                    <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                    <input type="hidden" name="mark_read_type" value="<?= $n['type'] ?>">
                                    <input type="hidden" name="redirect_link" value="<?= htmlspecialchars($n['link']) ?>">
                                </form>
                                <form method="POST" style="margin:0; display:none;" id="form-delete-<?= $n['id'] ?>-<?= $n['type'] ?>">
                                    <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                    <input type="hidden" name="mark_read_type" value="<?= $n['type'] ?>">
                                    <input type="hidden" name="delete_one" value="1">
                                </form>

                                <a href="<?= $n['link'] ?>"
                                   class="notif-text"
                                   onclick="event.preventDefault(); document.getElementById('form-read-<?= $n['id'] ?>-<?= $n['type'] ?>').submit();">
                                    <?= $n['message'] ?>
                                </a>

                                <div class="action-group">
                                    <button type="button"
                                            class="mark-read-btn"
                                            title="Mark as Read"
                                            onclick="document.getElementById('form-read-<?= $n['id'] ?>-<?= $n['type'] ?>').submit();">
                                        <i class="fas fa-check-circle"></i>
                                    </button>

                                    <button type="button"
                                            class="delete-btn"
                                            title="Clear Notification"
                                            onclick="document.getElementById('form-delete-<?= $n['id'] ?>-<?= $n['type'] ?>').submit();">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; margin: 10px 0; font-size: 0.9em; color: #777;">No notifications.</p>
                    <?php endif; ?>
                </div>
            </div>
            <a href="../index.php" class="log-out-btn">LOG OUT</a>
        </div>
    </div>

    <div class="page-content-wrapper">
        <div class="alert-warning"><span>!</span> Select the organization or faculty where you want to request a clearance.</div>
        <?php if ($message): ?>
            <div class="alert-warning" style="background-color: <?= $messageType == 'error' ? '#f8d7da' : '#d4edda' ?>; color: <?= $messageType == 'error' ? '#721c24' : '#155724' ?>; border-color: <?= $messageType == 'error' ? '#f5c6cb' : '#c3e6cb' ?>;">
                <span style="font-size: 1.5em;"><?= $messageType == 'error' ? '⚠️' : '✅' ?></span> <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="signer-grid">
            <?php foreach ($org_result as $org):
                $status = getSignerStatus('Organization', $org['org_id'], $currentSignatures);
                $org_name = htmlspecialchars($org['org_name']);

                // --- MODIFIED LOGO LOGIC FOR ORGANIZATIONS ---
                $pic = $org['profile_picture'] ?? '';
                if (!empty($pic) && strpos($pic, 'org_') === 0) {
                    $logo_url = '../assets/img/profiles/' . $pic;
                } else {
                    $logo_url = '../assets/img/profile.png';
                }
                // ---------------------------------------------

                $js_safe_req = addslashes($org['requirements'] ?? '');
                $badge_status_class = strtolower($status);
                $status_clean = trim($status);
                $is_disabled = ($status_clean === 'Approved' || $status_clean === 'Pending');
                $card_class = $is_disabled ? 'signer-card-org status-approved-card' : 'signer-card-org';
                $onclick_handler = $is_disabled ? '' : "openModal('Organization',{$org['org_id']}, '{$js_safe_req}', '".addslashes($org_name)."')";
            ?>
                <div class="<?= $card_class ?>" onclick="<?= $onclick_handler ?>" style="<?= $is_disabled ? 'cursor: default; opacity: 0.6;' : 'cursor: pointer;' ?>">
                    <div class="logo-small" style="background-image: url('<?= htmlspecialchars($logo_url) ?>');"></div>
                    <h3><?= $org_name ?></h3>
                    <p style="color: #ccc; margin-top: 5px;">Organization</p>
                    <div class="status-badge status-<?= $badge_status_class ?>"><?= htmlspecialchars($status) ?></div>
                </div>
            <?php endforeach; ?>
            <?php foreach ($faculty_members as $faculty):
                $status = getSignerStatus('Faculty', $faculty['faculty_id'], $currentSignatures);
                $display_name = htmlspecialchars($faculty['faculty_name']);
                $display_position = htmlspecialchars($faculty['position']);

                // --- MODIFIED LOGO LOGIC FOR FACULTY ---
                $pic = $faculty['profile_picture'] ?? '';
                if (!empty($pic) && strpos($pic, 'faculty_') === 0) {
                    $logo_url = '../assets/img/profiles/' . $pic;
                } else {
                    $logo_url = '../assets/img/profile.png';
                }
                // ---------------------------------------

                $js_safe_req = addslashes($faculty['requirements'] ?? 'No requirements set.');
                $badge_status_class = strtolower($status);
                $status_clean = trim($status);
                $is_disabled = ($status_clean === 'Approved' || $status_clean === 'Pending');
                $card_class = $is_disabled ? 'signer-card-faculty status-approved-card' : 'signer-card-faculty';
                $onclick_handler = $is_disabled ? '' : "openModal('Faculty',{$faculty['faculty_id']}, '{$js_safe_req}', '".addslashes($display_name)."')";
            ?>
                <div class="<?= $card_class ?>" onclick="<?= $onclick_handler ?>" style="<?= $is_disabled ? 'cursor: default; opacity: 0.6;' : 'cursor: pointer;' ?>">
                    <div class="logo-small" style="background-image: url('<?= htmlspecialchars($logo_url) ?>');"></div>
                    <h3><?= $display_name ?></h3>
                    <p><?= $display_position ?></p>
                    <div class="status-badge status-<?= $badge_status_class ?>"><?= htmlspecialchars($status) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="modal" class="modal">
            <div class="modal-content">
                <span style="position:absolute; right:14px; top:10px; cursor:pointer; font-size: 20px;" onclick="closeModal()">✕</span>
                <h2>Request Signature</h2>
                <p>Signer: <strong id="mName"></strong></p>
                <p><span style="font-weight: 600;">Requirements:</span> <span id="mReq"></span></p>
                <form method="post" action="request.php" enctype="multipart/form-data">
                    <input type="hidden" name="signer_type" id="in_type">
                    <input type="hidden" name="signer_ref_id" id="in_ref">
                    <input type="hidden" name="request_clearance" value="1">
                    <div class="file-upload-box">
                        <div class="upload-icon"><img src="../assets/img/upload_icon.png" alt="Upload" style="height: 40px;"></div>
                        <p id="file_display_name" style="margin-bottom: 5px; font-size: 1em;">Select your file or drag and drop</p>
                        <label for="requirement_file" class="browse-button">Browse</label>
                        <input type="file" name="requirement_file" id="requirement_file" accept=".pdf, .png, .jpg, .jpeg, .docx">
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="cancel-modal-btn">Cancel</button>
                        <button type="submit" class="submit-modal-btn">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="profileModalStudent" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Student Profile</h3>
        <form method="POST" action="request.php" enctype="multipart/form-data">
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
    const fileInput = document.getElementById('requirement_file');
    const fileDisplayName = document.getElementById('file_display_name');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            fileDisplayName.textContent = this.files.length > 0 ? this.files[0].name : "Select your file or drag and drop";
        });
    }
    function openModal(type, ref, req, name) {
        document.getElementById('mName').innerText = name;
        document.getElementById('mReq').innerHTML = req.replace(/\\r\\n|\r\n|\n|\r/g, '<br>');
        document.getElementById('in_type').value = type;
        document.getElementById('in_ref').value = ref;
        document.getElementById('modal').style.display = 'flex';
        if (fileInput) fileInput.value = '';
        if (fileDisplayName) fileDisplayName.textContent = "Select your file or drag and drop";
    }
    function closeModal() { document.getElementById('modal').style.display = 'none'; }
    function toggleNotificationDropdown() { document.getElementById("notification-dropdown").classList.toggle("show"); }

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
        if (!event.target.matches('.notification-bell-btn, .notification-bell-btn *')) {
            var dropdowns = document.getElementsByClassName("notification-dropdown-content");
            for (var i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show')) {
                    openDropdown.classList.remove('show');
                }
            }
        }
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
</script>
</body>
</html>