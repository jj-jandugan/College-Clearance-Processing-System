<?php
session_start();
require_once "../classes/Database.php";
require_once "../classes/Admin.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$db = new Database();
$conn = $db->connect();
$adminObj = new Admin();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $org_id = (int)($_POST['org_id'] ?? 0);
    $action = $_POST['admin_action'] ?? '';

    if ($action === 'provision_org') {
        $org_name = trim($_POST['new_org_name']);
        $temp_password = trim($_POST['new_temp_password']);
        $requirements = trim($_POST['new_requirements'] ?? '');

        if (empty($org_name) || empty($temp_password)) {
            $message = "❌ Organization Name and Temporary Password are required for provisioning.";
            $messageType = "error";
        } else {
            $result = $adminObj->provisionOrganization($org_name, $temp_password, $requirements);
            if ($result === true) {
                $message = "✅ Organization $org_name successfully provisioned with Temporary Password " . htmlspecialchars($temp_password) . ". The user must now log in to finalize their account setup.";
                $messageType = "success";
            } else {
                $message = "❌ Provisioning failed: $result";
                $messageType = "error";
            }
        }
    }

    elseif ($action === 'deactivate_org') {
        if ($adminObj->updateOrgStatus($org_id, false)) {
            $message = "✅ Organization ID $org_id successfully deactivated.";
            $messageType = "success";
        } else {
            $message = "❌ Failed to deactivate organization ID $org_id.";
            $messageType = "error";
        }
    } elseif ($action === 'activate_org') {
        if ($adminObj->updateOrgStatus($org_id, true)) {
            $message = "✅ Organization ID $org_id successfully activated.";
            $messageType = "success";
        } else {
            $message = "❌ Failed to activate organization ID $org_id.";
            $messageType = "error";
        }
    } elseif ($action === 'edit_org') {
        $data = [
            'org_name' => trim($_POST['org_name']),
            'email' => trim($_POST['email']),
            'requirements' => trim($_POST['requirements']),
        ];

        if ($adminObj->updateOrganization($org_id, $data)) {
            $message = "✅ Organization ID $org_id successfully updated.";
            $messageType = "success";
        } else {
            $message = "❌ Failed to update organization ID $org_id. Try again after user has set their official email.";
            $messageType = "error";
        }
    }

    header("Location: manage_orgs.php?msg=" . urlencode($message) . "&type=$messageType");
    exit;
}

$search_term = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$org_types = ['Academic', 'Sports', 'Service', 'Cultural'];

$base_query = "
    SELECT
        s.signer_id AS org_id,
        s.name AS org_name,
        s.requirements,
        a.email,
        -- Status derived from account is_verified (1=Active, 0=Inactive)
        (CASE WHEN a.is_verified = 1 THEN 'Active' ELSE 'Inactive' END) AS org_status
    FROM signer s
    JOIN account a ON s.account_id = a.account_id
    WHERE s.signer_type = 'Organization'
";

$params = [];
$required_where = " 1=1 ";

if (!empty($search_term)) {
    $search_term_like = "%$search_term%";
    $required_where .= " AND (s.name LIKE :search_term_name OR s.signer_id = :search_term_id)";
    $params[':search_term_name'] = $search_term_like;
    $params[':search_term_id'] = $search_term;
}

if (!empty($filter_status)) {
    $required_where .= " AND (CASE WHEN a.is_verified = 1 THEN 'Active' ELSE 'Inactive' END) = :filter_status";
    $params[':filter_status'] = $filter_status;
}

$final_query = $base_query . " AND " . $required_where . " ORDER BY s.name ASC";
$stmt = $conn->prepare($final_query);
$stmt->execute($params);
$org_records = $stmt->fetchAll(PDO::FETCH_ASSOC);


