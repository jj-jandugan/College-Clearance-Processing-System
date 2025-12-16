<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'signer') {
    header("Location: ../index.php");
    exit;
}

require_once "../classes/Signer.php";
require_once "../classes/Database.php";
require_once "../classes/Clearance.php";
require_once "../classes/Account.php";

$signer_id = $_SESSION['ref_id'];
$signerObj = new Signer();
$accountObj = new Account();
$db = new Database();
$conn = $db->connect();

$profile_data = $signerObj->getSignerDetails($signer_id);
$account_data = $accountObj->getAccountDetailsByRefId($signer_id, 'signer');
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
        $message = "❌ Password mismatch.";
        $messageType = "error";
        goto skip_profile_update;
    }

    $new_pic_filename = $current_pic;

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
        $target_dir = "../assets/img/profiles/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
        $new_pic_filename = "faculty_" . $faculty_id . "_" . time() . "." . $file_extension;
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
        $signer_update_data = ['fName' => $fName, 'mName' => $mName, 'lName' => $lName, 'profile_picture' => $new_pic_filename];
        $signerObj->updateSignerProfile($signer_id, $signer_update_data, $conn);
        $accountObj->updateAccountDetails($account_data['account_id'], $email, empty($password) ? null : $password, $conn);
        $conn->commit();

        $redirect_url = "pending.php?msg=" . urlencode(" Profile updated!") . "&type=success";
        if (isset($_GET['search'])) $redirect_url .= "&search=" . urlencode($_GET['search']);
        if (isset($_GET['class_group'])) $redirect_url .= "&class_group=" . urlencode($_GET['class_group']);
        elseif (isset($_GET['filter'])) $redirect_url .= "&filter=" . urlencode($_GET['filter']);

        header("Location: " . $redirect_url);
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "❌ Error: " . $e->getMessage(); $messageType = "error";
    }
    skip_profile_update:
}

$profile_data = $signerObj->getSignerDetails($signer_id);
$account_data = $accountObj->getAccountDetailsByRefId($signer_id, 'signer');

$fName = empty($profile_data['fName']) ? 'Signer' : $profile_data['fName'];
$mName = $profile_data['mName'] ?? '';
$lName = empty($profile_data['lName']) ? 'Name' : $profile_data['lName'];
$middle_initial = !empty($mName) ? substr($mName, 0, 1) . '. ' : '';
$faculty_name = trim("{$fName} {$middle_initial}{$lName}");
$position = $profile_data['position'] ?? 'N/A';

$current_pic_name = !empty($profile_data['profile_picture']) ? $profile_data['profile_picture'] : 'profile.png';
if (strpos($current_pic_name, 'faculty_') === 0) {
    $profile_pic_path = '../assets/img/profiles/' . $current_pic_name;
} else {
    $profile_pic_path = '../assets/img/profile.png';
}

if (isset($_POST['mark_read_keep']) && isset($_POST['mark_read_id'])) {
    $signerObj->markNotificationRead($_POST['mark_read_id']);
    header("Location: pending.php");
    exit;
}

if (isset($_POST['delete_one']) && isset($_POST['mark_read_id'])) {
    $signerObj->clearNotification($_POST['mark_read_id']);
    header("Location: pending.php");
    exit;
}

if (isset($_POST['mark_all_read'])) {
    $signerObj->markAllNotificationsRead($signer_id);
    header("Location: pending.php");
    exit;
}
if (isset($_POST['delete_all'])) {
    $signerObj->markAllNotificationsRead($signer_id);
    header("Location: pending.php");
    exit;
}

$pending_count = $signerObj->getPendingSignatureCount($signer_id);
$details = $signerObj->getSignerDetails($signer_id);
if (!$details) {
    $faculty_name = "Signer";
    $position = "";
} else {
    $faculty_name = ($details['fName'] ?? '') . ' ' . ($details['lName'] ?? '');
    $position = $details['position'];
}

$classGroups = [];
$filter_options = [];
$position_lower = strtolower($position);

if ($position_lower === 'adviser') {
    $filter_options = $signerObj->getAssignedClassGroups($signer_id);
} elseif ($position_lower !== 'dept head' && $position_lower !== 'department head') {
    $filter_options = $db->getAllDepartments();
}

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_class_group = isset($_GET['class_group']) ? trim($_GET['class_group']) : '';
$filter_value = $selected_class_group ?: (isset($_GET['filter']) ? trim($_GET['filter']) : '');
$filter_name = 'section_filter';

