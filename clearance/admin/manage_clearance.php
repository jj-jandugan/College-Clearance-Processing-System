<?php
session_start();
require_once "../classes/Database.php";
require_once "../classes/Clearance.php";
require_once "../classes/Admin.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$db = new Database();
$conn = $db->connect();
$clearanceObj = new Clearance();
$adminObj = new Admin();
$notifications = $adminObj->getAdminNotifications();

if (isset($_POST['delete_one']) && isset($_POST['mark_read_id'])) {
    $adminObj->clearNotification($_POST['mark_read_id']);
    session_write_close();
    header("Location: dashboard.php");
    exit;
}

if (isset($_POST['mark_read_id']) && !isset($_POST['delete_one'])) {
    $adminObj->markNotificationRead($_POST['mark_read_id']);
    session_write_close();
    header("Location: dashboard.php");
    exit;
}

if (isset($_POST['mark_all_read'])) {
    $adminObj->markAllNotificationsRead();
    session_write_close();
    header("Location: dashboard.php");
    exit;
}

if (isset($_POST['delete_all'])) {
    $adminObj->deleteAllNotifications();
    session_write_close();
    header("Location: dashboard.php");
    exit;
}

$notifications = $adminObj->getAdminNotifications();
$notification_count = 0;
foreach ($notifications as $n) { if (!$n['is_read']) $notification_count++; }

$message = "";
$messageType = "";

$alerted_users = $clearanceObj->checkAndSendCycleEndingAlert();
if ($alerted_users > 0) {
    $message = "⚠️ Cycle Ending Soon Alert was successfully sent to $alerted_users users!";
    $messageType = "error";
}

$latest_cycle_query = "SELECT clearance_id, status, school_year, term FROM clearance ORDER BY clearance_id DESC LIMIT 1";
$latest_cycle = $conn->query($latest_cycle_query)->fetch(PDO::FETCH_ASSOC);

$is_cycle_active = $latest_cycle && $latest_cycle['status'] === 'Pending';
$current_cycle_name = $latest_cycle ? (htmlspecialchars($latest_cycle['school_year'] ?? 'N/A') . ' ' . htmlspecialchars($latest_cycle['term'] ?? 'N/A')) : 'None Active';
$current_cycle_id = $latest_cycle['clearance_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'start_cycle') {
        $school_year = trim($_POST['school_year']);
        $term = trim($_POST['term']);

        if (empty($school_year) || empty($term)) {
            $message = "❌ School Year and Term are required.";
            $messageType = "error";
        } elseif ($is_cycle_active) {
            $message = "❌ A clearance cycle is already active. Stop the current cycle before starting a new one.";
            $messageType = "error";
        } else {
            $expired_count = $clearanceObj->expirePendingClearances();

            $student_ids = $conn->query("SELECT student_id FROM student")->fetchAll(PDO::FETCH_COLUMN);
            $new_clearance_count = 0;
            $updated_count = 0;

            foreach ($student_ids as $student_id) {
                $existing_pending_records = $clearanceObj->getStudentHistory($student_id, 'Pending');

                if (empty($existing_pending_records)) {
                    $clearanceObj->createClearanceRequest($student_id, null, $school_year, $term);
                    $new_clearance_count++;
                }
            }
            $notified_count = $clearanceObj->sendNewCycleAlertToAllUsers($school_year, $term);

            $message = "✅ Clearance cycle $school_year - $term started! Archived $expired_count old cycles, created $new_clearance_count new records, and notified $notified_count total users (Students, Faculty, Orgs).";
            $messageType = "success";
        }
    }

    elseif ($action === 'stop_cycle' && $is_cycle_active && $current_cycle_id) {
        try {
            $archived_count = $clearanceObj->expirePendingClearances();

            $update_master = "UPDATE clearance SET status = 'Cancelled', date_completed = NOW(), remarks = 'Official Cycle Stop by Admin'
                           WHERE clearance_id = :cid";
            $stmt = $conn->prepare($update_master);
            $stmt->execute([':cid' => $current_cycle_id]);

            $message = "✅ Current cycle (ID $current_cycle_id) has been officially stopped. $archived_count student cycles archived/cancelled.";
            $messageType = "success";
        } catch (Exception $e) {
             $message = "❌ Error stopping cycle: " . $e->getMessage();
             $messageType = "error";
        }
    }

    elseif ($action === 'admin_cancel_clearance') {
        $clearance_id_to_cancel = (int)$_POST['clearance_id_to_cancel'];
        try {
            if ($clearanceObj->cancelClearance($clearance_id_to_cancel)) {
                 $message = "✅ Clearance ID $clearance_id_to_cancel successfully CANCELLED/OVERRIDDEN by Admin. Status set to 'Rejected'.";
                 $messageType = "success";
            } else {
                 $message = "❌ Failed to cancel clearance ID $clearance_id_to_cancel. Check if record exists or is already finalized.";
                 $messageType = "error";
            }
        } catch (Exception $e) {
            $message = "❌ Error during cancellation: " . $e->getMessage();
            $messageType = "error";
        }
    }

    header("Location: manage_clearance.php?msg=" . urlencode($message) . "&type=$messageType");
    exit;
}

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_date = isset($_GET['date']) ? trim($_GET['date']) : '';

