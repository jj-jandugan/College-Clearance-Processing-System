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
$faculty_name = $details['fName'] . ' ' . $details['lName'];

if ($position != 'Adviser') {
    header("Location: dashboard.php");
    exit;
}

$clearanceObj = new Clearance();

$search_term = $_GET['search'] ?? '';
$selected_section = $_GET['section'] ?? null;


if (!$selected_section) {
    header("Location: sections.php");
    exit;
}

if (isset($_POST['mark_read_keep']) && isset($_POST['mark_read_id'])) {
    $facultyObj->markNotificationRead($_POST['mark_read_id']);
    header("Location: student_list.php?section=" . urlencode($selected_section));
    exit;
}

if (isset($_POST['delete_one']) && isset($_POST['mark_read_id'])) {
    $facultyObj->clearNotification($_POST['mark_read_id']);
    header("Location: student_list.php?section=" . urlencode($selected_section));
    exit;
}

if (isset($_POST['mark_all_read'])) {
    $facultyObj->markAllNotificationsRead($faculty_id);
    header("Location: student_list.php?section=" . urlencode($selected_section));
    exit;
}
if (isset($_POST['delete_all'])) {
    $facultyObj->markAllNotificationsRead($faculty_id);
    header("Location: student_list.php?section=" . urlencode($selected_section));
    exit;
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

$all_students_for_adviser = $facultyObj->getAssignedStudents($faculty_id, $search_term);
$students_in_selected_section = [];
$current_section_label = "Student List";
foreach ($all_students_for_adviser as $student) {
    $student_section_key = $student['course'] . $student['year_level'] . $student['section'];

    if ($student_section_key === $selected_section) {
        if ($current_section_label === "Student List") {
            $current_section_label = strtoupper($student['course']) . " - " . $student['year_level'] . $student['section'];
        }

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

        $student['clearance_status'] = $status_text;
        $student['clearance_id'] = $clearance_id;

        $students_in_selected_section[] = $student;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Adviser Student List - <?= htmlspecialchars($current_section_label) ?></title>
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
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-profile">
        <div class="profile-icon">👨‍🏫</div>
        <div class="profile-name" style="font-weight: 700; margin-bottom: 5px;"><?= htmlspecialchars($faculty_name) ?><i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></div>
    </div>
    <a href="dashboard.php">Dashboard</a>
    <a href="section.php" class="active">Student List</a> <a href="requirements.php">Clearance Requirements</a>
    <a href="pending.php" >
    Pending Request
</a>
    <a href="history.php">History</a>
</div>

<div class="main-content">

    <div class="page-header">
        <div class="logo-text">CCS Clearance - <?= htmlspecialchars($current_section_label) ?></div>
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

        <h1>Advisee List: <?= htmlspecialchars($current_section_label) ?></h1>
        <hr>

        <p><a href="section.php" style="font-weight: 600;">&larr; Back to Sections</a></p>

        <div class="search-container" style="margin-bottom: 15px;">
            <form method="GET" action="student_list.php" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="section" value="<?= htmlspecialchars($selected_section) ?>">

                <input type="text" name="search" placeholder="Search Student Name or ID..."
                       value="<?= htmlspecialchars($search_term) ?>"
                       style="padding: 8px 10px; border: 1px solid #ccc; border-radius: 5px; width: 300px; font-size: 0.9em;">

                <button type="submit" class="submit-modal-btn" style="background-color: var(--color-logout-btn); padding: 8px 15px; width: auto; font-size: 0.9em;">
                    Search
                </button>

                <?php if ($search_term): ?>
                    <a href="student_list.php?section=<?= urlencode($selected_section) ?>"
                       class="log-out-btn" style="background-color: #aaa; margin-left: 0; padding: 8px 15px; font-size: 0.9em;">Clear Search</a>
                <?php endif; ?>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 35%;">Student Name (School ID)</th>
                    <th style="width: 20%;">Course/Section</th>
                    <th style="width: 25%;">Clearance Status</th>
                    <th style="width: 20%;">Student History</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($students_in_selected_section)): ?>
                <?php foreach ($students_in_selected_section as $student): ?>
                    <tr>
                        <td style="text-align: left;"><?= htmlspecialchars($student['lName'] . ', ' . $student['fName'] . (!empty($student['mName']) ? ' ' . strtoupper($student['mName'][0]) . '.' : '')) ?> (<?= htmlspecialchars($student['school_id']) ?>)</td>


                        <td><?= htmlspecialchars(strtoupper($student['course'])) ?> / <?= htmlspecialchars($student['year_level']) ?><?= htmlspecialchars($student['section']) ?></td>
                        <td>
                            <span class="status-<?= str_replace(' ', '', $student['clearance_status']) ?>">
                                <?= htmlspecialchars($student['clearance_status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($student['clearance_id']): ?>
                                 <button onclick="openModal(<?= $student['clearance_id'] ?>)" class="view-upload-btn">View History</button>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align:center;">No students found in this section<?= $search_term ? " matching the search term." : "." ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

<div id="modal" class="modal">
    <div class="modal-content" style="max-width: 600px; text-align: left;">
        <span class="close" onclick="closeModal()" style="position: absolute; right: 15px; top: 10px; font-size: 1.5em; cursor: pointer;">&times;</span>
        <h3 style="margin-top: 5px;">Student Clearance History</h3>
        <div id="modal-body" style="overflow-x: auto;">
            </div>
    </div>
</div>

<script>
function openModal(clearanceId) {
    const modal = document.getElementById('modal');
    const body = document.getElementById('modal-body');
    body.innerHTML = 'Loading...';

    fetch(`student_history.php?clearance_id=${clearanceId}`)
        .then(res => res.json())
        .then(data => {
            if (!data || data.length === 0) {
                body.innerHTML = '<p>No signatures yet.</p>';
            } else {
                let html = '<table width="100%"><thead><tr><th>Signer</th><th>Status</th><th>Date</th><th>Remarks</th></tr></thead><tbody>';
                data.forEach(h => {
                    const signer = h.signer_name || (h.signer_type === 'Organization' ? 'Organization' : 'Faculty');

                    let statusClass = 'status-pending';
                    if (h.signed_status === 'Approved') statusClass = 'status-approved';
                    else if (h.signed_status === 'Rejected' || h.signed_status === 'Cancelled') statusClass = 'status-rejected';

                    html += `<tr>
                        <td style="text-align: left;">${signer}</td>
                        <td class="${statusClass}">${h.signed_status}</td>
                        <td>${h.signed_date ? h.signed_date.substring(0, 10) : 'N/A'}</td>
                        <td style="text-align: left;">${h.remarks || '-'}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                body.innerHTML = html;
            }
            modal.style.display = 'block';
        })
        .catch(error => {
            console.error('Error fetching data:', error);
            body.innerHTML = '<p>Error fetching data. Check server logs (student_history.php) and database connection.</p>';
            modal.style.display = 'block';
        });
}

function closeModal() {
    document.getElementById('modal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('modal')) closeModal();
}
</script>

    </div> </div>
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