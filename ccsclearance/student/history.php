<?php
session_start();
require_once "../classes/Clearance.php";
require_once "../classes/Database.php";
require_once "../classes/Account.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'student') {
    header("Location: ../index.php");
    exit;
}

$student_id = $_SESSION['ref_id'];
$clearanceObj = new Clearance();
$accountObj = new Account();
$db = new Database();
$conn = $db->connect();

$profile_data = $clearanceObj->getStudentProfileData($student_id);
$account_data = $accountObj->getAccountDetailsByRefId($student_id, 'student');
$message = "";
$messageType = "";

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
        $student_update_data = [ 'fName' => $fName, 'mName' => $mName, 'lName' => $lName, 'profile_picture' => $new_pic_filename ];
        $clearanceObj->updateStudentProfile($student_id, $student_update_data, $conn);
        $accountObj->updateAccountDetails($account_data['account_id'], $email, empty($password) ? null : $password, $conn);
        $conn->commit();
        $message = "✅ Profile updated successfully!";
        $messageType = "success";
        header("Location: history.php?msg=" . urlencode($message) . "&type=$messageType");
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "❌ Database error during update: " . $e->getMessage();
        $messageType = "error";
    }
    skip_profile_update:
}

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

if (isset($_POST['delete_all'])) {
    $clearanceObj->deleteAllNotifications($student_id);
    header("Location: history.php");
    exit;
}

if (isset($_POST['delete_one']) && isset($_POST['mark_read_id']) && isset($_POST['mark_read_type'])) {
    $clearanceObj->clearNotification($_POST['mark_read_id'], $_POST['mark_read_type']);
    header("Location: history.php");
    exit;
}

if (isset($_POST['mark_all_read'])) {
    $clearanceObj->markAllNotificationsRead($student_id);
    header("Location: history.php");
    exit;
}

if (isset($_POST['mark_read_id']) && isset($_POST['mark_read_type']) && !isset($_POST['delete_one'])) {
    $clearanceObj->markNotificationRead($_POST['mark_read_id'], $_POST['mark_read_type']);

    $redirect_link = $_POST['redirect_link'] ?? 'history.php';

    header("Location: " . $redirect_link);
    exit;
}

function formatDateTime($dateTimeString) {
    if (empty($dateTimeString)) return 'N/A';
    return date('M d, Y h:i A', strtotime($dateTimeString));
}

$recent_requests = $clearanceObj->getRecentClearanceRequests($student_id, 10);
$notifications = [];
$notification_count = 0;
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

    if ($master_status === 'Completed' && !$is_completed_notified && !$is_deleted_master) {
        $notifications[] = [
            'id' => $c_id,
            'type' => 'completion',
            'message' => "🎉 <strong>CLEARANCE COMPLETE!</strong> Your certificate is ready.",
            'class'   => 'approved',
            'link'    => "certificate.php?clearance_id=$c_id",
            'is_read' => $is_read_master
        ];
        if (!$is_read_master) {
            $notification_count++;
        }
        $is_completed_notified = true;
    }

    if (in_array($signer_status, ['Approved', 'Rejected', 'Cancelled']) && !$is_deleted_signature) {
        $signer_name = htmlspecialchars($r['signer_name'] ?? $r['signer_type']);
        $date = date('M d', strtotime($r['signed_date'] ?? $r['date_requested']));
        $alert_class = strtolower($signer_status) === 'approved' ? 'approved' : 'rejected';

        $notifications[] = [
            'id' => $sig_id,
            'type' => 'signature',
            'message' => "<strong>{$signer_name}</strong> {$signer_status} your request on {$date}.",
            'class'   => $alert_class,
            'link'    => "history.php?cid=$c_id",
            'is_read' => $is_read_signature
        ];
        if (!$is_read_signature) {
            $notification_count++;
        }
    }
}


$filter_year = isset($_GET['year']) ? trim($_GET['year']) : '';
$filter_term = isset($_GET['term']) ? trim($_GET['term']) : '';

$all_history = $clearanceObj->getStudentSignatureHistory($student_id);
$grouped_history = [];
$display_statuses = ['Approved', 'Rejected', 'Cancelled'];


$available_years = [];
$available_terms = [];
foreach ($all_history as $row) {
    $school_year = $row['school_year'] ?? null;
    $term = $row['term'] ?? null;

    if (!empty($school_year) && !in_array($school_year, $available_years)) {
        $available_years[] = $school_year;
    }
    if (!empty($term) && !in_array($term, $available_terms)) {
        $available_terms[] = $term;
    }
}
sort($available_years);
$available_years = array_reverse($available_years); 


