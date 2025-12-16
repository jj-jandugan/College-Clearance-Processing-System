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

        header("Location: status.php?msg=" . urlencode($message) . "&type=$messageType");
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
    header("Location: status.php");
    exit;
}

// --- NEW HANDLE DELETE ONE ACTION (Sets is_read = 2) ---
if (isset($_POST['delete_one']) && isset($_POST['mark_read_id']) && isset($_POST['mark_read_type'])) {
    $clearanceObj->clearNotification($_POST['mark_read_id'], $_POST['mark_read_type']);
    header("Location: status.php");
    exit;
}

// --- NEW HANDLE MARK ALL ACTION (Sets is_read = 1) ---
if (isset($_POST['mark_all_read'])) {
    $clearanceObj->markAllNotificationsRead($student_id);
    header("Location: status.php");
    exit;
}

// --- HANDLE MARK AS READ ACTION (Sets is_read = 1) ---
if (isset($_POST['mark_read_id']) && isset($_POST['mark_read_type']) && !isset($_POST['delete_one'])) {
    // Assuming markNotificationRead is added to Clearance.php from previous steps
    $clearanceObj->markNotificationRead($_POST['mark_read_id'], $_POST['mark_read_type']);

    // Check if a specific redirect link was passed (Fix for direct link click)
    $redirect_link = $_POST['redirect_link'] ?? 'status.php';

    // Redirect to the correct page or history.php
    header("Location: " . $redirect_link);
    exit;
}

// --- Clearance Status Fetch ---
// Fetch the current pending clearance (only one active at a time)
$current_pending = $clearanceObj->getStudentHistory($student_id, 'Pending')[0] ?? null;
$clearance_status = [];

if ($current_pending) {
    $clearance_id = $current_pending['clearance_id'];
    // This method needs to fetch all requested signatures for the given clearance ID
    $clearance_status = $clearanceObj->getClearanceStatus($clearance_id);
}


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
        if (!$is_read_master) {
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
            'link'    => "status.php?cid=$c_id",
            'is_read' => $is_read_signature
        ];
        if (!$is_read_signature) {
            $notification_count++;
        }
    }
}
// Removed array_reverse() as SQL handles DESC sorting now
// --- END NOTIFICATION LOGIC ---


// --- POST Handling for Cancellation ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['cancel_signature'])) {
    $signature_id = (int)$_POST['signature_id'];

    // Send notification to signer that the request was cancelled
    $clearanceObj->sendCancellationNotificationToSigner($signature_id);

    if ($signature_id) {
        $clearanceObj->cancelSignature($signature_id);
        header("Location: status.php?msg=" . urlencode("✅ Single request successfully cancelled. You can now re-request the form.") . "&type=success");
        exit;
    }
}

// --- Message Handling ---
$message = "";
$messageType = "";
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['msg']);
    $messageType = htmlspecialchars($_GET['type']);
}

// Helper function to format date/time
function formatDateTime($dateTimeString) {
    if (empty($dateTimeString)) {
        return 'N/A';
    }
    // Format: Nov 08, 2025 10:47 AM
    return date('M d, Y h:i A', strtotime($dateTimeString));
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Clearance Status</title>

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
        <div class="profile-name" onclick="openProfileModal('student')" style="font-weight: 700; margin-bottom: 5px;"><?= htmlspecialchars($student_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php">Dashboard</a>
    <a href="request.php">Request</a>
    <a href="status.php" class="active">Status</a>
    <a href="history.php">History</a>
</div>

<div class="main-content">
    <div class="page-header">
        <div class="logo-text">
            <img src="../assets/img/ccs_logo.png" alt="CCS Logo" style="height:40px;">
            CCS Clearance
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

        <h2>Clearance Status</h2>
        <hr>

        <?php if ($message): ?>
            <p style="color: <?= $messageType == 'success' ? 'var(--color-accent-green)' : 'var(--color-card-rejected)' ?>; font-weight: 600;"><?= $message ?></p>
        <?php endif; ?>

            <table class="status-table">
                <thead>
                    <tr>
                        <th>Signer</th>
                        <th>Date Requested</th>
                        <th>Status</th>
                        <th>Uploaded File</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <?php if ($current_pending): ?>
            <h2>Current Pending Clearance (ID: <?= htmlspecialchars($current_pending['clearance_id']) ?>)</h2>
                <tbody>
                    <?php foreach ($clearance_status as $row): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['signer_name'] ?? $row['signer_type']) ?></strong>
                                <br><small style="color: #666;"><?= htmlspecialchars($row['signer_type'] == 'Faculty' ? ($row['position'] ?? 'Faculty') : 'Organization') ?></small>
                            </td>
                            <td><?= formatDateTime($row['signed_date'] ?? $current_pending['date_requested']) ?></td>
                            <td class="status-<?= htmlspecialchars($row['signed_status']) ?>"><?= htmlspecialchars($row['signed_status']) ?></td>
                            <td>
                                <?php if (!empty($row['uploaded_file'])): ?>
                                    <a href="../uploads/<?= htmlspecialchars($row['uploaded_file']) ?>" target="_blank" class="view-upload-btn">View Upload</a>
                                <?php else: ?>
                                    No file
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['signed_status'] === 'Pending'): ?>
                                    <form method="post" onsubmit="return confirm('⚠️ Are you sure you want to cancel the request for this signer?');" style="display:inline;">
                                        <input type="hidden" name="signature_id" value="<?= (int)$row['signature_id'] ?>">
                                        <button type="submit" name="cancel_signature" class="cancel-btn">Cancel</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>
                <tbody>
                    <tr class="empty-history-row">
                        <td colspan="5">
                            You do not have a currently pending clearance request.
                            <br>
                            Go to <a href="request.php" style="color: var(--color-accent-green); text-decoration: underline;">New Request</a> to start one.
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div id="profileModalStudent" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Student Profile</h3>
        <form method="POST" action="status.php" enctype="multipart/form-data">
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