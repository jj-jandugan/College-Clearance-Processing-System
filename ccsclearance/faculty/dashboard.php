<?php
session_start();
require_once "../classes/Signer.php";
require_once "../classes/Account.php";
require_once "../classes/Database.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'signer') {
    header("Location: ../index.php");
    exit;
}

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
        $message = "❌ New Password and Confirm New Password do not match.";
        $messageType = "error";
        goto skip_update;
    }

    $new_pic_filename = $current_pic;
    $target_dir = "../assets/img/profiles/";

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
        $new_pic_filename = "faculty_" . $faculty_id . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_pic_filename;
        if (!move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
            $message = "❌ Error uploading new profile picture.";
            $messageType = "error";
            goto skip_update;
        }
        if ($current_pic && file_exists($target_dir . $current_pic) && $current_pic !== 'profile.png') {
            @unlink($target_dir . $current_pic);
        }
    }

    try {
        $conn->beginTransaction();

        $signer_update_data = [
            'fName' => $fName,
            'mName' => $mName,
            'lName' => $lName,
            'profile_picture' => $new_pic_filename
        ];
        $signerObj->updateSignerProfile($signer_id, $signer_update_data, $conn);

        $accountObj->updateAccountDetails($account_data['account_id'], $email, empty($password) ? null : $password, $conn);

        $conn->commit();
        $message = "Profile updated successfully!";
        $messageType = "success";

        header("Location: dashboard.php?msg=" . urlencode($message) . "&type=$messageType");
        exit;

    } catch (Exception $e) {
        $conn->rollBack();
        $message = "❌ Database error during update: " . $e->getMessage();
        $messageType = "error";
    }
    skip_update:
}

if (isset($_POST['mark_read_keep']) && isset($_POST['mark_read_id'])) {
    $signerObj->markNotificationRead($_POST['mark_read_id']);
    header("Location: dashboard.php");
    exit;
}
if (isset($_POST['delete_one']) && isset($_POST['mark_read_id'])) {
    $signerObj->clearNotification($_POST['mark_read_id']);
    header("Location: dashboard.php");
    exit;
}
if (isset($_POST['mark_all_read'])) {
    $signerObj->markAllNotificationsRead($signer_id);
    header("Location: dashboard.php");
    exit;
}
if (isset($_POST['delete_all'])) {
    $signerObj->markAllNotificationsRead($signer_id);
    header("Location: dashboard.php");
    exit;
}

$profile_data = $signerObj->getSignerDetails($signer_id);
$account_data = $accountObj->getAccountDetailsByRefId($signer_id, 'signer');

$fName = empty($profile_data['fName']) ? 'Signer' : $profile_data['fName'];
$mName = $profile_data['mName'] ?? '';
$lName = empty($profile_data['lName']) ? 'Name' : $profile_data['lName'];

$middle_initial = !empty($mName) ? substr($mName, 0, 1) . '. ' : '';
$faculty_name = trim("{$fName} {$middle_initial}{$lName}");

$position = $profile_data['position'] ?? 'N/A';
$pending_count = $signerObj->getPendingSignatureCount($signer_id);

$summary = $signerObj->getDashboardSummary($signer_id);
$recent_requests = $signerObj->getRecentRequests($signer_id, 10);

$raw_notifications = $signerObj->getSignerNotifications($signer_id, 10);
$notifications = [];
$notification_count = 0;

foreach ($raw_notifications as $r) {
    $status = $r['signed_status'];
    $is_read = (bool)($r['is_read'] ?? 0);
    $date = date('M d, Y h:i A', strtotime($r['date_requested']));
    $link = ($status === 'Pending') ? "pending.php" : "history.php";

    if ($status === 'Pending') {
        $msg = "<strong>NEW REQUEST:</strong> Student {$r['student_name']} requested sign-off (ID: {$r['clearance_id']}).";
        $type = 'New Request Submitted';
        $class = 'approved';
    } elseif ($status === 'Cancelled') {
        $msg = "<strong>REQUEST CANCELED:</strong> Student {$r['student_name']} cancelled request (ID: {$r['clearance_id']}).";
        $type = 'Request Canceled by Student';
        $class = 'rejected';
    } else {
        continue;
    }

    $notifications[] = [
        'id' => $r['signature_id'],
        'type' => $type,
        'message' => $msg,
        'class'   => $class,
        'link'    => $link,
        'is_read' => $is_read,
        'date'    => $date
    ];
    if (!$is_read) {
        $notification_count++;
    }
}

if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
    $messageType = htmlspecialchars($_GET['type']);
}

$current_pic_name = !empty($profile_data['profile_picture']) ? $profile_data['profile_picture'] : 'profile.png';

