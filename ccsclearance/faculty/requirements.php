<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'signer') {
    header("Location: ../index.php");
    exit;
}

require_once "../classes/Signer.php";
require_once "../classes/Database.php";

$signer_id = $_SESSION['ref_id'];
$signerObj = new Signer();
$details = $signerObj->getSignerDetails($signer_id);
$position = $details['position'];
$faculty_name = ($details['fName'] ?? '') . ' ' . ($details['lName'] ?? '');

$requirements = $details['requirements'] ?? "";
$message = "";
$message_type = "";

$db = new Database();
$conn = $db->connect();
$pending_count = $signerObj->getPendingSignatureCount($signer_id);

$current_pic_name = !empty($profile_data['profile_picture']) ? $profile_data['profile_picture'] : 'profile.png';
if (strpos($current_pic_name, 'faculty_') === 0) {
    $profile_pic_path = '../assets/img/profiles/' . $current_pic_name;
} else {
    $profile_pic_path = '../assets/img/profile.png';
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

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_requirements'])) {
    $requirements = trim($_POST['requirements']);

    $sql = "UPDATE signer SET requirements = :req WHERE signer_id = :sid";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':req', $requirements);
    $stmt->bindParam(':sid', $signer_id);
    if ($stmt->execute()) {
        $message = "Requirements updated successfully!";
        $message_type = "success";
        $details = $signerObj->getSignerDetails($signer_id);
        $requirements = $details['requirements'] ?? "";
    } else {
        $message = "Failed to update requirements.";
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
    <title><?= htmlspecialchars($position) ?> Requirements</title>
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
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-profile">
        <div class="profile-icon"><img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile"></div>
        <div class="profile-name" onclick="openProfileModal('faculty')" style="font-weight:700; margin-bottom:5px;"><?= htmlspecialchars($faculty_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php">Dashboard</a>
    <?php if ($position == 'Adviser'): ?>
        <a href="section.php">Student List</a>
    <?php endif; ?>
    <a href="requirements.php" class="active">Clearance Requirements</a>
    <a href="pending.php">
    Pending Request
    <?php if ($pending_count > 0): ?>
        <span class="badge" style="background-color: var(--color-card-rejected); color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.7em;">
            <?= $pending_count ?>
        </span>
    <?php endif; ?>
</a>
    <a href="history.php">History</a>
</div>

<div class="main-content">

    <div class="page-header">
        <div class="logo-text">CCS Clearance - Requirements</div>
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

        <h1><?= htmlspecialchars($position) ?> Clearance Requirements</h1>
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
                <h2 style="margin-top: 0; font-size: 1.4em;">Set Clearance Requirements</h2>

                <label for="requirements" style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--color-text-dark);">
                    Requirements for Student Clearance:
                </label>
                <textarea name="requirements" id="requirements" rows="15"
                          style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 1em; resize: vertical;"
                          required><?= htmlspecialchars($requirements) ?></textarea>

                <p style="font-size: 0.9em; color: #666; margin-top: 10px;">
                    This text will be shown to students before they upload their document for your approval. Use clear instructions (e.g., list items, file type info).
                </p>
                <br>

                <button type="submit" name="update_requirements" class="submit-modal-btn" style="width: 100%; max-width: 250px; padding: 12px; font-size: 1em;">
                    Update Requirements
                </button>
            </form>
        </div>

    </div>
 </div>
 <script>
    function toggleNotificationDropdown() {
        document.getElementById("notification-dropdown").classList.toggle("show");
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
    }
</script>
</body>
</html>