$base_query = "
    SELECT
        c.clearance_id,
        c.school_year, c.term,
        c.date_requested,
        c.status AS overall_status,
        c.date_completed,
        s.student_id, s.school_id,
        CONCAT(s.fName, ' ', s.lName) AS student_name,
        s.course, s.year_level, s.section_id,

        -- Critical Progress Calculation
        (COALESCE((SELECT COUNT(signature_id) FROM clearance_signature WHERE clearance_id = c.clearance_id AND signed_status = 'Approved'), 0)) AS approved_count,
        (COALESCE((SELECT COUNT(signature_id) FROM clearance_signature WHERE clearance_id = c.clearance_id AND signed_status NOT IN ('Superseded')), 0)) AS total_required_count

    FROM clearance c
    JOIN student s ON c.student_id = s.student_id
    WHERE 1=1 ";

$params = [];
if (!empty($search_term)) {
    $base_query .= " AND (CONCAT(s.fName, ' ', s.lName) LIKE :search_name OR s.school_id LIKE :search_id)";
    $params[':search_name'] = "%$search_term%";
    $params[':search_id'] = "%$search_term%";
}

if (!empty($filter_status)) {
    $base_query .= " AND c.status = :status_filter";
    $params[':status_filter'] = $filter_status;
}

if (!empty($filter_date)) {
    $base_query .= " AND DATE(c.date_requested) = :date_filter";
    $params[':date_filter'] = $filter_date;
}

$base_query .= " ORDER BY c.date_requested DESC";

$stmt = $conn->prepare($base_query);
$stmt->execute($params);
$clearance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['msg']);
    $messageType = htmlspecialchars($_GET['type']);
}

$current_year = (int)date('Y');

if ((int)date('n') >= 1 && (int)date('n') <= 6) {
    $start_academic_year = $current_year - 1;
} else {
    $start_academic_year = $current_year;
}