if (strpos($current_pic_name, 'faculty_') === 0) {
    $profile_pic_path = '../assets/img/profiles/' . $current_pic_name;
} else {
    $profile_pic_path = '../assets/img/profile.png';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard</title>
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
        <div class="profile-icon">
            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
        </div>
        <div class="profile-name" onclick="openProfileModal('faculty')" style="font-weight: 700; margin-bottom: 5px; cursor: pointer;"><?= htmlspecialchars($faculty_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php" class="active">Dashboard</a>
    <?php if ($position == 'Adviser'): ?>
        <a href="section.php">Student List</a>
    <?php endif; ?>
    <a href="requirements.php">Clearance Requirements</a>
    <a href="pending.php"> Pending Request</a>
    <a href="history.php">History</a>
</div>

<div class="main-content">

    <div class="page-header">
        <div class="logo-text">Welcome, <?= htmlspecialchars($faculty_name) ?>!</div>
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
                <span style="font-size:0.8em; color: #777; display: block;"><?= htmlspecialchars($n['type']) ?></span>
                <?= $n['message'] ?>
                <span style="font-size:0.7em; color:#999; display:block; margin-top:3px;">Date: <?= htmlspecialchars($n['date']) ?></span>
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

        <?php if (!empty($message)): ?>
            <?php
            $is_error = $messageType === 'error';
            $alert_bg = $is_error ? '#f8d7da' : '#d4edda';
            $alert_color = $is_error ? '#721c24' : '#155724';
            ?>
            <div class="alert-warning" style="background-color: <?= $alert_bg ?>; color: <?= $alert_color ?>; border-color: <?= $is_error ? '#f5c6cb' : '#c3e6cb' ?>; margin-bottom: 20px; font-weight: 600;">
                <span style="font-size: 1.2em;"><?= $is_error ? '⚠️' : '✅' ?></span> <?= $message ?>
            </div>
        <?php endif; ?>

        <h1>Faculty Dashboard</h1>
        <hr>

        <div class="card-container" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">

            <div class="card pending" onclick="window.location.href='pending.php'" style="cursor: pointer;">
                <h3>Pending</h3>
                <p><?= htmlspecialchars($summary['Pending'] ?? 0) ?></p>
            </div>

            <div class="card approved" onclick="window.location.href='history.php'" style="cursor: pointer;">
                <h3>Approved</h3>
                <p><?= htmlspecialchars($summary['Approved'] ?? 0) ?></p>
            </div>

            <div class="card rejected" onclick="window.location.href='history.php'" style="cursor: pointer;">
                <h3>Rejected</h3>
                <p><?= htmlspecialchars($summary['Rejected'] ?? 0) ?></p>
            </div>

            <div class="card cancelled" onclick="window.location.href='history.php'" style="cursor: pointer;">
                <h3>Cancelled</h3>
                <p><?= htmlspecialchars($summary['Cancelled'] ?? 0)?></p>
            </div>
        </div>

        <div class="recent-requests-section">
            <h2>Recent Request</h2>

            <table>
                <thead>
                    <tr>
                        <th>Clearance ID</th>
                        <th>Student Name (ID)</th>
                        <th>Date Signed</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($recent_requests)): ?>
                        <?php foreach ($recent_requests as $request):
                            $status_class = strtolower(htmlspecialchars($request['signed_status']));
                            if ($status_class === 'cancelled') {
                                $status_class = 'rejected';
                            }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($request['clearance_id']) ?></td>
                                <td><?= htmlspecialchars($request['student_name']) ?> (<?= htmlspecialchars($request['school_id']) ?>)</td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($request['signed_date']))) ?></td>
                                <td class="status-<?= $status_class ?>">
                                    <?= htmlspecialchars($request['signed_status']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center;">No recent request found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="profileModalFaculty" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeModal('profileModalFaculty')">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Faculty Profile</h3>
        <form method="POST" action="dashboard.php" enctype="multipart/form-data">
            <input type="hidden" name="update_profile" value="1">
            <input type="hidden" name="current_profile_picture" value="<?= htmlspecialchars($profile_data['profile_picture'] ?? 'profile.png') ?>">

            <div class="form-group-profile" style="text-align: center;">
                <label>Profile Picture:</label><br>
                <img id="profile_pic_preview_fac" src="<?= htmlspecialchars($profile_pic_path) ?>" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; border: 2px solid var(--color-sidebar-bg);">
                <input type="file" name="profile_picture" id="profile_picture_fac" accept="image/*" onchange="previewImage(event, 'profile_pic_preview_fac')">
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
                <button type="button" onclick="closeModal('profileModalFaculty')" class="cancel-modal-btn">Cancel</button>
                <button type="submit" class="submit-modal-btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>


<script>
    function toggleNotificationDropdown() {
        document.getElementById("notification-dropdown").classList.toggle("show");
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    function openProfileModal(role) {
        if (role === 'faculty') {
            const currentFName = '<?= htmlspecialchars(addslashes($profile_data['fName'] ?? '')) ?>';
            const currentMName = '<?= htmlspecialchars(addslashes($profile_data['mName'] ?? '')) ?>';
            const currentLName = '<?= htmlspecialchars(addslashes($profile_data['lName'] ?? '')) ?>';
            const currentEmail = '<?= htmlspecialchars(addslashes($account_data['email'] ?? '')) ?>';

            document.getElementById('fName_fac').value = currentFName;
            document.getElementById('mName_fac').value = currentMName;
            document.getElementById('lName_fac').value = currentLName;
            document.getElementById('email_fac').value = currentEmail;

            document.getElementById('profileModalFaculty').style.display = 'flex';
        }
    }

    function previewImage(event, previewId) {
        const reader = new FileReader();
        reader.onload = function() {
            const output = document.getElementById(previewId);
            output.src = reader.result;
        };
        if (event.target.files[0]) {
            reader.readAsDataURL(event.target.files[0]);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const profileNameElement = document.querySelector('.sidebar-profile .profile-name');
        if (profileNameElement) {
            profileNameElement.onclick = () => openProfileModal('faculty');
        }
    });

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