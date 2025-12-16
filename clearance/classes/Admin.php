<?php
require_once "Database.php";
require_once "Notification.php";

class Admin extends Database {
    protected $conn;

    public function __construct() {
        $this->conn = $this->connect();
    }

    public function getConnection() {
        return $this->conn;
    }

    private function generatePlaceholderEmail() {
        return 'stub_' . time() . mt_rand(100, 999) . '@clearance.local';
    }

    private function generateOtp() {
        return str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function provisionFaculty($fName, $mName, $lName, $dept_id, $position, $temp_password, $course_assigned = null) {
        if (!$this->conn) return "Database connection error.";

        $placeholder_email = $this->generatePlaceholderEmail();
        $hashedPassword = password_hash($temp_password, PASSWORD_DEFAULT);
        $role = "faculty";

        try {
            $this->conn->beginTransaction();

            $sql = "INSERT INTO account (email, password, role, is_verified, verification_token)
                    VALUES (:email, :password, :role, 0, NULL)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $placeholder_email);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':role', $role);
            $stmt->execute();
            $account_id = $this->conn->lastInsertId();

            $sql_ref = "INSERT INTO faculty (account_id, fName, mName, lName, position, department_id, course_assigned)
                        VALUES (:aid, :fn, :mn, :ln, :pos, :dept, :course_assigned)";
            $stmt_ref = $this->conn->prepare($sql_ref);
            $stmt_ref->bindParam(':aid', $account_id);
            $stmt_ref->bindParam(':fn', $fName);
            $stmt_ref->bindParam(':mn', $mName);
            $stmt_ref->bindParam(':ln', $lName);
            $stmt_ref->bindParam(':pos', $position);
            $stmt_ref->bindParam(':dept', $dept_id);
            $stmt_ref->bindValue(':course_assigned', $course_assigned ?: null, PDO::PARAM_STR);
            $stmt_ref->execute();

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return "Database Error: " . $e->getMessage();
        }
    }

    public function provisionOrganization($org_name, $temp_password, $requirements = null) {
        if (!$this->conn) return "Database connection error.";

        $placeholder_email = $this->generatePlaceholderEmail();
        $hashedPassword = password_hash($temp_password, PASSWORD_DEFAULT);
        $role = "organization";

        try {
            $this->conn->beginTransaction();

            $sql = "INSERT INTO account (email, password, role, is_verified, verification_token)
                    VALUES (:email, :password, :role, 0, NULL)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $placeholder_email);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':role', $role);
            $stmt->execute();
            $account_id = $this->conn->lastInsertId();

            $sql_ref = "INSERT INTO organization (account_id, org_name, requirements)
                        VALUES (:aid, :org, :req)";
            $stmt_ref = $this->conn->prepare($sql_ref);
            $stmt_ref->bindParam(':aid', $account_id);
            $stmt_ref->bindParam(':org', $org_name);
            $stmt_ref->bindValue(':req', $requirements ?: null, PDO::PARAM_STR);
            $stmt_ref->execute();

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return "Database Error: " . $e->getMessage();
        }
    }

    public function softDeleteStudent($student_id) {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("SELECT account_id FROM student WHERE student_id = :sid");
            $stmt->execute([':sid' => $student_id]);
            $account_id = $stmt->fetchColumn();

            if (!$account_id) {
                $this->conn->rollBack();
                return false;
            }

            $stmt_acc = $this->conn->prepare("UPDATE account SET is_verified = 0 WHERE account_id = :aid");
            $stmt_acc->execute([':aid' => $account_id]);

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error soft deleting student: " . $e->getMessage());
            return false;
        }
    }

    public function reactivateStudent($student_id) {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("SELECT account_id FROM student WHERE student_id = :sid");
            $stmt->execute([':sid' => $student_id]);
            $account_id = $stmt->fetchColumn();

            if (!$account_id) {
                $this->conn->rollBack();
                return false;
            }

            $stmt_acc = $this->conn->prepare("UPDATE account SET is_verified = 1 WHERE account_id = :aid");
            $stmt_acc->execute([':aid' => $account_id]);

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error reactivating student: " . $e->getMessage());
            return false;
        }
    }

    public function resetClearance($clearance_id) {
        $this->conn->beginTransaction();
        try {
            $stmt_del = $this->conn->prepare("DELETE FROM clearance_signature WHERE clearance_id = :cid");
            $stmt_del->execute([':cid' => $clearance_id]);

            $stmt_upd = $this->conn->prepare("UPDATE clearance SET status = 'Pending', date_completed = NULL, remarks = NULL WHERE clearance_id = :cid");
            $stmt_upd->execute([':cid' => $clearance_id]);

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error resetting clearance: " . $e->getMessage());
            return false;
        }
    }

    public function getStudentEditDetails($student_id) {
        $sql = "
            SELECT
                s.student_id, s.school_id, s.fName, s.mName, s.lName, s.course, s.year_level, s.section_id, s.department_id, s.adviser_id,
                a.email, a.is_verified
            FROM student s
            JOIN account a ON s.account_id = a.account_id
            WHERE s.student_id = :sid
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateStudent($student_id, $data) {
        $this->conn->beginTransaction();
        try {
            $sql_stu = "UPDATE student SET
                school_id = :sid_new, fName = :fn, mName = :mn, lName = :ln, course = :course,
                year_level = :lvl, section_id = :sec, department_id = :dept, adviser_id = :adv
                WHERE student_id = :sid_old";
            $stmt_stu = $this->conn->prepare($sql_stu);
            $stmt_stu->execute([
                ':sid_new' => $data['school_id'], ':fn' => $data['fName'], ':mn' => $data['mName'], ':ln' => $data['lName'],
                ':course' => $data['course'], ':lvl' => $data['year_level'], ':sec' => $data['section_id'],
                ':dept' => $data['department_id'], ':adv' => $data['adviser_id'], ':sid_old' => $student_id
            ]);

            $stmt_acc = $this->conn->prepare("SELECT account_id FROM student WHERE student_id = :sid");
            $stmt_acc->execute([':sid' => $student_id]);
            $account_id = $stmt_acc->fetchColumn();

            if ($account_id) {
                $sql_acc = "UPDATE account SET email = :email, is_verified = :is_verified WHERE account_id = :aid";
                $stmt_acc_upd = $this->conn->prepare($sql_acc);
                $stmt_acc_upd->execute([':email' => $data['email'], ':is_verified' => $data['is_verified'], ':aid' => $account_id]);
            }

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error updating student: " . $e->getMessage());
            return false;
        }
    }

    public function getOrganizationById($org_id) {
        $sql = "
            SELECT
                o.org_id, o.org_name, o.requirements,
                a.email, a.is_verified AS is_active_proxy
            FROM organization o
            JOIN account a ON o.account_id = a.account_id
            WHERE o.org_id = :oid
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':oid', $org_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateOrganization($org_id, $data) {
        $this->conn->beginTransaction();
        try {
            $sql_org = "UPDATE organization SET org_name = :oname, requirements = :req WHERE org_id = :oid";
            $stmt_org = $this->conn->prepare($sql_org);
            $stmt_org->execute([':oname' => $data['org_name'], ':req' => $data['requirements'], ':oid' => $org_id]);

            $stmt_acc = $this->conn->prepare("SELECT account_id FROM organization WHERE org_id = :oid");
            $stmt_acc->execute([':oid' => $org_id]);
            $account_id = $stmt_acc->fetchColumn();

            if ($account_id) {
                $sql_acc = "UPDATE account SET email = :email WHERE account_id = :aid";
                $stmt_acc_upd = $this->conn->prepare($sql_acc);
                $stmt_acc_upd->execute([':email' => $data['email'], ':aid' => $account_id]);
            }

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error updating organization: " . $e->getMessage());
            return false;
        }
    }

    public function updateOrgStatus($org_id, $is_active) {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("SELECT account_id FROM organization WHERE org_id = :oid");
            $stmt->execute([':oid' => $org_id]);
            $account_id = $stmt->fetchColumn();

            if ($account_id) {
                $sql_acc = "UPDATE account SET is_verified = :active WHERE account_id = :aid";
                $stmt_acc_upd = $this->conn->prepare($sql_acc);
                $stmt_acc_upd->execute([':active' => $is_active ? 1 : 0, ':aid' => $account_id]);
            }

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error updating organization status: " . $e->getMessage());
            return false;
        }
    }

    public function getFacultyEditDetails($faculty_id) {
        $sql = "
            SELECT
                f.faculty_id, f.fName, f.mName, f.lName, f.position, f.department_id, f.course_assigned,
                a.email, a.is_verified
            FROM faculty f
            JOIN account a ON f.account_id = a.account_id
            WHERE f.faculty_id = :fid
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateFaculty($faculty_id, $data) {
        $this->conn->beginTransaction();
        try {
            $sql_fac = "UPDATE faculty SET
                fName = :fn, mName = :mn, lName = :ln, position = :pos,
                department_id = :dept, course_assigned = :course
                WHERE faculty_id = :fid";
            $stmt_fac = $this->conn->prepare($sql_fac);
            $stmt_fac->execute([
                ':fn' => $data['fName'], ':mn' => $data['mName'], ':ln' => $data['lName'],
                ':pos' => $data['position'], ':dept' => $data['department_id'],
                ':course' => $data['course_assigned'], ':fid' => $faculty_id
            ]);

            $stmt_acc = $this->conn->prepare("SELECT account_id FROM faculty WHERE faculty_id = :fid");
            $stmt_acc->execute([':fid' => $faculty_id]);
            $account_id = $stmt_acc->fetchColumn();

            if ($account_id) {
                $sql_acc = "UPDATE account SET email = :email, is_verified = :is_verified WHERE account_id = :aid";
                $stmt_acc_upd = $this->conn->prepare($sql_acc);
                $stmt_acc_upd->execute([':email' => $data['email'], ':is_verified' => $data['is_verified'], ':aid' => $account_id]);
            }

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error updating faculty: " . $e->getMessage());
            return false;
        }
    }

    public function resetAllClearanceData() {
        try {
            $this->conn->beginTransaction();

            $stmt1 = $this->conn->prepare("DELETE FROM clearance_signature");
            $stmt1->execute();

            $stmt2 = $this->conn->prepare("DELETE FROM clearance");
            $stmt2->execute();

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error resetting clearance data: " . $e->getMessage());
            return false;
        }
    }

    public function getSystemSummary() {
        $summary = [
            'total_departments' => 0,
            'total_faculty' => 0,
            'active_faculty' => 0,
            'total_students' => 0,
        ];

        try {
            $summary['total_departments'] = $this->conn->query("SELECT COUNT(*) FROM department")->fetchColumn() ?? 0;
            $summary['total_faculty'] = $this->conn->query("SELECT COUNT(*) FROM faculty")->fetchColumn() ?? 0;
            $summary['total_students'] = $this->conn->query("SELECT COUNT(*) FROM student")->fetchColumn() ?? 0;
            $summary['active_faculty'] = $this->conn->query("
                SELECT COUNT(f.faculty_id) FROM faculty f
                JOIN account a ON f.account_id = a.account_id
                WHERE a.is_verified = 1
            ")->fetchColumn() ?? 0;
        } catch (PDOException $e) {
            error_log("Error fetching system summary: " . $e->getMessage());
        }

        return $summary;
    }

    public function getClearanceKpiSummary() {
        $data = [
            'total_requests' => 0,
            'approved_clearance' => 0,
            'rejected_cancelled' => 0,
            'faculty_in_clearance' => 0,
        ];

        try {
            $data['total_requests'] = $this->conn->query("SELECT COUNT(clearance_id) FROM clearance")->fetchColumn() ?? 0;
            $data['faculty_in_clearance'] = $this->conn->query("SELECT COUNT(DISTINCT signer_ref_id) FROM clearance_signature WHERE signer_type = 'Faculty'")->fetchColumn() ?? 0;

            $approved_cycles_sql = "
                SELECT
                    COUNT(c.clearance_id)
                FROM clearance c
                WHERE c.status = 'Completed'
                AND (SELECT COUNT(*) FROM clearance_signature cs WHERE cs.clearance_id = c.clearance_id AND cs.signed_status = 'Approved')
                = (SELECT COUNT(*) FROM clearance_signature cs2 WHERE cs2.clearance_id = c.clearance_id AND cs2.signed_status NOT IN ('Rejected', 'Cancelled', 'Superseded'))
            ";
            $data['approved_clearance'] = $this->conn->query($approved_cycles_sql)->fetchColumn() ?? 0;

            $data['rejected_cancelled'] = $this->conn->query("SELECT COUNT(clearance_id) FROM clearance WHERE status IN ('Rejected', 'Cancelled')")->fetchColumn() ?? 0;

        } catch (PDOException $e) {
            error_log("Error fetching clearance KPI summary: " . $e->getMessage());
        }
        return $data;
    }

    public function getWeeklyClearanceActivity() {
        $date_generator = "
            SELECT DATE(NOW() - INTERVAL n DAY) AS dt
            FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) AS days
            ORDER BY dt ASC
        ";

        $sql = "
            SELECT
                DATE_FORMAT(d.dt, '%a') AS day,

                -- Count only clearances that have corresponding signature requests (activity check)
                COALESCE(SUM(CASE WHEN c.date_requested IS NOT NULL
                    AND EXISTS (SELECT 1 FROM clearance_signature cs WHERE cs.clearance_id = c.clearance_id LIMIT 1)
                    THEN 1 ELSE 0 END), 0) AS requests,

                COALESCE(SUM(CASE WHEN c.status = 'Completed' THEN 1 ELSE 0 END), 0) AS completed

            FROM ({$date_generator}) d
            LEFT JOIN clearance c ON DATE(c.date_requested) = d.dt
            GROUP BY d.dt
            ORDER BY d.dt ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


   public function getAdminNotifications() {

        if (session_status() === PHP_SESSION_NONE) session_start();
        $state = $_SESSION['admin_notif_state'] ?? [];
        $raw_notifications = [];

        $sql = "SELECT clearance_id, school_year, term, date_requested FROM clearance WHERE status = 'Pending' LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute();

        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);



        if ($cycle) {

            $raw_notifications[] = [

                'id' => 'sys_active_' . $cycle['clearance_id'],

                'type' => 'info',

                'title' => 'System Status: Active',

                'message' => "Cycle <strong>{$cycle['school_year']} - {$cycle['term']}</strong> is currently open.",

                'date' => $cycle['date_requested'],

                'icon' => 'fa-check-circle',

                'color' => 'var(--color-accent-green)'

            ];

            $start_date = strtotime($cycle['date_requested']);

            $days_elapsed = floor((time() - $start_date) / (60 * 60 * 24));

            if ($days_elapsed >= 5) {

                $raw_notifications[] = [

                    'id' => 'sys_warning_' . $cycle['clearance_id'],

                    'type' => 'warning',

                    'title' => 'Time Management Alert',

                    'message' => "Cycle has been active for <strong>{$days_elapsed} days</strong>. Consider closing soon.",

                    'date' => date('Y-m-d H:i:s'),

                    'icon' => 'fa-exclamation-triangle',

                    'color' => 'var(--color-card-pending)'

                ];

            }

        } else {

            $raw_notifications[] = [

                'id' => 'sys_idle',

                'type' => 'neutral',

                'title' => 'System Status: Idle',

                'message' => "No active clearance cycle running.",

                'date' => date('Y-m-d'),

                'icon' => 'fa-pause-circle',

                'color' => '#888'

            ];

        }

        $final_notifications = [];

        foreach ($raw_notifications as $n) {

            $id = $n['id'];

            if (isset($state[$id]['deleted']) && $state[$id]['deleted'] == 1) {
                continue;
            }

            $n['is_read'] = (isset($state[$id]['read']) && $state[$id]['read'] == 1);

            $final_notifications[] = $n;
        }

        return $final_notifications;

    }



    public function markNotificationRead($id) {

        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!isset($_SESSION['admin_notif_state'])) $_SESSION['admin_notif_state'] = [];

        if (!isset($_SESSION['admin_notif_state'][$id])) $_SESSION['admin_notif_state'][$id] = [];

        $_SESSION['admin_notif_state'][$id]['read'] = 1;

        session_write_close();

    }



    public function clearNotification($id) {

        if (session_status() === PHP_SESSION_NONE) session_start();



        if (!isset($_SESSION['admin_notif_state'])) $_SESSION['admin_notif_state'] = [];

        if (!isset($_SESSION['admin_notif_state'][$id])) $_SESSION['admin_notif_state'][$id] = [];



        $_SESSION['admin_notif_state'][$id]['deleted'] = 1;

        session_write_close();

    }



    public function markAllNotificationsRead() {

        $current = $this->getAdminNotifications();

        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!isset($_SESSION['admin_notif_state'])) $_SESSION['admin_notif_state'] = [];



        foreach ($current as $n) {

            $id = $n['id'];

            if (!isset($_SESSION['admin_notif_state'][$id])) $_SESSION['admin_notif_state'][$id] = [];

            $_SESSION['admin_notif_state'][$id]['read'] = 1;

        }

        session_write_close();

    }



    public function deleteAllNotifications() {

        $current = $this->getAdminNotifications();

        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!isset($_SESSION['admin_notif_state'])) $_SESSION['admin_notif_state'] = [];



        foreach ($current as $n) {

            $id = $n['id'];

            if (!isset($_SESSION['admin_notif_state'][$id])) $_SESSION['admin_notif_state'][$id] = [];

            $_SESSION['admin_notif_state'][$id]['deleted'] = 1;

        }

        session_write_close();

    }

    public function addDepartment($dept_name) {
        $sql = "INSERT INTO department (dept_name) VALUES (:name)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':name', $dept_name);
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                return false;
            }
            error_log("Error adding department: " . $e->getMessage());
            return false;
        }
    }

    public function updateDepartment($dept_id, $dept_name) {
        $sql = "UPDATE department SET dept_name = :name WHERE department_id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':name', $dept_name);
        $stmt->bindParam(':id', $dept_id, PDO::PARAM_INT);
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating department: " . $e->getMessage());
            return false;
        }
    }

    public function getAllDepartmentsWithSummary() {
        $sql = "
            SELECT
                d.department_id,
                d.dept_name,
                -- Get Department Head Name
                COALESCE(CONCAT(h.fName, ' ', h.lName), 'N/A') AS head_name,
                -- Get total faculty count for the department
                (SELECT COUNT(*) FROM faculty f WHERE f.department_id = d.department_id) AS total_faculty
            FROM department d
            LEFT JOIN faculty h ON h.department_id = d.department_id AND h.position = 'Department Head'
            ORDER BY d.dept_name";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDepartmentById($dept_id) {
        $sql = "SELECT * FROM department WHERE department_id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $dept_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public function getSpecialFaculty() {
        $sql = "
            SELECT
                f.faculty_id,
                f.fName,
                f.mName,
                f.lName,
                f.position,
                f.department_id,
                a.email,
                a.is_verified AS is_active
            FROM faculty f
            JOIN account a ON f.account_id = a.account_id
            WHERE f.position IN ('Dean', 'SA Coordinator')
            ORDER BY f.position, f.lName
        ";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching special faculty: " . $e->getMessage());
            return [];
        }
    }

    public function getFacultyByDepartment($dept_id) {
        $sql = "
            SELECT
                f.faculty_id,
                f.fName,
                f.mName,
                f.lName,
                f.position,
                a.email,
                a.is_verified AS is_active
            FROM faculty f
            JOIN account a ON f.account_id = a.account_id
            WHERE f.department_id = :dept_id
            ORDER BY f.position, f.lName
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':dept_id', $dept_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deactivateFaculty($faculty_id) {
        $sql = "
            UPDATE account a
            JOIN faculty f ON a.account_id = f.account_id
            SET a.is_verified = 0
            WHERE f.faculty_id = :fid
        ";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':fid' => $faculty_id]);
    }

    public function activateFaculty($faculty_id) {
        $sql = "
            UPDATE account a
            JOIN faculty f ON a.account_id = f.account_id
            SET a.is_verified = 1
            WHERE f.faculty_id = :fid
        ";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':fid' => $faculty_id]);
    }

    public function getStudentManagementRecords($search_term = '', $filter_year = '', $filter_course = '', $filter_status = '') {
            $base_query = "
                SELECT
                    s.student_id,
                    s.school_id,
                    CONCAT_WS(' ', s.fName, s.lName) AS student_name,
                    a.email,
                    s.course,
                    s.year_level,
                    s.section_id,
                    d.dept_name, -- NEW: Department Name

                    -- Get the most recent clearance status details
                    c.clearance_id,
                    COALESCE(c.status, 'Not Started') AS clearance_status,
                    COALESCE(c.date_requested, a.created_at) AS last_updated_date
                FROM student s
                JOIN account a ON s.account_id = a.account_id
                JOIN department d ON s.department_id = d.department_id -- NEW: Join Department
                LEFT JOIN clearance c ON c.student_id = s.student_id

                -- Filter to get only the LATEST clearance record per student
                LEFT JOIN clearance c2 ON c.student_id = c2.student_id AND c.clearance_id < c2.clearance_id
                WHERE c2.clearance_id IS NULL
            ";

            $params = [];
            $required_where = " 1=1 ";

            if (!empty($search_term)) {
                $search_term_like = "%$search_term%";
                $required_where .= " AND (s.school_id LIKE :search_term_id OR a.email LIKE :search_term_email OR CONCAT(s.fName, ' ', s.lName) LIKE :search_term_name)";
                $params[':search_term_id'] = $search_term_like;
                $params[':search_term_email'] = $search_term_like;
                $params[':search_term_name'] = $search_term_like;
            }

            if (!empty($filter_year)) {
                $required_where .= " AND s.year_level = :filter_year";
                $params[':filter_year'] = $filter_year;
            }

            if (!empty($filter_course)) {
                $required_where .= " AND s.course = :filter_course";
                $params[':filter_course'] = $filter_course;
            }

            if (!empty($filter_status)) {
                if ($filter_status === 'Active' || $filter_status === 'Pending') {
                    $required_where .= " AND c.status = 'Pending'";
                } elseif ($filter_status === 'Not Started') {
                    $required_where .= " AND c.clearance_id IS NULL";
                } else {
                    $required_where .= " AND c.status = :filter_status";
                    $params[':filter_status'] = $filter_status;
                }
            }

            $final_query = $base_query . " AND " . $required_where . " ORDER BY s.school_id ASC";

            $stmt = $this->conn->prepare($final_query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        public function deleteDepartment($dept_id) {
        try {
            $this->conn->beginTransaction();

            $sql = "DELETE FROM department WHERE department_id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $dept_id, PDO::PARAM_INT);
            $result = $stmt->execute();

            $this->conn->commit();
            return $result;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error deleting department ID $dept_id: " . $e->getMessage());
            return false;
            }
        }

        public function transferFaculty($faculty_id, $new_dept_id) {
        $sql = "UPDATE faculty SET department_id = :dept_id WHERE faculty_id = :fid";
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([':dept_id' => $new_dept_id, ':fid' => $faculty_id]);
        } catch (PDOException $e) {
            error_log("Error transferring faculty: " . $e->getMessage());
            return false;
        }
    }

}