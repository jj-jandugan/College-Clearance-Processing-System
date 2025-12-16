<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'signer') {
    header("Location: ../index.php");
    exit;
}

require_once "../classes/Signer.php";
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
    $org_name = trim($_POST['org_name']);
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
        
        $new_pic_filename = "org_" . $signer_id . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_pic_filename;

        if (!move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
            $message = "❌ Upload error."; $messageType = "error";
            goto skip_profile_update;
        }
        if (!empty($current_pic) && $current_pic !== 'profile.png' && file_exists($target_dir . $current_pic)) {
            @unlink($target_dir . $current_pic);
        }
    }

    try {
        $conn->beginTransaction();

        
        $signer_update_data = [
            'name' => $org_name,
            'profile_picture' => $new_pic_filename
        ];

        $signerObj->updateSignerProfile($signer_id, $signer_update_data, $conn);
        $accountObj->updateAccountDetails($account_data['account_id'], $email, empty($password) ? null : $password, $conn);
        $conn->commit();

        header("Location: pending.php?msg=" . urlencode("✅ Profile updated!") . "&type=success");
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $messageType = "error";
    }
    skip_profile_update:
}


$profile_data = $signerObj->getSignerDetails($signer_id);
$account_data = $accountObj->getAccountDetailsByRefId($signer_id, 'signer');

$organization_name = empty($profile_data['name']) ? 'Organization Name' : $profile_data['name'];
$current_pic_name = !empty($profile_data['profile_picture']) ? $profile_data['profile_picture'] : 'profile.png';

if (strpos($current_pic_name, 'org_') === 0) {
    $profile_pic_path = '../assets/img/profiles/' . $current_pic_name;
} else {
    $profile_pic_path = '../assets/img/profile.png';
}


$pending_requests = $signerObj->getPendingRequests($signer_id);
$pending_count = $signerObj->getPendingSignatureCount($signer_id);


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
    $signerObj->deleteAllNotifications($signer_id);
    header("Location: pending.php");
    exit;
}


if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $signature_id = $_POST['signature_id'];
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? NULL;

    if ($signerObj->signClearance($signature_id, $action, $remarks)) {
        $message = "Clearance request for Signature ID #{$signature_id} was " . ucfirst(strtolower($action)) . " successfully!";

        $clearanceObj = new Clearance();

        
        $stmt_cid = $conn->prepare("SELECT clearance_id FROM clearance_signature WHERE signature_id = :sid");
        $stmt_cid->execute([':sid' => $signature_id]);
        $clearance_id = $stmt_cid->fetchColumn();

        
        $signer_id_query = "SELECT signer_id FROM clearance_signature WHERE signature_id = :sid";
        $stmt_se = $conn->prepare($signer_id_query);
        $stmt_se->execute([':sid' => $signature_id]);
        $fetched_signer_id = $stmt_se->fetchColumn();

        $clearanceObj->sendSignerActionNotification($clearance_id, $fetched_signer_id, $action);

        if ($action === "Approved") {
            $clearanceObj->checkForCompletion($clearance_id);
        }
        
        $pending_requests = $signerObj->getPendingRequests($signer_id);

    } else {
        $message = "Failed to process request.";
    }
}


$raw_notifications = $signerObj->getSignerNotifications($signer_id, 10);
$notifications = [];
$notification_count = 0;

foreach ($raw_notifications as $r) {
    $status = $r['signed_status'];
    $is_read = (bool)($r['is_read'] ?? 0);
    $c_id = $r['clearance_id'];
    $date = date('M d', strtotime($r['date_requested']));

    $type = '';
    $msg = '';
    $class = '';
    $link = '';

    if ($status === 'Pending') {
        $msg = "<strong>NEW REQUEST:</strong> Student {$r['student_name']} ({$r['school_id']}) requested sign-off on {$date}.";
        $class = 'approved';
        $link = "pending.php";
        $type = 'new_req';
    } elseif ($status === 'Cancelled') {
        $msg = "<strong>REQUEST CANCELLED:</strong> Student {$r['student_name']} cancelled request (ID: {$r['clearance_id']}).";
        $class = 'rejected';
        $link = "history.php";
        $type = 'cancelled';
    } else {
        continue;
    }

    $notifications[] = [
        'id' => $r['signature_id'],
        'type' => $type,
        'message' => $msg,
        'class'   => $class,
        'link'    => $link,
        'is_read' => $is_read
    ];
    if (!$is_read) {
        $notification_count++;
    }
}

