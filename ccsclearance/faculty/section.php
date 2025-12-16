<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'signer') {
    header("Location: ../index.php");
    exit;
}

require_once "../classes/Signer.php"; 
require_once "../classes/Clearance.php";

$faculty_id = $_SESSION['ref_id'];
$facultyObj = new Signer(); 
$details = $facultyObj->getSignerDetails($faculty_id); 
$position = $details['position'];


$fName = $details['fName'] ?? '';
$lName = $details['lName'] ?? '';
$faculty_name = trim("$fName $lName");

$pending_count = $facultyObj->getPendingSignatureCount($faculty_id);

if ($position != 'Adviser') {
    header("Location: dashboard.php");
    exit;
}

$clearanceObj = new Clearance();


$current_pic_name = !empty($details['profile_picture']) ? $details['profile_picture'] : 'profile.png';
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
    $facultyObj->deleteAllNotifications($faculty_id); 
    header("Location: dashboard.php");
    exit;
}


$all_students_for_adviser = $facultyObj->getAssignedStudents($faculty_id, '');

$sections_grouped = [];

foreach ($all_students_for_adviser as $student) {
    $key = $student['course'] . $student['year_level'] . $student['section']; 
    $label = strtoupper($student['course']) . " - " . $student['year_level'] . $student['section'];

    $status_data = $clearanceObj->getClearanceStatusByStudentId($student['student_id']);
    $clearance_id = $status_data[0]['clearance_id'] ?? null;
    $status_text = 'Not Started';

    if ($clearance_id) {
        $finalStatus = 'Completed';
        foreach ($status_data as $s) {
             if ($s['signed_status'] == 'Pending') {
                 $finalStatus = 'Pending';
                 break;
             }
             if ($s['signed_status'] == 'Rejected' || $s['signed_status'] == 'Cancelled') {
                 $finalStatus = 'Issues';
                 break;
             }
        }
        $status_text = $finalStatus;
    }

    if (!isset($sections_grouped[$key])) {
        $sections_grouped[$key] = [
            'label' => $label,
            'count' => 0,
            'students' => []
        ];
    }
    $sections_grouped[$key]['count']++;
    $sections_grouped[$key]['students'][] = $student;
}


$raw_notifications = $facultyObj->getSignerNotifications($faculty_id, 10);
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
<title>Adviser Student Sections</title>
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
        <div class="profile-name" onclick="openProfileModal('faculty')" style="font-weight: 700; margin-bottom: 5px;"><?= htmlspecialchars($faculty_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php">Dashboard</a>
    <a href="section.php" class="active">Student List</a>
    <a href="requirements.php">Clearance Requirements</a>
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
        <div class="logo-text">CCS Clearance - Student Sections</div>
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

        <h1>Advisee Sections</h1>
        <hr>

        <h2>Select a Section to View Students</h2>

        <style>
            .section-cards {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .section-card {
                background: linear-gradient(135deg, var(--color-sidebar-bg) 0%, #2c5f7d 100%);
                border-radius: 12px;
                padding: 30px 20px;
                text-align: center;
                text-decoration: none;
                color: white;
                transition: all 0.3s ease;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                cursor: pointer;
                position: relative;
                overflow: hidden;
            }
            .section-card::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -50%;
                width: 200%;
                height: 200%;
                background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
                transition: all 0.5s ease;
                opacity: 0;
            }
            .section-card:hover::before {
                opacity: 1;
                top: -75%;
                right: -75%;
            }
            .section-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 8px 15px rgba(0,0,0,0.2);
            }
            .section-card h3 {
                margin: 10px 0 15px 0;
                font-size: 1.8em;
                font-weight: 700;
                color: white;
                position: relative;
                z-index: 1;
            }
            .section-card p {
                margin: 0;
                font-size: 1.1em;
                opacity: 0.9;
                position: relative;
                z-index: 1;
            }
            .section-card .section-icon {
                font-size: 2.5em;
                margin-bottom: 10px;
                position: relative;
                z-index: 1;
            }
            .empty-state {
                text-align: center;
                padding: 60px 20px;
                color: #666;
                font-size: 1.1em;
            }
        </style>

        <div class="section-cards">
            <?php if (!empty($sections_grouped)): ?>
                <?php foreach ($sections_grouped as $key => $section): ?>
                    <a href="student_list.php?section=<?= urlencode($key) ?>" class="section-card">
                        <div class="section-icon">📚</div>
                        <h3><?= htmlspecialchars($section['label']) ?></h3>
                        <p><?= $section['count'] ?> Student<?= $section['count'] != 1 ? 's' : '' ?></p>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users" style="font-size: 4em; color: #ccc; margin-bottom: 20px; display: block;"></i>
                    <p>You have no students assigned as an Adviser.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>
<div id="profileModalFaculty" class="modal" style="display:none;">
    <div class="modal-content-profile">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <h3 style="margin-top: 5px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Update Faculty Profile</h3>
        <form method="POST" action="section.php" enctype="multipart/form-data">
            <p>Please update your profile via the Dashboard.</p>
            <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closeProfileModal()" class="cancel-modal-btn">Close</button>
            </div>
        </form>
    </div>
</div>
<script>
    function toggleNotificationDropdown() {
        document.getElementById("notification-dropdown").classList.toggle("show");
    }
    function closeProfileModal() { document.getElementById('profileModalFaculty').style.display = 'none'; }
    function openProfileModal(role) {
        if (role === 'faculty') {
            document.getElementById('profileModalFaculty').style.display = 'flex';
        }
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