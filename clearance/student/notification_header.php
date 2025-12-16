<?php
// clearance/student/notification_header.php
// Requires: $student_id (int), $clearanceObj (Clearance object) to be defined in the calling scope.
// Optionally: $student_name (string) for sidebar profile details.

// Determine the current page name for redirects (e.g., status.php)
$current_page = basename($_SERVER['PHP_SELF']);

// --- HANDLE DELETE ALL ACTION (Sets is_read = 2) ---
if (isset($_POST['delete_all'])) {
    $clearanceObj->deleteAllNotifications($student_id);
    // Use basename() to redirect back to the current page (e.g., status.php)
    header("Location: " . $current_page);
    exit;
}

// --- HANDLE DELETE ONE ACTION (Sets is_read = 2) ---
if (isset($_POST['delete_one']) && isset($_POST['mark_read_id']) && isset($_POST['mark_read_type'])) {
    $clearanceObj->clearNotification($_POST['mark_read_id'], $_POST['mark_read_type']);
    header("Location: " . $current_page);
    exit;
}

// --- HANDLE MARK ALL ACTION (Sets is_read = 1) ---
if (isset($_POST['mark_all_read'])) {
    $clearanceObj->markAllNotificationsRead($student_id);
    header("Location: " . $current_page);
    exit;
}

// --- HANDLE MARK AS READ ACTION (Sets is_read = 1) ---
if (isset($_POST['mark_read_id']) && isset($_POST['mark_read_type']) && !isset($_POST['delete_one'])) {
    $clearanceObj->markNotificationRead($_POST['mark_read_id'], $_POST['mark_read_type']);
    $redirect_link = $_POST['redirect_link'] ?? $current_page;
    header("Location: " . $redirect_link);
    exit;
}

// --- NOTIFICATION LOGIC: Collect ALL items (PHP Block) ---
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
        if (!$is_read_master) { // Count if is_read = 0
            $notification_count++;
        }
        $is_completed_notified = true;
    }

    // 2. CHECK INDIVIDUAL SIGNER ACTIONS (Only include if NOT deleted/hidden)
    if (in_array($signer_status, ['Approved', 'Rejected', 'Cancelled']) && !$is_deleted_signature) {
        $signer_name = htmlspecialchars($r['signer_name'] ?? $r['signer_type']);
        $alert_class = strtolower($signer_status) === 'approved' ? 'approved' : 'rejected';

        // FIX: Calculate date BEFORE the array declaration
        $date = date('M d', strtotime($r['signed_date'] ?? $r['date_requested']));

        // Set the base link depending on the event type (completion events go to certificate, actions go to status)
        $link_base = "status.php"; // Default action to status page
        if ($master_status === 'Completed' || $master_status === 'Approved') {
            $link_base = "history.php";
        }


        $notifications[] = [
            'id' => $sig_id,
            'type' => 'signature',
            'message' => "<strong>{$signer_name}</strong> {$signer_status} your request on {$date}.",
            'class'   => $alert_class,
            'link'    => "{$link_base}?cid=$c_id",
            'is_read' => $is_read_signature
        ];

        if (!$is_read_signature) $notification_count++;
    }
}
?>

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

<script>
    // These functions must be defined globally on every page that includes the header
    function toggleNotificationDropdown() {
        document.getElementById("notification-dropdown").classList.toggle("show");
    }

    window.onclick = function(event) {
        if (!event.target.closest('.notification-bell-btn') && !event.target.closest('.notification-item')) {
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