$pending_requests = $signerObj->getPendingRequests($signer_id, $search_term, $filter_value, $filter_name);

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $signature_id = $_POST['signature_id'] ?? null;
    $clearance_id = $_POST['clearance_id'] ?? null;
    $sign_order = $_POST['sign_order'] ?? null;
    $action = $_POST['action'] ?? null;
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$signature_id || !$clearance_id || !$sign_order || !$action) {
        $message = "Missing form data.";
    } else {
        if (!$signerObj->checkPrerequisites($clearance_id, $sign_order)) {
            $message = "Cannot sign yet! Not all previous signers have approved this request.";
        } else {
            $ok = $signerObj->signClearance($signature_id, $action, $remarks ?: null);
            if ($ok) {
                 require_once "../classes/Clearance.php";
                $clearanceObj = new Clearance();

                
                $signer_id_query = "SELECT signer_id FROM clearance_signature WHERE signature_id = :sid";
                $stmt_se = $conn->prepare($signer_id_query);
                $stmt_se->execute([':sid' => $signature_id]);
                $fetched_signer_id = $stmt_se->fetchColumn();

                $clearanceObj->sendSignerActionNotification($clearance_id, $fetched_signer_id, $action);

                $clearanceObj->checkForCompletion($clearance_id);
                $redirect_url = "pending.php?success=1";
                if ($search_term) $redirect_url .= "&search=" . urlencode($search_term);
                if ($selected_class_group) $redirect_url .= "&class_group=" . urlencode($selected_class_group);
                elseif (!empty($filter_value)) $redirect_url .= "&filter=" . urlencode($filter_value);
                header("Location: " . $redirect_url);
                exit;
            } else {
                $message = "Failed to process request.";
            }
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Request processed successfully!";
}

if (isset($_GET['msg'])) { $message = htmlspecialchars($_GET['msg']); $messageType = htmlspecialchars($_GET['type']); }

$raw_notifications = $signerObj->getSignerNotifications($signer_id, 10);
$notifications = [];
$notification_count = 0;

foreach ($raw_notifications as $r) {
    $status = $r['signed_status'];
    $is_read = (bool)($r['is_read'] ?? 0);
    $date = date('M d', strtotime($r['date_requested']));
    $link = ($status === 'Pending') ? "pending.php" : "history.php";

    if ($status === 'Pending') {
        $msg = "<strong>NEW REQUEST:</strong> {$r['student_name']} requested sign-off on {$date}.";
        $class = 'approved';
    } elseif ($status === 'Cancelled') {
        $msg = "<strong>REQUEST CANCELLED:</strong> {$r['student_name']} cancelled request (ID: {$r['clearance_id']}).";
        $class = 'rejected';
    } else {
        continue;
    }

    $notifications[] = [
        'id' => $r['signature_id'],
        'message' => $msg,
        'class'   => $class,
        'link'    => $link,
        'is_read' => $is_read
    ];
    if (!$is_read) {
        $notification_count++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title><?= htmlspecialchars($position) ?> Pending Sign-offs</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https:
    <style>
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
        .mark-read-btn-icon {
            background: none; border: none; color: #aaa; cursor: pointer; font-size: 1.2em; padding: 0 5px; transition: color 0.2s;
        }
        .mark-read-btn-icon:hover { color: var(--color-accent-green); }
        .delete-btn-icon {
            background: none; border: none; color: #C0392B; cursor: pointer; font-size: 1.2em; padding: 0 5px; transition: color 0.2s; margin-left: 5px;
        }
        .delete-btn-icon:hover { color: #8C2A1E; }
        .action-group {
            display: flex; align-items: center;
        }
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(3px); display: flex; justify-content: center; align-items: center; }
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
        <div class="profile-name" onclick="openProfileModal('faculty')" style="font-weight:700; margin-bottom:5px;"><?= htmlspecialchars($faculty_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php">Dashboard</a>
    <?php if (strtolower($position) === 'adviser' || $position === 'Adviser'): ?>
        <a href="section.php">Student List</a>
    <?php endif; ?>
    <a href="requirements.php">Clearance Requirements</a>
    <a href="pending.php" class="active">Pending Request</a>
    <a href="history.php">History</a>
</div>

<div class="main-content">
    <div class="page-header">
        <div class="logo-text">CCS Clearance - Pending</div>
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
                                <form method="POST" style="margin:0; display:none;" id="form-mark-<?= $n['id'] ?>">
                                    <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                    <input type="hidden" name="mark_read_keep" value="1">
                                </form>
                                <form method="POST" style="margin:0; display:none;" id="form-delete-<?= $n['id'] ?>">
                                    <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                    <input type="hidden" name="delete_one" value="1">
                                </form>

                                <a href="<?= $n['link'] ?>"
                                   class="notif-text"
                                   onclick="event.preventDefault(); document.getElementById('form-mark-<?= $n['id'] ?>').submit();">
                                    <?= $n['message'] ?>
                                </a>

                                <div class="action-group">
                                    <?php if (!$n['is_read']): ?>
                                        <button type="button"
                                                class="mark-read-btn-icon"
                                                title="Mark as Read"
                                                onclick="document.getElementById('form-mark-<?= $n['id'] ?>').submit();">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
                                    <?php endif; ?>

                                    <button type="button"
                                            class="delete-btn-icon"
                                            title="Delete"
                                            onclick="document.getElementById('form-delete-<?= $n['id'] ?>').submit();">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; margin: 10px 0; font-size: 0.9em; color: #777;">No new notifications.</p>
                    <?php endif; ?>
                </div>
            </div>
            <a href="../index.php" class="log-out-btn">LOG OUT</a>
        </div>
    </div>

    <div class="page-content-wrapper">
        <h1><?= htmlspecialchars($position) ?> Pending Request</h1>
        <hr>

        <?php if ($message):
            $is_error = stripos($message, 'cannot') !== false || stripos($message, 'failed') !== false;
            $alert_bg = $is_error ? '#f8d7da' : '#d4edda';
            $alert_color = $is_error ? '#721c24' : '#155724';
        ?>
            <div class="alert-warning" style="background-color: <?= $alert_bg ?>; color: <?= $alert_color ?>; border-color: #c3e6cb;">
                <span style="font-size:1.2em;"><?= $is_error ? '⚠️' : '✅' ?></span>
                &nbsp;<?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="search-container" style="margin-bottom:20px;">
            <form method="GET" action="pending.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="text" name="search" placeholder="Search Student Name or ID..."
                       value="<?= htmlspecialchars($search_term) ?>"
                       style="padding:8px 10px; border:1px solid #ccc; border-radius:5px; width:250px; font-size:0.95em;">

                <?php if ($position_lower === 'adviser'): ?>
                    <select name="class_group" style="padding:8px 10px; border:1px solid #ccc; border-radius:5px; font-size:0.95em;">
                        <option value="">Filter by Section</option>
                        <?php foreach ($filter_options as $opt):
                            $cg = $opt['class_group'] ?? ( (isset($opt['course']) && isset($opt['year_level']) && isset($opt['section_id'])) ? ($opt['course'] . $opt['year_level'] . $opt['section_id']) : '' );
                            if (!$cg) continue;
                        ?>
                            <option value="<?= htmlspecialchars($cg) ?>" <?= ($cg === $selected_class_group) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cg) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($position_lower !== 'dept head' && $position_lower !== 'department head'): ?>
                    <select name="filter" style="padding:8px 10px; border:1px solid #ccc; border-radius:5px; font-size:0.95em;">
                        <option value="">Filter by Department</option>
                        <?php foreach ($filter_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['department_id']) ?>" <?= (isset($_GET['filter']) && $_GET['filter'] == $opt['department_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt['dept_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <select name="filter" style="padding:8px 10px; border:1px solid #ccc; border-radius:5px; font-size:0.95em;">
                        <option value="">Filter by Department</option>
                        <?php foreach ($filter_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['department_id']) ?>" <?= (isset($_GET['filter']) && $_GET['filter'] == $opt['department_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt['dept_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" class="submit-modal-btn" style="background-color: var(--color-logout-btn); padding:8px 15px; font-size:0.95em;">
                    Apply Filter
                </button>

                <?php if ($search_term || $selected_class_group || (isset($_GET['filter']) && $_GET['filter'])): ?>
                    <a href="pending.php" class="log-out-btn" style="background-color:#aaa; padding:8px 15px; font-size:0.95em; text-decoration:none; color:#fff;">
                        Clear Filter
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <h2>Pending Requests</h2>

        <table>
            <thead>
                <tr>
                    <th style="width:30%;">Student Name (School ID)</th>
                    <th style="width:10%;">Clearance ID</th>
                    <?php if (strtolower($position) !== 'adviser' && $position !== 'Adviser'): ?>
                        <th style="width:10%;">Dept ID</th>
                    <?php endif; ?>
                    <th style="width:10%;">Uploaded File</th>
                    <th style="width:10%;">Student History</th>
                    <th style="width:30%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($pending_requests)): ?>
                    <?php foreach ($pending_requests as $request): ?>
                        <tr>
                            <td style="text-align:left;">
                                <?= htmlspecialchars($request['student_name']) ?> (<?= htmlspecialchars($request['school_id']) ?>)
                            </td>
                            <td><?= htmlspecialchars($request['clearance_id']) ?></td>

                            <?php if (strtolower($position) !== 'adviser' && $position !== 'Adviser'): ?>
                                <td><?= htmlspecialchars($request['department_id']) ?></td>
                            <?php endif; ?>

                            <td>
                                <?php if (!empty($request['uploaded_file'])): ?>
                                    <a href="../uploads/<?= htmlspecialchars($request['uploaded_file']) ?>" target="_blank" class="view-upload-btn" style="background-color:#17a2b8;">View File</a>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>

                            <td>
                                <button onclick="openHistoryModal(<?= (int)$request['clearance_id'] ?>)" class="view-upload-btn">View History</button>
                            </td>

                            <td>
                                <form method="POST" action="" style="display:flex; gap:6px; align-items:center; justify-content:center;">
                                    <input type="hidden" name="signature_id" value="<?= htmlspecialchars($request['signature_id']) ?>">
                                    <input type="hidden" name="clearance_id" value="<?= htmlspecialchars($request['clearance_id']) ?>">
                                    <input type="hidden" name="sign_order" value="<?= htmlspecialchars($request['sign_order']) ?>">

                                    <input type="text" name="remarks" placeholder="Optional Remarks"
                                           style="padding:6px; border:1px solid #ccc; border-radius:4px; flex-grow:1; max-width:180px;">

                                    <button type="submit" name="action" value="Approved" class="submit-modal-btn" style="padding:6px 12px; font-weight:600;">Approve</button>

                                    <button type="submit" name="action" value="Rejected" class="cancel-modal-btn" style="padding:6px 12px; font-weight:600;">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= (strtolower($position) === 'adviser' || $position === 'Adviser') ? 6 : 6 ?>" style="text-align:center;">
                            No pending clearance sign-offs found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </div> 
</div> 


<div id="modal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:600px; text-align:left;">
        <span class="close" onclick="closeHistoryModal()" style="position:absolute; right:15px; top:10px; font-size:1.5em; cursor:pointer;">&times;</span>
        <h3 style="margin-top:5px;">Student Clearance History</h3>
        <div id="modal-body" style="overflow:auto; max-height:60vh;"></div>
    </div>
</div>

<div id="profileModalFaculty" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Faculty Profile</h3>
        <form method="POST" action="pending.php" enctype="multipart/form-data">
            <input type="hidden" name="update_profile" value="1">
            <input type="hidden" name="current_profile_picture" value="<?= htmlspecialchars($profile_data['profile_picture'] ?? 'profile.png') ?>">
            <div class="form-group-profile" style="text-align: center;">
                <label>Profile Picture:</label><br>
                <img id="profile_pic_preview_fac" src="<?= htmlspecialchars($profile_pic_path) ?>" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; border: 2px solid var(--color-sidebar-bg);">
                <input type="file" name="profile_picture" id="profile_picture_fac" accept="image/*" onchange="previewImage(event)">
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div class="form-group-profile" style="flex: 1 1 45%;"><label for="fName_fac">First Name:</label><input type="text" name="fName" id="fName_fac" value="<?= htmlspecialchars($profile_data['fName'] ?? '') ?>"></div>
                <div class="form-group-profile" style="flex: 1 1 45%;"><label for="lName_fac">Last Name:</label><input type="text" name="lName" id="lName_fac" value="<?= htmlspecialchars($profile_data['lName'] ?? '') ?>"></div>
                <div class="form-group-profile" style="flex: 1 1 95%;"><label for="mName_fac">M. Name (Opt):</label><input type="text" name="mName" id="mName_fac" value="<?= htmlspecialchars($profile_data['mName'] ?? '') ?>"></div>
            </div>
            <div class="form-group-profile"><label for="email_fac">Email Address:</label><input type="email" name="email" id="email_fac" value="<?= htmlspecialchars($account_data['email'] ?? '') ?>" required></div>
            <hr>
            <p style="font-size: 0.9em; color: #555;">Leave password fields blank if you do not want to change your password.</p>
            <div class="form-group-profile"><label for="password_fac">New Password:</label><input type="password" name="password" id="password_fac"></div>
            <div class="form-group-profile"><label for="confirm_password_fac">Confirm New Password:</label><input type="password" name="confirm_password" id="confirm_password_fac"></div>
            <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closeProfileModal()" class="cancel-modal-btn">Cancel</button>
                <button type="submit" class="submit-modal-btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleNotificationDropdown() { document.getElementById("notification-dropdown").classList.toggle("show"); }
function openHistoryModal(clearanceId) {
    const modal = document.getElementById('modal');
    const body = document.getElementById('modal-body');
    body.innerHTML = 'Loading...';
    modal.style.display = 'flex';

    fetch(`student_history.php?clearance_id=${encodeURIComponent(clearanceId)}`)
        .then(res => {
             if (!res.ok) throw new Error('Network response was not ok.');
             return res.json();
        })
        .then(data => {
            if (!data || data.length === 0) {
                body.innerHTML = '<p>No clearance requirements found for this student.</p>';
                return;
            }

            let html = '<table width="100%"><thead><tr><th>Signer</th><th>Type/Position</th><th>Status</th><th>Remarks</th><th>File</th></tr></thead><tbody>';

            data.forEach(h => {
                const status = h.signed_status || 'Not Yet Requested';
                const statusClass = 'status-' + status.toLowerCase().replace(/[^a-z0-9]/g, '');

                const signerName = h.signer_name || 'N/A';
                const signerType = h.signer_type === 'Faculty' ? (h.position || 'Faculty') : h.signer_type;

                const fileLink = h.uploaded_file ?
                    `<a href="../uploads/${h.uploaded_file}" target="_blank" class="view-upload-btn" style="background-color:#17a2b8; text-decoration:none; color:#fff; padding: 4px 8px; border-radius: 4px;">View File</a>` :
                    'N/A';

                html += `<tr>
                    <td><strong>${signerName}</strong></td>
                    <td>${signerType}</td>
                    <td class="${statusClass}"><strong>${status}</strong></td>
                    <td>${h.remarks || '-'}</td>
                    <td>${fileLink}</td>
                </tr>`;
            });

            html += '</tbody></table>';
            body.innerHTML = html;
        })
        .catch(err => {
            console.error('History Load Error:', err);
            body.innerHTML = '<p>Error loading history data. Please check network and server logs.</p>';
        });
    }

function closeHistoryModal() {
    document.getElementById('modal').style.display = 'none';
}

function closeProfileModal() { document.getElementById('profileModalFaculty').style.display = 'none'; }
function openProfileModal(role) {
    if (role === 'faculty') {
        document.getElementById('profileModalFaculty').style.display = 'flex';
    }
}
function previewImage(event) {
    const reader = new FileReader();
    reader.onload = function() { document.getElementById('profile_pic_preview_fac').src = reader.result; };
    if (event.target.files[0]) reader.readAsDataURL(event.target.files[0]);
}

window.onclick = function(event) {
    if (event.target === document.getElementById('modal')) {
        closeHistoryModal();
    }
    if (event.target === document.getElementById('profileModalFaculty')) {
        closeProfileModal();
    }
    if (!event.target.matches('.notification-bell-btn, .notification-bell-btn *')) {
        var dropdowns = document.getElementsByClassName("notification-dropdown-content");
        for (var i = 0; i < dropdowns.length; i++) {
            if (dropdowns[i].classList.contains('show')) dropdowns[i].classList.remove('show');
        }
    }
}
</script>

</body>
</html>