$clearance_statuses = [];
if (!empty($all_history)) {
    $clearance_ids = array_unique(array_column($all_history, 'clearance_id'));
    foreach ($clearance_ids as $cid) {
        $final_status = $clearanceObj->getClearanceFinalStatus($cid);
        $clearance_statuses[$cid] = $final_status;
    }
}


foreach ($all_history as $row) {
    
    if (!empty($filter_year) && ($row['school_year'] ?? '') != $filter_year) {
        continue;
    }
    
    if (!empty($filter_term) && ($row['term'] ?? '') != $filter_term) {
        continue;
    }

    if (in_array($row['signed_status'], $display_statuses)) {
        $grouped_history[$row['clearance_id']][] = $row;
    }
}

if (isset($_GET['msg'])) { $message = htmlspecialchars($_GET['msg']); $messageType = htmlspecialchars($_GET['type']); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Clearance History</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https:
<style>
    .status-table {
        width: 100%; border-collapse: collapse; margin-top: 20px;
        background-color: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .status-table th, .status-table td {
        padding: 12px 15px; border-bottom: 1px solid #eee; text-align: left;
    }
    .status-table th { background-color: #f2f2f2; font-weight: 600; }

    
    .status-table th:nth-child(1), .status-table td:nth-child(1) { text-align: center; } 
    .status-table th:nth-child(2), .status-table td:nth-child(2) { text-align: left; }   
    .status-table th:nth-child(3), .status-table td:nth-child(3) { text-align: center; } 
    .status-table th:nth-child(4), .status-table td:nth-child(4) { text-align: center; } 
    .status-table th:nth-child(5), .status-table td:nth-child(5) { text-align: center; } 
    .status-table th:nth-child(6), .status-table td:nth-child(6) { text-align: left; }   


    .status-approved { color: var(--color-card-approved, #4CAF50); font-weight: 700; }
    .status-rejected, .status-cancelled, .status-superseded { color: var(--color-card-rejected, #C0392B); font-weight: 700; }

    .remarks-box { font-size: 0.9em; padding: 5px; border-left: 3px solid var(--color-card-rejected, #f44336); margin-top: 5px; text-align: left; color: var(--color-text-dark, #333); }
    .remarks-box.approved { border-left-color: var(--color-card-approved, #4CAF50); }
    .view-upload-btn { background-color: var(--color-accent-green, #4CAF50); color: white; padding: 6px 10px; border-radius: 4px; text-decoration: none; display: inline-block; font-weight: 500; }
    .empty-history-row td { font-style: italic; padding: 20px; background-color: #fff; border: none; text-align: center; }

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

    .notification-item {
        display: flex; justify-content: space-between; align-items: center; padding: 12px; margin-top: 5px; text-decoration: none; font-size: 0.9em; border-radius: 4px; transition: background-color 0.2s; border-bottom: 1px solid #eee;
    }
    .notification-item a { color: var(--color-text-dark); text-decoration: none; }

    .notification-item:hover { background-color: #e9ecef; }
    .notification-item.unread {
        background-color: #fff;
        border-left: 4px solid var(--color-accent-green);
        font-weight: 600;
    }
    .notification-item.read {
        background-color: #f4f4f4;
        color: #777;
        border-left: 4px solid #ccc;
    }
    .notification-item.read a { color: #777; }

    .notif-text { flex-grow: 1; margin-right: 10px; }
    .mark-read-btn {
        background: none; border: none; color: #aaa; cursor: pointer; font-size: 1.2em; padding: 0 5px; transition: color 0.2s;
    }
    .mark-read-btn:hover { color: var(--color-accent-green); }
    .delete-btn {
        background: none; border: none; color: #C0392B; cursor: pointer; font-size: 1.2em; padding: 0 5px; transition: color 0.2s; margin-left: 5px;
    }
    .delete-btn:hover { color: #8C2A1E; }
    .action-group {
        display: flex; align-items: center;
    }

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
        <div class="profile-name" onclick="openProfileModal('student')" style="font-weight:700;margin-bottom:5px;"><?= htmlspecialchars($student_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php">Dashboard</a>
    <a href="request.php">Request</a>
    <a href="status.php">Status</a>
    <a href="history.php" class="active">History</a>
</div>

<div class="main-content">
    <div class="page-header">
        <div class="logo-text">
            <img src="../assets/img/ccs_logo.png" alt="CCS Logo" style="height:40px;">
            CCS Clearance - History
        </div>
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
        <h2>Clearance History</h2>
        <hr>

        
        <div class="filter-controls" style="margin-bottom: 20px; background: #f5f5f5; padding: 15px; border-radius: 8px;">
            <form method="GET" action="history.php" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label for="year" style="display: block; margin-bottom: 5px; font-weight: 600;">📅 School Year:</label>
                    <select id="year" name="year" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">All Years</option>
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?= htmlspecialchars($year) ?>" <?= $filter_year == $year ? 'selected' : '' ?>>
                                <?= htmlspecialchars($year) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1; min-width: 150px;">
                    <label for="term" style="display: block; margin-bottom: 5px; font-weight: 600;">📚 Term:</label>
                    <select id="term" name="term" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">All Terms</option>
                        <option value="1st" <?= $filter_term == '1st' ? 'selected' : '' ?>>1st Term</option>
                        <option value="2nd" <?= $filter_term == '2nd' ? 'selected' : '' ?>>2nd Term</option>
                        <option value="Summer" <?= $filter_term == 'Summer' ? 'selected' : '' ?>>Summer</option>
                    </select>
                </div>

                <button type="submit" class="btn-primary" style="padding: 8px 20px; height: 38px;">Apply Filter</button>

                <?php if (!empty($filter_year) || !empty($filter_term)): ?>
                    <a href="history.php" class="btn-primary" style="padding: 8px 20px; background-color: #6c757d; text-decoration: none; display: inline-block; height: 38px; line-height: 22px;">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <table class="status-table">
            <thead>
                <tr>
                    <th>Clearance ID</th>
                    <th>Signed By</th>
                    <th>Date Requested</th>
                    <th>Date Signed</th>
                    <th>Status</th>
                    <th>Remarks / Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($grouped_history)): ?>
                <?php foreach ($grouped_history as $clearance_id => $signatures): ?>
                    <?php foreach ($signatures as $h):
                        $raw_status = $h['signed_status'];
                        $remarks = trim($h['remarks'] ?? '');
                        $is_fully_approved = $h['is_fully_approved'] ?? false;

                        $status_to_display = htmlspecialchars($raw_status);
                        $status_class = strtolower($raw_status);

                        if ($raw_status === 'Superseded') {
                            $status_to_display = 'Rejected';
                            $status_class = 'rejected';
                        }
                    ?>
                        <tr>
                            <td style="text-align: center;"><?= htmlspecialchars($h['clearance_id']) ?></td>
                            <td><?= htmlspecialchars($h['signer_name'] ?? 'N/A') ?></td>
                            <td><?= formatDateTime($h['date_requested']) ?></td>
                            <td><?= $h['signed_date'] ? formatDateTime($h['signed_date']) : 'N/A' ?></td>

                            <td class="status-<?= $status_class ?>">
                                <?= $status_to_display ?>
                            </td>

                            <td>
                                <?php
                                $clearance_id_for_check = $h['clearance_id'];
                                $is_cleared = isset($clearance_statuses[$clearance_id_for_check]) && $clearance_statuses[$clearance_id_for_check] === 'CLEARED';

                                if ($raw_status === 'Approved') {
                                    if ($is_cleared) {
                                        echo '<a href="certificate.php?clearance_id='.urlencode($h['clearance_id']).'" class="view-upload-btn" style="height: 35px; align-self: flex-end; background-color: grey">View Certificate</a>';
                                    } elseif (!empty($remarks)) {
                                        echo '<div class="remarks-box approved">'.nl2br(htmlspecialchars($remarks)).'</div>';
                                    } else {
                                        echo '—';
                                    }
                                } elseif (in_array($raw_status, ['Rejected','Cancelled','Superseded'])) {
                                    echo '<div class="remarks-box">'.(!empty($remarks) ? nl2br(htmlspecialchars($remarks)) : '<i>No remarks provided.</i>').'</div>';
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="empty-history-row"><td colspan="6">No clearance history available.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div id="profileModalStudent" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Student Profile</h3>
        <form method="POST" action="history.php" enctype="multipart/form-data">
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

    <?php include '../includes/burger_menu_script.php'; ?>

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