if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
    $messageType = htmlspecialchars($_GET['type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Organizations</title>
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https:
    <style>
        .status-Active { color: var(--color-accent-green); font-weight: 700; }
        .status-Inactive { color: var(--color-card-cancelled); font-weight: 700; }
        .action-link { margin-right: 10px; text-decoration: none; color: var(--color-sidebar-bg); }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); justify-content: center; align-items: center; }
        .modal-content { background-color: #fefefe; padding: 20px; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 8px; position: relative; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; }
        .close:hover, .close:focus { color: black; text-decoration: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin Panel<i class="fas fa-pen-to-square" style="font-size: 0.6em; margin-left: 5px; opacity: 0.7;"></i></h2>
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_clearance.php">Clearance Control</a>
        <a href="manage_departments.php">Departments</a>
        <a href="manage_student.php">Students</a>
        <a href="manage_orgs.php" class="active">Organizations</a>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1>Organization Management</h1>
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
                <h3>Organization Records (<?= count($org_records) ?>)</h3>

                <div class="form-group" style="margin-bottom: 20px;">
                    <button class="btn-primary" onclick="openProvisionModal()">+ Add New Organization</button>
                </div>

                <div class="filter-controls" style="margin-bottom: 20px;">
                    <form method="GET" action="manage_orgs.php" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">

                        <div class="form-group" style="flex: 2;">
                            <label for="search">🔎 Search Name/ID:</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="Organization Name or ID">
                        </div>

                        <div class="form-group">
                            <label for="status">Status:</label>
                            <select id="status" name="status">
                                <option value="">— ALL —</option>
                                <option value="Active" <?= $filter_status == 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= $filter_status == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <button type="submit" class="btn-primary" style="height: 38px; align-self: flex-end;">Filter/Search</button>
                        <a href="manage_orgs.php" class="btn-primary" style="height: 38px; align-self: flex-end; background-color: grey;">Reset</a>
                    </form>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Requirements / Category</th> <th>Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($org_records)): ?>
                            <tr><td colspan="6" style="text-align: center;">No organization records found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($org_records as $org):
                            $status_class = strtolower($org['org_status']);
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($org['org_id']) ?></td>
                                <td data-org-name="<?= htmlspecialchars($org['org_name']) ?>"><?= htmlspecialchars($org['org_name']) ?></td>
                                <td data-requirements="<?= htmlspecialchars($org['requirements'] ?? '') ?>"><?= nl2br(htmlspecialchars($org['requirements'] ?? 'N/A')) ?></td>
                                <td data-email="<?= htmlspecialchars($org['email']) ?>"><?= htmlspecialchars($org['email']) ?></td>
                                <td class="status-<?= $status_class ?>"><?= htmlspecialchars($org['org_status']) ?></td>
                                <td>
                                    <a href="#" onclick="fetchOrgForEdit(<?= $org['org_id'] ?>)" class="action-link" title="Edit Organization">✏️</a>

                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to change the status of this organization?');">
                                        <input type="hidden" name="org_id" value="<?= $org['org_id'] ?>">
                                        <input type="hidden" name="admin_action" value="<?= $org['org_status'] == 'Active' ? 'deactivate_org' : 'activate_org' ?>">
                                        <button type="submit" class="action-link" title="<?= $org['org_status'] == 'Active' ? 'Deactivate Organization' : 'Activate Organization' ?>" style="color: <?= $org['org_status'] == 'Active' ? 'red' : 'green' ?>; border: none; background: none; cursor: pointer;">
                                            <?= $org['org_status'] == 'Active' ? '❌' : '✅' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="provisionOrgModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('provisionOrgModal')">&times;</span>
            <h3 style="margin-top: 5px;">Add New Organization</h3>
            <p style="font-size: 0.9em; color: var(--color-text-dark); margin-bottom: 15px;">
                Phase 1 Setup: Enter details & temp password. User sets email on first login.
            </p>
            <form method="POST" action="manage_orgs.php">
                <input type="hidden" name="admin_action" value="provision_org">

                <div class="form-group">
                    <label for="new_org_name">Organization Name:</label>
                    <input type="text" name="new_org_name" id="new_org_name" required>
                </div>

                <div class="form-group">
                    <label for="new_temp_password">Temporary Password:</label>
                    <input type="text" name="new_temp_password" id="new_temp_password" placeholder="e.g. Password123" required>
                </div>

                <div class="form-group">
                    <label for="new_requirements">Default Requirements (Optional):</label>
                    <textarea name="new_requirements" id="new_requirements" rows="3" placeholder="e.g. Cleared of all financial obligations"></textarea>
                </div>

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('provisionOrgModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary">Add Account</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editOrgModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editOrgModal')">&times;</span>
            <h3 style="margin-top: 5px;">Edit Organization: <span id="edit_org_name_display"></span></h3>
            <form id="editOrgForm" method="POST" action="manage_orgs.php">
                <input type="hidden" name="admin_action" value="edit_org">
                <input type="hidden" name="org_id" id="edit_org_id">

                <div class="form-group" style="margin-top: 15px;"><label for="edit_org_name">Organization Name:</label><input type="text" name="org_name" id="edit_org_name" required></div>
                <div class="form-group"><label for="edit_org_email">Email:</label><input type="email" name="email" id="edit_org_email" required></div>

                <div class="form-group">
                    <label for="edit_org_requirements">Requirements / Category (Displayed to Student):</label>
                    <textarea name="requirements" id="edit_org_requirements" rows="6" style="width: 100%;"></textarea>
                </div>

                <div class="modal-actions" style="justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeModal('editOrgModal')" class="btn-primary" style="background-color: grey;">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
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
        document.getElementById(modalId).style.display = 'none';
    }

    function openProvisionModal() {
        document.getElementById('provisionOrgModal').style.display = 'flex';
        document.getElementById('new_org_name').value = '';
        document.getElementById('new_temp_password').value = '';
        document.getElementById('new_requirements').value = '';
    }

    async function fetchOrgForEdit(orgId) {
        const modal = document.getElementById('editOrgModal');
        modal.style.display = 'flex';
        document.getElementById('edit_org_name_display').innerText = 'Loading...';

        try {
            const orgRecords = <?= json_encode($org_records) ?>;
            const org = orgRecords.find(o => parseInt(o.org_id) === parseInt(orgId));

            if (!org) throw new Error('Org not found');

            const data = {
                org_id: org.org_id,
                org_name: org.org_name,
                email: org.email,
                requirements: org.requirements || '',
            };

            document.getElementById('edit_org_id').value = data.org_id;
            document.getElementById('edit_org_name').value = data.org_name;
            document.getElementById('edit_org_email').value = data.email;
            document.getElementById('edit_org_requirements').value = data.requirements;
            document.getElementById('edit_org_name_display').innerText = data.org_name;

        } catch (error) {
            console.error('Error fetching organization details:', error);
            document.getElementById('edit_org_name_display').innerText = 'Error loading data.';
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