if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
    
    $messageType = ($_GET['type'] == 'error') ? 'error' : 'success';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Sign-offs</title>
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
        <div class="profile-name" onclick="openProfileModal('organization')" style="font-weight:700;margin-bottom:5px;"><?= htmlspecialchars($organization_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php">Dashboard</a>
    <a href="requirement.php">Set Requirements</a>
    <a href="pending.php" class="active">Pending Request</a>
    <a href="history.php">History</a>
</div>

<div class="main-content">

    <div class="page-header">
        <div class="logo-text">CCS Clearance</div>
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
                                                class="mark-read-btn"
                                                title="Mark as Read"
                                                onclick="document.getElementById('form-mark-<?= $n['id'] ?>').submit();">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
                                    <?php endif; ?>

                                    <button type="button"
                                            class="delete-btn"
                                            title="Clear Notification"
                                            onclick="document.getElementById('form-delete-<?= $n['id'] ?>').submit();">
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

        <h1>Pending Clearance Requests</h1>
        <hr>

        <?php if ($message): ?>
            <div style="background-color: <?= $messageType == 'error' ? '#f8d7da' : '#d4edda' ?>; color: <?= $messageType == 'error' ? '#721c24' : '#155724' ?>; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th style="width: 25%;">Student Name (ID)</th>
                    <th style="width: 10%;">Clearance ID</th>
                    <th style="width: 15%;">Date Uploaded</th>
                    <th style="width: 15%;">Uploaded File</th>
                    <th style="width: 35%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pending_requests): ?>
                    <?php foreach ($pending_requests as $request): ?>
                        <tr>
                            <td style="text-align: left;"><?= htmlspecialchars($request['student_name']) ?> (<?= htmlspecialchars($request['school_id']) ?>)</td>
                            <td><?= htmlspecialchars($request['clearance_id']) ?></td>
                            <td><?= htmlspecialchars($request['date_uploaded'] ?? 'N/A') ?></td>
                            <td>
                                <?php if ($request['uploaded_file']): ?>
                                    <a href="../uploads/<?= htmlspecialchars($request['uploaded_file']) ?>" target="_blank"
                                       class="view-upload-btn" style="background-color: #17a2b8;">View File</a>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td>
                                <form action="" method="post" style="display:flex; gap: 5px; align-items: center; justify-content: center;">
                                    <input type="hidden" name="signature_id" value="<?= $request['signature_id'] ?>">

                                    <input type="text" name="remarks" placeholder="Optional Remarks"
                                           style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; flex-grow: 1; max-width: 150px;">

                                    <button type="submit" name="action" value="Approved"
                                            class="submit-modal-btn" style="padding: 6px 12px; font-weight: 500; width: auto; font-size: 0.9em;">
                                        Approve
                                    </button>

                                    <button type="submit" name="action" value="Rejected"
                                            class="cancel-modal-btn" style="padding: 6px 12px; font-weight: 500; width: auto; font-size: 0.9em;">
                                        Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align:center;">No pending clearance request for your organization.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </div>
</div>

<div id="profileModalOrganization" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Organization Profile</h3>
        <form method="POST" action="pending.php" enctype="multipart/form-data">
            <input type="hidden" name="update_profile" value="1">
            <input type="hidden" name="current_profile_picture" value="<?= htmlspecialchars($profile_data['profile_picture'] ?? 'profile.png') ?>">
            <div class="form-group-profile" style="text-align: center;">
                <label>Profile Picture (Logo):</label><br>
                <img id="profile_pic_preview_org" src="<?= htmlspecialchars($profile_pic_path) ?>" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; border: 2px solid var(--color-sidebar-bg);">
                <input type="file" name="profile_picture" id="profile_picture_org" accept="image/*" onchange="previewImage(event)">
            </div>
            <div class="form-group-profile"><label for="org_name">Organization Name:</label><input type="text" name="org_name" id="org_name" value="<?= htmlspecialchars($profile_data['name'] ?? '') ?>"></div>
            <div class="form-group-profile"><label for="email_org">Login Email Address:</label><input type="email" name="email" id="email_org" value="<?= htmlspecialchars($account_data['email'] ?? '') ?>" required></div>
            <hr>
            <p style="font-size: 0.9em; color: #555;">Leave password fields blank if you do not want to change your password.</p>
            <div class="form-group-profile"><label for="password_org">New Password:</label><input type="password" name="password" id="password_org"></div>
            <div class="form-group-profile"><label for="confirm_password_org">Confirm New Password:</label><input type="password" name="confirm_password" id="confirm_password_org"></div>
            <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closeProfileModal()" class="cancel-modal-btn">Cancel</button>
                <button type="submit" class="submit-modal-btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleNotificationDropdown() { document.getElementById("notification-dropdown").classList.toggle("show"); }
    function closeProfileModal() { document.getElementById('profileModalOrganization').style.display = 'none'; }
    function openProfileModal(role) {
        if (role === 'organization') {
            document.getElementById('profileModalOrganization').style.display = 'flex';
        }
    }
    function previewImage(event) {
        const reader = new FileReader();
        reader.onload = function() { document.getElementById('profile_pic_preview_org').src = reader.result; };
        if (event.target.files[0]) reader.readAsDataURL(event.target.files[0]);
    }

    <?php include '../includes/burger_menu_script.php'; ?>

    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) event.target.style.display = 'none';
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