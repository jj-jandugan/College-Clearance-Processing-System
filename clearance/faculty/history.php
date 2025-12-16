<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty') {
    header("Location: ../index.php");
    exit;
}

require_once "../classes/Faculty.php";
require_once "../classes/Database.php";
require_once "../classes/Account.php";

$faculty_id = $_SESSION['ref_id'];
$facultyObj = new Faculty();
$accountObj = new Account();
$db = new Database();
$conn = $db->connect();

$profile_data = $facultyObj->getFacultyProfileData($faculty_id);
$account_data = $accountObj->getAccountDetailsByRefId($faculty_id, 'faculty');
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
        $faculty_update_data = ['fName' => $fName, 'mName' => $mName, 'lName' => $lName, 'profile_picture' => $new_pic_filename];
        $facultyObj->updateFacultyProfile($faculty_id, $faculty_update_data, $conn);
        $accountObj->updateAccountDetails($account_data['account_id'], $email, empty($password) ? null : $password, $conn);
        $conn->commit();

        $redirect_url = "history.php?msg=" . urlencode("✅ Profile updated!") . "&type=success";
        if (isset($_GET['search'])) $redirect_url .= "&search=" . urlencode($_GET['search']);
        header("Location: " . $redirect_url);
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "❌ Error: " . $e->getMessage(); $messageType = "error";
    }
    skip_profile_update:
}

$profile_data = $facultyObj->getFacultyProfileData($faculty_id);
$account_data = $accountObj->getAccountDetailsByRefId($faculty_id, 'faculty');

$fName = empty($profile_data['fName']) ? 'Faculty' : $profile_data['fName'];
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
    $facultyObj->markNotificationRead($_POST['mark_read_id']);
    header("Location: dashboard.php");
    exit;
}

if (isset($_POST['delete_one']) && isset($_POST['mark_read_id'])) {
    $facultyObj->clearNotification($_POST['mark_read_id']);
    header("Location: dashboard.php");
    exit;
}

if (isset($_POST['mark_all_read'])) {
    $facultyObj->markAllNotificationsRead($faculty_id);
    header("Location: dashboard.php");
    exit;
}
if (isset($_POST['delete_all'])) {
    $facultyObj->markAllNotificationsRead($faculty_id);
    header("Location: dashboard.php");
    exit;
}

$pending_count = $facultyObj->getPendingSignatureCount($faculty_id);
$details = $facultyObj->getFacultyDetails($faculty_id);
if (!$details) {
    $faculty_name = "Faculty";
    $position = "";
} else {
    $faculty_name = $details['fName'] . ' ' . $details['lName'];
    $position = $details['position'];
}

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_value = '';
$filter_options = [];

if (strtolower($position) === 'adviser') {
    $filter_options = $facultyObj->getAssignedClassGroups($faculty_id);
    $filter_value = isset($_GET['section_filter']) ? trim($_GET['section_filter']) : '';
    $filter_name = 'section_filter';
} else {
    $filter_options = $db->getAllDepartments();
    $filter_value = isset($_GET['department_filter']) ? trim($_GET['department_filter']) : '';
    $filter_name = 'department_filter';
}

$signed_history = $facultyObj->getHistoryRequests($faculty_id, $search_term, $filter_value);

if (isset($_GET['msg'])) { $message = htmlspecialchars($_GET['msg']); $messageType = htmlspecialchars($_GET['type']); }

$raw_notifications = $facultyObj->getFacultyNotifications($faculty_id, 10);
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
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($position) ?> History</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
    <?php if (strtolower($position) === 'adviser'): ?>
        <a href="section.php">Student List</a>
    <?php endif; ?>
    <a href="requirements.php">Clearance Requirements</a>
    <a href="pending.php">Pending Request</a>
    <a href="history.php" class="active">History</a>
</div>

<div class="main-content">
    <div class="page-header">
        <div class="logo-text">CCS Clearance - History</div>
        < <div class="header-actions">
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
        <h1>Clearance Sign-off History</h1>
        <hr>

        <div class="search-container" style="margin-bottom:20px;">
            <form method="GET" action="history.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="text" name="search" placeholder="Search Student Name or ID..."
                       value="<?= htmlspecialchars($search_term) ?>"
                       style="padding:8px 10px; border:1px solid #ccc; border-radius:5px; width:250px; font-size:0.95em;">

                <?php if (strtolower($position) === 'adviser'): ?>
                    <select name="section_filter" style="padding:8px 10px; border:1px solid #ccc; border-radius:5px; font-size:0.95em;">
                        <option value="">Filter by Section</option>
                        <?php foreach ($filter_options as $opt):
                            $section = $opt['class_group'];
                            if (!$section) continue;
                        ?>
                            <option value="<?= htmlspecialchars($section) ?>" <?= ($filter_value === $section) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($section) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <select name="department_filter" style="padding:8px 10px; border:1px solid #ccc; border-radius:5px; font-size:0.95em;">
                        <option value="">Filter by Department</option>
                        <?php foreach ($filter_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['department_id']) ?>" <?= ($filter_value == $opt['department_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt['dept_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" class="submit-modal-btn" style="background-color: var(--color-logout-btn); padding:8px 15px; font-size:0.95em;">
                    Apply Filter
                </button>

                <?php if ($search_term || $filter_value): ?>
                    <a href="history.php" class="log-out-btn" style="background-color:#aaa; padding:8px 15px; font-size:0.95em; text-decoration:none; color:#fff;">
                        Clear Filter
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:15%;">Clearance ID</th>
                    <th style="width:30%;">Student Name (ID)</th>
                    <th style="width:15%;">Date Signed</th>
                    <th style="width:15%;">Status</th>
                    <th style="width:25%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($signed_history)): ?>
                    <?php foreach ($signed_history as $request):
                        $status_class = strtolower($request['signed_status']);
                        if ($status_class === 'cancelled') $status_class = 'rejected';
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($request['clearance_id']) ?></td>
                            <td style="text-align:left;"><?= htmlspecialchars($request['student_name']) ?> (<?= htmlspecialchars($request['school_id'] ?? $request['student_id']) ?>)</td>
                            <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($request['signed_date']))) ?></td>
                            <td class="status-<?= $status_class ?>"><?= htmlspecialchars($request['signed_status']) ?></td>
                            <td><?= htmlspecialchars($request['remarks']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align:center;">No history records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </div>
</div>
<div id="profileModalFaculty" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Faculty Profile</h3>
        <form method="POST" action="history.php" enctype="multipart/form-data">
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