$school_years_options = [];
$school_years_options[] = "{$start_academic_year}-" . ($start_academic_year + 1);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Clearance</title>
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .progress-count { font-weight: bold; }
        .status-completed, .status-approved { color: var(--color-card-approved); font-weight: 700; }
        .status-pending { color: var(--color-card-pending); font-weight: 700; }
        .status-rejected, .status-cancelled { color: var(--color-card-rejected); font-weight: 700; }
        .status-notstarted { color: #888; font-weight: 700; font-style: italic; }
        .action-link { margin-right: 10px; text-decoration: none; }
        .cycle-info { font-weight: 600; }
        .cycle-controls { display: flex; gap: 15px; align-items: flex-start; }

        #viewClearanceModal { display: none; }
        #adminCancelClearanceModal { display: none; }

        #viewClearanceModal .modal-content { max-width: 800px; }
        #viewClearanceModal table { font-size: 0.85em; }
        #viewClearanceModal table th { text-align: left; background-color: #f0f0f0; }
        #viewClearanceModal table td:nth-child(3), #viewClearanceModal table td:nth-child(4) { white-space: nowrap; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin Panel<i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></h2>
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_clearance.php" class="active">Clearance Control</a>
        <a href="manage_departments.php">Departments</a>
        <a href="manage_student.php"> Students</a>
        <a href="manage_orgs.php">Organizations</a>

    </div>

    <div class="main-content">
        <div class="page-header">
            <h1>Clearance Management</h1>
            <div style="display: flex; align-items: center;">

                <div class="notification-icon-container">
                    <button class="notification-bell-btn" onclick="toggleNotif()">
                        <i class="fas fa-bell"></i>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?= $notification_count ?></span>
                        <?php endif; ?>
                    </button>

                    <div id="notifDropdown" class="notification-dropdown-content">
                        <div class="notification-header">System Monitor (<?= $notification_count ?> New)</div>

                        <?php if (!empty($notifications)): ?>
                            <div class="notification-header-actions">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="mark_all_read" value="1">
                                    <button type="submit" class="header-action-btn text-green">Mark All Read</button>
                                </form>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="delete_all" value="1">
                                    <button type="submit" class="header-action-btn text-red">Clear All</button>
                                </form>
                            </div>

                            <?php foreach($notifications as $n): ?>
                                <div class="notification-item <?= $n['is_read'] ? 'read' : 'unread' ?>">
                                    <div style="display:flex; align-items: flex-start; flex-grow: 1;">
                                        <i class="fas <?= $n['icon'] ?> notif-icon" style="color: <?= $n['color'] ?>; margin-top: 3px;"></i>
                                        <div class="notif-content">
                                            <div><?= $n['title'] ?></div>
                                            <?= $n['message'] ?>
                                            <br><small><?= date('M d, H:i', strtotime($n['date'])) ?></small>
                                        </div>
                                    </div>
                                    <div class="action-group">
                                        <?php if (!$n['is_read']): ?>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                                <button type="submit" class="mark-read-btn-icon" title="Mark as Read"><i class="fas fa-check-circle"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="mark_read_id" value="<?= $n['id'] ?>">
                                            <input type="hidden" name="delete_one" value="1">
                                            <button type="submit" class="delete-btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 15px; text-align: center; color: #777;">No active notifications.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="../index.php" class="log-out-btn">LOG OUT</a>
            </div>
        </div>

        <div class="page-content-wrapper">
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
            <?php endif; ?>

            <div class="container">
                <h3>Cycle Master Control</h3>
                <p class="cycle-info">Current Cycle:
                    <span class="status-<?= strtolower($latest_cycle['status'] ?? 'none') ?>">
                        <?= htmlspecialchars($current_cycle_name) ?> (ID <?= htmlspecialchars($current_cycle_id) ?>)
                    </span>
                </p>

                <?php if ($is_cycle_active): ?>
                    <div class="cycle-controls">
                        <p style="color: var(--color-card-pending);">The system is currently open for submissions under this cycle.</p>
                        <form method="POST" action="manage_clearance.php">
                            <input type="hidden" name="action" value="stop_cycle">
                            <input type="hidden" name="clearance_id" value="<?= $current_cycle_id ?>">
                            <button type="submit" class="btn-primary" style="background-color: var(--color-card-rejected);">STOP CURRENT CYCLE (Archive)</button>
                        </form>
                    </div>

                <?php else: ?>
                    <form method="POST" action="manage_clearance.php">
                        <input type="hidden" name="action" value="start_cycle">
                        <p style="color: var(--color-text-dark); font-weight: normal;">Initiate a new clearance cycle for all students:</p>
                        <div style="display: flex; gap: 20px;">
                            <div class="form-group" style="flex: 2;">
                        <label for="school_year">School Year:</label>
                        <select id="school_year" name="school_year" required>
                            <?php foreach ($school_years_options as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" selected>
                                    <?= htmlspecialchars($sy) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        </div>
                            <div class="form-group" style="flex: 1;">
                                <label for="term">Term:</label>
                                <select id="term" name="term" required>
                                    <option value="">Select Term</option>
                                    <option value="1st">1st Term</option>
                                    <option value="2nd">2nd Term</option>
                                    <option value="Summer">Summer</option>
                                </select>
                            </div>
                            <button type="submit" class="btn-primary" style="height: 38px; align-self: flex-end;">START CLEARANCE</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            <div class="container">
                <h3>Clearance Records</h3>

                <div class="filter-controls" style="margin-bottom: 20px;">
                    <form method="GET" action="manage_clearance.php" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">

                        <div class="form-group" style="flex: 2;">
                            <label for="search">🔎 Search Name/ID:</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="Student Name or ID">
                        </div>

                        <div class="form-group" style="flex: 1;">
                            <label for="date">📅 Date Requested:</label>
                            <input type="date" id="date" name="date" value="<?= htmlspecialchars($filter_date) ?>">
                        </div>

                        <div class="form-group" style="flex: 1;">
                            <label for="status">📊 Status:</label>
                            <select id="status" name="status">
                                <option value="">— ALL —</option>
                                <?php
                                $statuses = ['Pending', 'Completed', 'Rejected', 'Cancelled'];
                                foreach ($statuses as $s):
                                ?>
                                    <option value="<?= $s ?>" <?= $filter_status == $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn-primary" style="height: 38px; align-self: flex-end;">Filter</button>
                        <a href="manage_clearance.php" class="btn-primary" style="height: 38px; align-self: flex-end; background-color: grey;">Reset</a>
                    </form>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Clearance ID</th>
                            <th>Student Name</th>
                            <th>Student ID</th>
                            <th>Course/Section</th>
                            <th>Cycle</th>
                            <th>Date Requested</th>
                            <th>Progress</th>
                            <th>Overall Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clearance_records)): ?>
                            <tr><td colspan="9" style="text-align: center;">No clearance records found matching the filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($clearance_records as $record):
                                $current_progress = $record['total_required_count'] > 0 ?
                                    $record['approved_count'] . '/' . $record['total_required_count'] : '0/0';

                                $overall_status = htmlspecialchars($record['overall_status']);

                                if ($overall_status === 'Pending' && $record['approved_count'] == 0 && $record['total_required_count'] == 0) {
                                    $display_status = 'Not Started';
                                } else {
                                    $display_status = $overall_status;
                                }

                                $status_class = strtolower(str_replace(' ', '', $display_status));
                                $is_finalized = in_array($overall_status, ['Completed', 'Rejected', 'Cancelled', 'CLEARED']);
                                $student_name_for_js = htmlspecialchars($record['student_name'], ENT_QUOTES);
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($record['clearance_id']) ?></td>
                                    <td><?= $student_name_for_js ?></td>
                                    <td><?= htmlspecialchars($record['school_id']) ?></td>
                                    <td><?= htmlspecialchars($record['course']) . ' ' . htmlspecialchars($record['section_id']) ?></td>
                                    <td><?= htmlspecialchars($record['school_year']) . ' ' . htmlspecialchars($record['term']) ?></td>
                                    <td><?= date('Y-m-d', strtotime($record['date_requested'])) ?></td>
                                    <td class="progress-count"><?= $current_progress ?> Signed</td>
                                    <td class="status-<?= $status_class ?>"><?= htmlspecialchars($display_status) ?></td>
                                    <td>
                                        <a href="#"
                                           onclick="openViewModal(<?= $record['clearance_id'] ?>, '<?= $student_name_for_js ?>')"
                                           class="action-link"
                                           title="View Details">
                                            👁
                                        </a>

                                        <?php if (!$is_finalized): ?>
                                            <a href="#"
                                               onclick="openAdminCancelClearanceModal(<?= $record['clearance_id'] ?>, '<?= $student_name_for_js ?>')"
                                               class="action-link"
                                               title="Delete/Override"
                                               style="color: red;">
                                                🗑
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="viewClearanceModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('viewClearanceModal')">&times;</span>
            <h3 style="margin-top: 5px;">Clearance Status: ID <span id="view_clearance_id_display"></span></h3>
            <p>Student: <strong id="view_student_name"></strong></p>
            <div id="view_modal_body" style="overflow-y: auto; max-height: 70vh;">
                <p>Loading signer details...</p>
            </div>
        </div>
    </div>

    <div id="adminCancelClearanceModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeModal('adminCancelClearanceModal')">&times;</span>
            <h3 style="margin-top: 5px; color: var(--color-card-rejected);">Admin Clearance Override</h3>
            <p>You are about to CANCEL/OVERRIDE the clearance for student: <strong id="cancel_student_name"></strong>.</p>
            <p>Clearance ID: <strong id="cancel_clearance_id_display"></strong>.</p>
            <p style="color: var(--color-card-rejected); font-weight: 600;">⚠️ This action sets the final status to 'Rejected' and cancels all pending signatures. It CANNOT be undone.</p>

            <form id="adminCancelClearanceForm" method="POST" action="manage_clearance.php">
                <input type="hidden" name="action" value="admin_cancel_clearance">
                <input type="hidden" name="clearance_id_to_cancel" id="cancel_clearance_id_input">

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('adminCancelClearanceModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary" style="background-color: var(--color-card-rejected);">
                        🗑 Confirm Override/Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

<script>
    function toggleNotif() {
        var d = document.getElementById('notifDropdown');
        d.classList.toggle('show');
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function openAdminCancelClearanceModal(clearanceId, studentName) {
        document.getElementById('cancel_student_name').innerText = studentName;
        document.getElementById('cancel_clearance_id_display').innerText = clearanceId;
        document.getElementById('cancel_clearance_id_input').value = clearanceId;
        document.getElementById('adminCancelClearanceModal').style.display = 'flex';
    }

    function formatDateTime(dateTimeString) {
        if (!dateTimeString || dateTimeString === '0000-00-00 00:00:00') return 'N/A';

        const date = new Date(dateTimeString);

        if (isNaN(date.getTime())) return 'N/A';

        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    async function openViewModal(clearanceId, studentName) {
        const modal = document.getElementById('viewClearanceModal');
        const modalBody = document.getElementById('view_modal_body');
        const studentNameDisplay = document.getElementById('view_student_name');

        document.getElementById('view_clearance_id_display').innerText = clearanceId;
        studentNameDisplay.innerText = studentName;
        modalBody.innerHTML = '<p style="text-align: center;">Fetching real data...</p>';
        modal.style.display = 'flex';

        try {
            const response = await fetch(`get_clearance_detail.php?cid=${clearanceId}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);

            const data = await response.json();
            const signatures = data.signatures || [];
            studentNameDisplay.innerText = data.student_name || studentName;

            let tableHtml = '<table>';
            tableHtml += '<thead><tr><th>Signer</th><th>Type/Position</th><th>Status</th><th>Date Signed/Requested</th><th>Remarks</th><th>File</th></tr></thead>';
            tableHtml += '<tbody>';

            if (signatures.length === 0) {
                tableHtml += '<tr><td colspan="6" style="text-align: center; font-style: italic;">No signature requests found for this clearance ID.</td></tr>';
            } else {
                signatures.forEach(item => {
                    const signerName = item.signer_name || 'N/A';
                    const signerType = item.signer_type === 'Faculty' ? (item.position || 'Faculty') : item.signer_type;
                    const statusClass = 'status-' + item.signed_status.toLowerCase().replace(/[^a-z0-9]/g, '');
                    let displayDate = item.signed_date;
                    if (!displayDate || displayDate === '0000-00-00 00:00:00' || item.signed_status === 'Pending') {
                        displayDate = 'N/A';
                    } else {
                        displayDate = item.signed_date;
                    }


                    const formattedDate = formatDateTime(displayDate);

                    const fileLink = item.uploaded_file ? `<a href="../uploads/${item.uploaded_file}" target="_blank" class="action-link" title="View File">📄</a>` : '—';
                    const remarks = item.remarks || '—';

                    tableHtml += `
                        <tr>
                            <td><strong>${signerName}</strong></td>
                            <td>${signerType}</td>
                            <td class="${statusClass}"><strong>${item.signed_status}</strong></td>
                            <td>${formattedDate}</td>
                            <td>${remarks}</td>
                            <td>${fileLink}</td>
                        </tr>
                    `;
                });
            }

            tableHtml += '</tbody></table>';
            modalBody.innerHTML = tableHtml;

        } catch (error) {
            console.error('View modal error:', error);
            modalBody.innerHTML = `<p style="color: red; text-align: center;">Error loading details. (Error: ${error.message}). Please ensure the file get_clearance_detail.php is correctly uploaded in the admin folder and accessible.</p>`;
        }
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) { event.target.style.display = 'none'; }
        if (!event.target.closest('.notification-bell-btn') && !event.target.closest('.notification-dropdown-content')) {
            var d = document.getElementById('notifDropdown');
            if (d && d.classList.contains('show')) d.classList.remove('show');
        }
    }
</script>
</body>
</html>