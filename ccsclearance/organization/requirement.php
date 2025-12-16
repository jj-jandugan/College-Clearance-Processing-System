<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'signer') {
    header("Location: ../index.php");
    exit;
}

require_once "../classes/Signer.php";
require_once "../classes/Database.php";
require_once "../classes/Account.php";

$signer_id = $_SESSION['ref_id'];
$signerObj = new Signer();
$accountObj = new Account();
$db = new Database();
$conn = $db->connect();


$profile_data = $signerObj->getSignerDetails($signer_id);
$account_data = $accountObj->getAccountDetailsByRefId($signer_id, 'signer'); 
$message = "";
$message_type = "";


if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_profile'])) {
    $org_name = trim($_POST['org_name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $current_pic = trim($_POST['current_profile_picture']);

    if (!empty($password) && $password !== $confirm_password) {
        $message = "❌ Password mismatch.";
        $message_type = "error";
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
            $message = "❌ Upload error."; $message_type = "error";
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

        header("Location: requirement.php?msg=" . urlencode("✅ Profile updated!") . "&type=success");
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $message_type = "error";
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

$pending_count = $signerObj->getPendingSignatureCount($signer_id);


if (isset($_POST['mark_read_keep']) && isset($_POST['mark_read_id'])) {
    $signerObj->markNotificationRead($_POST['mark_read_id']);
    header("Location: requirement.php");
    exit;
}
if (isset($_POST['delete_one']) && isset($_POST['mark_read_id'])) {
    $signerObj->clearNotification($_POST['mark_read_id']);
    header("Location: requirement.php");
    exit;
}
if (isset($_POST['mark_all_read'])) {
    $signerObj->markAllNotificationsRead($signer_id);
    header("Location: requirement.php");
    exit;
}
if (isset($_POST['delete_all'])) {
    $signerObj->deleteAllNotifications($signer_id);
    header("Location: requirement.php");
    exit;
}



$requirements = $profile_data['requirements'] ?? "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_requirements'])) {
    $new_requirements = trim($_POST['requirements']);

    
    $sql_req = "UPDATE signer SET requirements = :req WHERE signer_id = :id";
    try {
        $stmt_req = $conn->prepare($sql_req);
        if ($stmt_req->execute([':req' => $new_requirements, ':id' => $signer_id])) {
            $requirements = $new_requirements;
            $message = "Requirements updated successfully!";
            $message_type = "success";
        } else {
            $message = "Failed to update requirements.";
            $message_type = "error";
        }
    } catch (PDOException $e) {
         $message = "Error: " . $e->getMessage();
         $message_type = "error";
    }
}


$raw_notifications = $signerObj->getSignerNotifications($signer_id, 10);
$notifications = [];
$notification_count = 0;

foreach ($raw_notifications as $r) {
    $status = $r['signed_status'];
    $is_read = (bool)($r['is_read'] ?? 0);
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
    $message_type = htmlspecialchars($_GET['type']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Requirements</title>
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
    <a href="requirement.php" class="active">Set Requirements</a>
    <a href="pending.php">Pending Request</a>
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

        <h1>Set Clearance Requirements</h1>
        <hr>

        <?php if ($message): ?>
            <?php
            $is_error = $message_type === 'error';
            $alert_bg = $is_error ? '#f8d7da' : '#d4edda';
            $alert_color = $is_error ? '#721c24' : '#155724';
            ?>
            <div class="alert-warning" style="background-color: <?= $alert_bg ?>; color: <?= $alert_color ?>; border-color: #c3e6cb;">
                <span style="font-size: 1.5em;"><?= $is_error ? '⚠️' : '✅' ?></span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="form-container" style="background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <form action="" method="post">
                <h2 style="margin-top: 0; font-size: 1.4em;">Define Clearance Requirements</h2>

                <label for="requirements" style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--color-text-dark);">
                    Requirements for Student Clearance:
                </label>
                <textarea name="requirements" id="requirements" rows="15"
                          style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 1em; resize: vertical;"
                          required><?= htmlspecialchars($requirements) ?></textarea>

                <p style="font-size: 0.9em; color: #666; margin-top: 10px;">
                    This text will be shown to students before they upload their document for your approval.
                </p>
                <br>

                <button type="submit" name="update_requirements" class="submit-modal-btn" style="width: 100%; max-width: 250px; padding: 12px; font-size: 1em;">
                    Update Requirements
                </button>
            </form>
        </div>

    </div>
</div>
<div id="profileModalOrganization" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Organization Profile</h3>
        <form method="POST" action="requirement.php" enctype="multipart/form-data">
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