<?php
require_once "Database.php";
require_once "Notification.php";

class Clearance extends Database {
    protected $conn;

    public function __construct() {
        $this->conn = $this->connect();
    }

    public function getConnection() {
        return $this->conn;
    }

    private function getStudentEmail($student_id) {
        if (!$this->conn) return null;
        $sql = "SELECT a.email FROM account a JOIN student s ON a.account_id = s.account_id WHERE s.student_id = :sid";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    private function getClearanceStudentId($clearance_id) {
        $sql = "SELECT student_id FROM clearance WHERE clearance_id = :cid";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':cid', $clearance_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

   private function getAllAccountEmails() {
        if (!$this->conn) return [];
        $sql = "SELECT email FROM account";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function sendNewCycleAlertToAllUsers($school_year, $term) {
        $all_emails = $this->getAllAccountEmails();
        $count = 0;
        foreach ($all_emails as $email) {
            Notification::sendNewCycleAlert($email, $school_year, $term);
            $count++;
        }
        return $count;
    }

    public function checkAndSendCycleEndingAlert() {
        if (!$this->conn) return 0;

        $active_cycle_query = "
            SELECT clearance_id, date_requested, school_year, term, remarks
            FROM clearance
            WHERE status = 'Pending'
            ORDER BY clearance_id DESC
            LIMIT 1";

        $stmt = $this->conn->prepare($active_cycle_query);
        $stmt->execute();
        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cycle) {
            return 0;
        }

        $clearance_id = $cycle['clearance_id'];
        $start_date = strtotime($cycle['date_requested']);
        $today = time();
        $hours_elapsed = floor(($today - $start_date) / (60 * 60));
        $alert_flag = 'CYCLE_ENDING_ALERT_SENT';

        if (strpos($cycle['remarks'] ?? '', $alert_flag) !== false) {
            return 0;
        }

        if ($hours_elapsed < 120) {
            return 0;
        }

        $all_emails = $this->getAllAccountEmails();
        $count = 0;
        $days_remaining = 2;

        foreach ($all_emails as $email) {
            Notification::sendCycleEndingSoonAlert(
                $email,
                $cycle['school_year'],
                $cycle['term'],
                $days_remaining
            );
            $count++;
        }

        if ($count > 0) {
            $new_remarks = trim(($cycle['remarks'] ?? '') . "\n" . $alert_flag);
            $update_sql = "UPDATE clearance SET remarks = :remarks_flag WHERE clearance_id = :cid";
            $update_stmt = $this->conn->prepare($update_sql);
            $update_stmt->execute([':remarks_flag' => $new_remarks, ':cid' => $clearance_id]);
        }

        return $count;
    }

    public function sendSignerActionNotification($clearance_id, $signer_id, $signed_status) {
        $student_id = $this->getClearanceStudentId($clearance_id);
        $student_email = $this->getStudentEmail($student_id);

        $signer_name_query = "SELECT name FROM signer WHERE signer_id = :sid";
        $stmt = $this->conn->prepare($signer_name_query);
        $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
        $stmt->execute();
        $signer_name = $stmt->fetchColumn();

        if ($student_email && $signer_name) {
            Notification::sendSignerActionAlert($student_email, $clearance_id, $signer_name, $signed_status);
            return true;
        }
        return false;
    }


    public function markNotificationRead($item_id, $type) {
        if ($type === 'completion') {
            $sql = "UPDATE clearance SET is_read = 1 WHERE clearance_id = :id AND is_read = 0 AND status = 'Completed'";
        } else {
            $sql = "UPDATE clearance_signature SET is_read = 1 WHERE signature_id = :id AND is_read = 0";
        }
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $item_id]);
    }

    public function clearNotification($item_id, $type) {
        if ($type === 'completion') {
            $sql = "UPDATE clearance SET is_read = 2 WHERE clearance_id = :id AND is_read < 2";
        } else {
            $sql = "UPDATE clearance_signature SET is_read = 2 WHERE signature_id = :id AND is_read < 2";
        }
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $item_id]);
    }

    public function markAllNotificationsRead($student_id) {
        if (!$this->conn) return false;

        try {
            $this->conn->beginTransaction();

            $sql_master = "UPDATE clearance c
                           SET c.is_read = 1
                           WHERE c.student_id = :sid AND c.is_read = 0 AND c.status = 'Completed'";
            $stmt_master = $this->conn->prepare($sql_master);
            $stmt_master->bindParam(':sid', $student_id, PDO::PARAM_INT);
            $stmt_master->execute();

            $sql_sig = "UPDATE clearance_signature cs
                        JOIN clearance c ON cs.clearance_id = c.clearance_id
                        SET cs.is_read = 1
                        WHERE c.student_id = :sid AND cs.is_read = 0 AND cs.signed_status IN ('Approved', 'Rejected', 'Cancelled', 'Superseded')";
            $stmt_sig = $this->conn->prepare($sql_sig);
            $stmt_sig->bindParam(':sid', $student_id, PDO::PARAM_INT);
            $stmt_sig->execute();

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error marking all notifications as read: " . $e->getMessage());
            return false;
        }
    }

    public function deleteAllNotifications($student_id) {
        if (!$this->conn) return false;

        try {
            $this->conn->beginTransaction();

            $sql_master = "UPDATE clearance c
                            SET c.is_read = 2
                            WHERE c.student_id = :sid AND c.is_read < 2 AND c.status = 'Completed'";
            $stmt_master = $this->conn->prepare($sql_master);
            $stmt_master->bindParam(':sid', $student_id, PDO::PARAM_INT);
            $stmt_master->execute();

            $sql_sig = "UPDATE clearance_signature cs
                        JOIN clearance c ON cs.clearance_id = c.clearance_id
                        SET cs.is_read = 2
                        WHERE c.student_id = :sid AND cs.is_read < 2 AND cs.signed_status IN ('Approved', 'Rejected', 'Cancelled', 'Superseded')";
            $stmt_sig = $this->conn->prepare($sql_sig);
            $stmt_sig->bindParam(':sid', $student_id, PDO::PARAM_INT);
            $stmt_sig->execute();

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error deleting all notifications: " . $e->getMessage());
            return false;
        }
    }

    public function checkForCompletion($clearance_id) {
        $final_status = $this->getClearanceFinalStatus($clearance_id);

        if ($final_status === 'CLEARED') {
            $sql = "UPDATE clearance
        SET status = 'Completed', date_completed = NOW()
        WHERE clearance_id = :cid AND status = 'Pending'";
            $stmt = $this->conn->prepare($sql);
            $success = $stmt->execute([':cid' => $clearance_id]);

            if ($success) {
                $student_id_sql = "SELECT student_id FROM clearance WHERE clearance_id = :cid";
                $student_stmt = $this->conn->prepare($student_id_sql);
                $student_stmt->execute([':cid' => $clearance_id]);
                $student_id = $student_stmt->fetchColumn();

                $student_email = $this->getStudentEmail($student_id);

                if ($student_email) {
                    Notification::sendClearanceCompletedAlert($student_email, $clearance_id);
                }
            }
            return $success;
        }
        return false;
    }

    public function getClearanceFinalStatus($clearance_id) {
        
        $rejected_sql = "SELECT COUNT(*) FROM clearance_signature
                        WHERE clearance_id = :cid AND signed_status IN ('Rejected', 'Cancelled', 'Superseded')";
        $rej_stmt = $this->conn->prepare($rejected_sql);
        $rej_stmt->bindParam(':cid', $clearance_id);
        $rej_stmt->execute();
        if ($rej_stmt->fetchColumn() > 0) {
            return 'REJECTED';
        }

        
        $all_required_signers = $this->getRequiredSignersFullList($clearance_id);
        $total_required_count = count($all_required_signers);

        
        $approved_sql = "SELECT COUNT(*) FROM clearance_signature
                        WHERE clearance_id = :cid
                        AND signed_status = 'Approved'";
        $approved_stmt = $this->conn->prepare($approved_sql);
        $approved_stmt->bindParam(':cid', $clearance_id);
        $approved_stmt->execute();
        $approved_count = $approved_stmt->fetchColumn();

        
        $status_sql = "SELECT status FROM clearance WHERE clearance_id = :cid";
        $status_stmt = $this->conn->prepare($status_sql);
        $status_stmt->bindParam(':cid', $clearance_id);
        $status_stmt->execute();
        $master_status = $status_stmt->fetchColumn() ?? 'Pending';

        
        if ($total_required_count > 0 && $total_required_count == $approved_count) {
            return 'CLEARED';
        }

        return $master_status;
    }

    public function getStudentDashboardSummary($student_id) {
        $summary = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];

        $sql = "
            SELECT cs.signed_status AS status, COUNT(*) AS total
            FROM clearance_signature cs
            JOIN clearance c ON cs.clearance_id = c.clearance_id
            WHERE c.student_id = :student_id
            AND c.date_requested <= NOW()
            GROUP BY cs.signed_status
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':student_id' => $student_id]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $summary[$row['status']] = $row['total'];
        }

        return $summary;
    }

    public function getRecentClearanceRequests($student_id, $limit = 10) {
        $sql = "SELECT
            c.clearance_id,
            c.date_requested,
            c.status,
            cs.signature_id,
            s.signer_type,
            cs.is_read AS signature_is_read,
            s.name AS signer_name,
            cs.signed_status AS signer_status,
            cs.signed_date
            FROM clearance c
            LEFT JOIN clearance_signature cs ON c.clearance_id = cs.clearance_id
            LEFT JOIN signer s ON cs.signer_id = s.signer_id
            WHERE c.student_id = :student_id
            AND c.date_requested <= NOW()
            -- Filter out manually deleted notifications (is_read = 2)
            AND (cs.is_read < 2 OR cs.signed_status NOT IN ('Approved', 'Rejected', 'Cancelled'))

            ORDER BY c.clearance_id DESC, cs.signature_id DESC
            LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':student_id', $student_id);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStudentHistory($student_id, $status = null) {
        $sql = "SELECT
            c.clearance_id,
            c.date_requested,
            c.date_completed,
            c.status,
            c.school_year,
            c.term,
            COALESCE(MAX(cs.remarks), c.remarks) AS remarks
            FROM clearance c
            LEFT JOIN clearance_signature cs ON c.clearance_id = cs.clearance_id
            WHERE c.student_id = :student_id
            AND c.date_requested <= NOW() ";

        if ($status) {
            $sql .= " AND c.status = :status";
        }

        $sql .= " GROUP BY c.clearance_id, c.date_requested, c.date_completed, c.status, c.remarks, c.school_year, c.term ORDER BY c.date_requested DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':student_id', $student_id);
        if ($status) $stmt->bindParam(':status', $status);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getClearanceStatus($clearance_id) {
        $query = "
            SELECT
                cs.signature_id,
                cs.clearance_id,
                s.signer_type,
                s.signer_id,
                s.name AS signer_name,
                s.position,
                cs.signed_status,
                cs.signed_date,
                cs.uploaded_file,
                cs.remarks,
                cs.sign_order
            FROM clearance_signature cs
            JOIN clearance c ON cs.clearance_id = c.clearance_id
            JOIN signer s ON cs.signer_id = s.signer_id
            WHERE cs.clearance_id = :clearance_id
            AND c.date_requested <= NOW()
            AND cs.signed_status IN ('Pending')
            ORDER BY cs.sign_order ASC, cs.signature_id ASC
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':clearance_id' => $clearance_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancelClearance($clearance_id) {
        try {
            $this->conn->beginTransaction();
            $stmt1 = $this->conn->prepare("
                UPDATE clearance
                SET status = 'Cancelled', date_completed = NOW(), remarks = 'Rejected by Admin/System'
                WHERE clearance_id = :clearance_id
            ");
            $stmt1->execute([':clearance_id' => $clearance_id]);

            $stmt2 = $this->conn->prepare("
                UPDATE clearance_signature
                SET signed_status = 'Rejected'
                WHERE clearance_id = :clearance_id AND signed_status = 'Pending'
            ");
            $stmt2->execute([':clearance_id' => $clearance_id]);

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    public function getSignaturesByClearance($clearance_id) {
        $sql = "SELECT cs.*, s.signer_type, s.signer_id
                FROM clearance_signature cs
                JOIN clearance c ON cs.clearance_id = c.clearance_id
                LEFT JOIN signer s ON cs.signer_id = s.signer_id
                WHERE cs.clearance_id = :clearance_id
                AND c.date_requested <= NOW()
                ORDER BY cs.signature_id DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':clearance_id', $clearance_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getSignerEmail($signer_id) {
      if (!$this-> conn) return null;

      $sql = "
          SELECT a.email
          FROM account a
          JOIN signer s ON a.account_id = s.account_id
          WHERE s.signer_id = :sid
          LIMIT 1";

      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
      $stmt->execute();
      return $stmt->fetchColumn();
    }

    public function sendCancellationNotificationToSigner($signature_id) {
        $query = "
            SELECT
                cs.clearance_id,
                cs.signer_id,
                s.signer_type,
                (SELECT CONCAT_WS(' ', st.fName, st.lName) FROM student st JOIN clearance c ON st.student_id = c.student_id WHERE c.clearance_id = cs.clearance_id) as student_name,
                (SELECT a.email FROM account a JOIN signer sg ON a.account_id = sg.account_id WHERE sg.signer_id = cs.signer_id) AS signer_email
            FROM clearance_signature cs
            JOIN signer s ON cs.signer_id = s.signer_id
            WHERE cs.signature_id = :sid
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':sid', $signature_id, PDO::PARAM_INT);
        $stmt->execute();
        $details = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($details && $details['signer_email']) {
            Notification::sendSignerTaskAlert(
                $details['signer_email'],
                $details['clearance_id'],
                $details['student_name'],
                'request canceled',
                []
            );
            return true;
        }
        return false;
    }

    public function sendPendingReminders() {
        $sql = "
            SELECT
                cs.signature_id,
                cs.clearance_id,
                s.signer_type,
                (SELECT CONCAT_WS(' ', st.fName, st.lName) FROM student st JOIN clearance c ON st.student_id = c.student_id WHERE c.clearance_id = cs.clearance_id) as student_name,
                (SELECT a.email FROM account a JOIN signer sg ON a.account_id = sg.account_id WHERE sg.signer_id = cs.signer_id) AS signer_email
            FROM clearance_signature cs
            JOIN signer s ON cs.signer_id = s.signer_id
            WHERE cs.signed_status = 'Pending'
            AND cs.signed_date < DATE_SUB(NOW(), INTERVAL 4 DAY)
            AND cs.is_read < 2
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;

        foreach ($reminders as $r) {
            if ($r['signer_email']) {
                Notification::sendSignerTaskAlert(
                    $r['signer_email'],
                    $r['clearance_id'],
                    $r['student_name'],
                    'pending reminder',
                    []
                );
                $count++;
            }
        }
        return $count;
    }

    public function submitSignatureUpload($clearance_id, $signer_id, $uploaded_file, $sign_order = 1) {

        $checkSql = "SELECT signature_id, signed_status FROM clearance_signature
                  WHERE clearance_id = :clearance_id
                  AND signer_id = :sid
                  LIMIT 1";
        $checkStmt = $this->conn->prepare($checkSql);
        $checkStmt->execute([
            ':clearance_id' => $clearance_id,
            ':sid' => $signer_id
        ]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (in_array($existing['signed_status'], ['Pending', 'Approved'])) {
                return false;
            }
            $historyCheckSql = "SELECT signed_date, remarks FROM clearance_signature WHERE signature_id = :sig_id";
            $historyStmt = $this->conn->prepare($historyCheckSql);
            $historyStmt->execute([':sig_id' => $existing['signature_id']]);
            $oldData = $historyStmt->fetch(PDO::FETCH_ASSOC);

            $historyNote = "RESUBMISSION - Previous: {$existing['signed_status']}";
            if ($oldData && $oldData['signed_date']) {
                $historyNote .= " on " . date('Y-m-d H:i', strtotime($oldData['signed_date']));
            }
            if ($oldData && !empty($oldData['remarks'])) {
                $historyNote .= " (Reason: " . $oldData['remarks'] . ")";
            }
            $historyNote .= " | Resubmitted on " . date('Y-m-d H:i');

            $updateSql = "UPDATE clearance_signature
                         SET signed_status = 'Pending',
                             uploaded_file = :uploaded_file,
                             sign_order = :sign_order,
                             signed_date = NULL,
                             remarks = :history_note
                         WHERE signature_id = :sig_id";
            $updateStmt = $this->conn->prepare($updateSql);
            $uploaded_file_bind = empty($uploaded_file) ? null : $uploaded_file;

            $success = $updateStmt->execute([
                ':uploaded_file' => $uploaded_file_bind,
                ':sign_order' => $sign_order,
                ':history_note' => $historyNote,
                ':sig_id' => $existing['signature_id']
            ]);
        } else {
            $sql = "INSERT INTO clearance_signature
                  (clearance_id, signer_id, sign_order, signed_status, uploaded_file)
                  VALUES (:clearance_id, :sid, :sign_order, 'Pending', :uploaded_file)";

            $stmt = $this->conn->prepare($sql);
            $uploaded_file_bind = empty($uploaded_file) ? null : $uploaded_file;

            $success = $stmt->execute([
                ':clearance_id' => $clearance_id,
                ':sid' => $signer_id,
                ':sign_order' => $sign_order,
                ':uploaded_file' => $uploaded_file_bind
            ]);
        }

        $signer_email = $this->getSignerEmail($signer_id);

        if ($success && $signer_email) {
            $signer_type_sql = "SELECT signer_type FROM signer WHERE signer_id = :sid";
            $stmt_type = $this->conn->prepare($signer_type_sql);
            $stmt_type->execute([':sid' => $signer_id]);
            $signer_type = $stmt_type->fetchColumn();

            Notification::sendNewRequestAlert(
                $signer_email,
                $clearance_id,
                $signer_type,
                $sign_order
            );
            return true;
        }

        return $success;
    }

    public function cancelSignature($signature_id) {
        $sql = "UPDATE clearance_signature
                SET signed_status = 'Cancelled', signed_date = NOW(), remarks = 'Request cancelled by student'
                WHERE signature_id = :signature_id AND signed_status = 'Pending'";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':signature_id' => $signature_id]);
    }

    public function getStudentSignatureHistory($student_id) {
        $sql = "SELECT
            c.clearance_id,
            c.date_requested,
            c.school_year,
            c.term,
            cs.signature_id,
            cs.signed_status,
            cs.signed_date,
            cs.remarks,
            cs.sign_order,
            s.name AS signer_name,
            (
                SELECT COUNT(*)
                FROM clearance_signature
                WHERE clearance_id = c.clearance_id
                AND signed_status = 'Approved'
            ) = (
                SELECT COUNT(*)
                FROM clearance_signature
                WHERE clearance_id = c.clearance_id
                AND signed_status IN ('Approved', 'Pending', 'Rejected', 'Cancelled')
            ) AS is_fully_approved
            FROM clearance c
            JOIN clearance_signature cs ON c.clearance_id = cs.clearance_id
            JOIN signer s ON cs.signer_id = s.signer_id
            WHERE c.student_id = :student_id
            AND cs.signed_status IN ('Approved', 'Rejected', 'Cancelled')
            ORDER BY c.clearance_id DESC, cs.signed_date DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':student_id' => $student_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancelFullClearance($clearance_id) {
        try {
            $this->conn->beginTransaction();
            $stmt1 = $this->conn->prepare("
                UPDATE clearance
                SET status = 'Cancelled', date_completed = NOW(), remarks = 'Cancelled by Student'
                WHERE clearance_id = :clearance_id
            ");
            $stmt1->execute([':clearance_id' => $clearance_id]);

            $stmt2 = $this->conn->prepare("
                UPDATE clearance_signature
                SET signed_status = 'Cancelled', signed_date = NOW(), remarks = 'Full clearance cancelled by student'
                WHERE clearance_id = :clearance_id AND signed_status = 'Pending'
            ");
            $stmt2->execute([':clearance_id' => $clearance_id]);

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    public function getStudentsByAdviserId($faculty_id) {
        $sql = "
            SELECT
                s.student_id,
                s.school_id,
                CONCAT_WS(' ', s.fName, s.lName) AS student_name,
                s.course,
                s.year_level,
                s.section
            FROM student s
            WHERE s.adviser_id = :faculty_id
            ORDER BY s.course, s.year_level, s.section, s.lName
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':faculty_id' => $faculty_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStudentDetailsBySchoolId($school_id) {
        $sql = "
            SELECT
                s.student_id,
                s.school_id,
                CONCAT_WS(' ', s.fName, s.lName) AS student_name,
                s.course,
                s.year_level,
                s.section,
                s.adviser_id
            FROM student s
            WHERE s.school_id = :school_id
            LIMIT 1
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':school_id' => $school_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getClearanceStatusByStudentId($student_id) {
        $sql = "SELECT cs.*, c.clearance_id, s.signer_type, s.signer_id
            FROM clearance_signature cs
            JOIN clearance c ON cs.clearance_id = c.clearance_id
            JOIN signer s ON cs.signer_id = s.signer_id
            WHERE c.student_id = :student_id
            AND c.date_requested <= NOW()
            ORDER BY cs.signature_id ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCertificateData($clearance_id) {

        $final_status = $this->getClearanceFinalStatus($clearance_id);

        if ($final_status !== 'CLEARED') {
            return ['clearance_status' => $final_status];
        }

        $sql = "
            SELECT
                s.fName, s.lName, s.mName, s.school_id,
                s.course, s.year_level, s.section,
                c.date_completed, c.school_year, c.term,

                -- Get Dean's info from signer table
                (SELECT name FROM signer WHERE position = 'Dean' LIMIT 1) AS dean_name,
                (SELECT position FROM signer WHERE position = 'Dean' LIMIT 1) AS dean_title

            FROM clearance c
            JOIN student s ON c.student_id = s.student_id
            WHERE c.clearance_id = :cid";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':cid', $clearance_id);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result) {
                    $student_name = $result['lName'] . ', ' . $result['fName'] . ' ' . (isset($result['mName']) && !empty($result['mName']) ? strtoupper($result['mName'][0]) . '.' : '');

                    return [
                        'student_name' => $student_name,
                        'school_id' => $result['school_id'],
                        'course_section' => strtoupper($result['course']) . ' ' . $result['year_level'] . ' - ' . $result['section'],
                        'school_year' => $result['school_year'],
                        'term' => $result['term'],
                        'date_issued' => $result['date_completed'] ?? date('Y-m-d'),
                        'dean_name' => $result['dean_name'] ?? 'Dean of CCS (Verify faculty table)',
                        'dean_title' => $result['dean_title'] ?? 'Dean, College of Computer Studies',
                        'clearance_status' => 'CLEARED'
                    ];
                }
                return ['clearance_status' => 'NOT_FOUND'];
    }

    public function createClearanceRequest($student_id, $remarks = null, $school_year = null, $term = null) {

    if (empty($school_year) || empty($term)) {
        $last_cycle_sql = "
            SELECT school_year, term
            FROM clearance
            WHERE school_year IS NOT NULL AND term IS NOT NULL
            ORDER BY clearance_id DESC
            LIMIT 1";

        $last_cycle = $this->conn->query($last_cycle_sql)->fetch(PDO::FETCH_ASSOC);

        if ($last_cycle) {
            $school_year = $school_year ?: $last_cycle['school_year'];
            $term = $term ?: $last_cycle['term'];
        }
    }

    $sql = "INSERT INTO clearance (student_id, status, remarks, school_year, term)
            VALUES (:student_id, 'Pending', :remarks, :school_year, :term)";
    $stmt = $this->conn->prepare($sql);

    $stmt->bindValue(':student_id', $student_id);
    $stmt->bindValue(':remarks', $remarks);
    $stmt->bindValue(':school_year', $school_year, $school_year === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':term', $term, $term === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

    $stmt->execute();
    return $this->conn->lastInsertId();
    }

    public function archiveOldNotifications() {
        if (!$this->conn) return 0;

        try {
            $this->conn->beginTransaction();

            $sql_sig = "UPDATE clearance_signature cs
                        JOIN clearance c ON cs.clearance_id = c.clearance_id
                        SET cs.is_read = 1
                        WHERE c.status != 'Pending' AND cs.is_read = 0";
            $stmt_sig = $this->conn->prepare($sql_sig);
            $stmt_sig->execute();

            $sql_master = "UPDATE clearance c
                           SET c.is_read = 1
                           WHERE c.status = 'Completed' AND c.is_read = 0";
            $stmt_master = $this->conn->prepare($sql_master);
            $stmt_master->execute();

            $this->conn->commit();
            return $stmt_sig->rowCount() + $stmt_master->rowCount();
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error archiving old notifications: " . $e->getMessage());
            return 0;
        }
    }

    public function expirePendingClearances() {
    try {
        $this->conn->beginTransaction();

        $sql_clearance = "
            UPDATE clearance c
            -- Set to Cancelled and mark completion date (end date of validity)
            SET c.status = 'Cancelled', c.date_completed = NOW(), c.remarks = 'Cycle expired due to new academic term.'
            -- CRITICAL FIX: Only target records that are still truly PENDING (i.e., not already 'Completed')
            WHERE c.status = 'Pending'";

        $stmt_clearance = $this->conn->prepare($sql_clearance);
        $stmt_clearance->execute();

        $count = $stmt_clearance->rowCount();

        $sql_sig = "
            UPDATE clearance_signature cs
            JOIN clearance c ON cs.clearance_id = c.clearance_id
            SET cs.signed_status = 'Expired',
                cs.signed_date = NOW(),
                cs.remarks = 'Cycle expired by new term start.'
            WHERE c.status = 'Cancelled' AND cs.signed_status = 'Pending'";
        $this->conn->prepare($sql_sig)->execute();

        $this->archiveOldNotifications();

        $this->conn->commit();
        return $count;
    } catch (PDOException $e) {
        return 0;
    }
}

    public function getClearanceSignaturesForAdmin($clearance_id) {
        $query = "
            SELECT
                cs.signature_id,
                se.entity_type AS signer_type,
                COALESCE(f.faculty_id, o.org_id) AS signer_ref_id,
                cs.signed_status,
                cs.signed_date,
                cs.remarks,
                cs.uploaded_file,
                cs.sign_order,
                CASE
                    WHEN se.entity_type = 'Organization' THEN o.org_name
                    WHEN se.entity_type = 'Faculty' THEN CONCAT_WS(' ', f.fName, f.lName)
                    ELSE 'Unknown Signer'
                END AS signer_name,
                f.position
            FROM clearance_signature cs
            JOIN signer_entity se ON cs.signer_entity_id = se.signer_entity_id
            LEFT JOIN organization o ON se.signer_entity_id = o.signer_entity_id
            LEFT JOIN faculty f ON se.signer_entity_id = f.signer_entity_id
            WHERE cs.clearance_id = :clearance_id
            ORDER BY cs.sign_order ASC, cs.signature_id ASC
        ";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':clearance_id', $clearance_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching admin clearance status: " . $e->getMessage());
            return [];
        }
    }

    public function getStudentProfileData($student_id) {
        $sql = "SELECT fName, mName, lName, school_id, course, year_level, section, department_id, adviser_id,
                       profile_picture
                FROM student WHERE student_id = :sid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching student profile: " . $e->getMessage());
            return false;
        }
    }

    public function updateStudentProfile($student_id, $data, $external_conn) {
        $sql = "UPDATE student SET
                fName = :fn, mName = :mn, lName = :ln,
                profile_picture = :pp
                WHERE student_id = :sid";
        try {
            $stmt = $external_conn->prepare($sql);
            return $stmt->execute([
                ':fn' => $data['fName'],
                ':mn' => $data['mName'],
                ':ln' => $data['lName'],
                ':pp' => $data['profile_picture'],
                ':sid' => $student_id
            ]);
        } catch (PDOException $e) {
            error_log("Error updating student profile: " . $e->getMessage());
            throw $e;
        }
    }

    public function getAllSignaturesByClearanceId($clearance_id) {
        $query = "
            SELECT
                cs.signature_id,
                s.signer_type,
                s.signer_id,
                cs.signed_status,
                cs.signed_date,
                cs.remarks,
                cs.uploaded_file,
                cs.sign_order,
                s.name AS signer_name,
                s.position
            FROM clearance_signature cs
            JOIN signer s ON cs.signer_id = s.signer_id
            WHERE cs.clearance_id = :clearance_id
            ORDER BY cs.sign_order ASC, cs.signature_id ASC
        ";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':clearance_id', $clearance_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Error fetching all signatures: ' . $e->getMessage());
            return [];
        }
    }

   public function getRequiredSignersFullList($clearance_id) {
        $student_details_sql = "
            SELECT s.student_id, s.adviser_id, s.department_id, s.course, s.year_level, s.section, c.school_year
            FROM student s
            JOIN clearance c ON s.student_id = c.student_id
            WHERE c.clearance_id = :cid
        ";
        $stmt_student = $this->conn->prepare($student_details_sql);
        $stmt_student->execute([':cid' => $clearance_id]);
        $student = $stmt_student->fetch(PDO::FETCH_ASSOC);

        if (!$student) return [];

        $signer_sql = "
            SELECT
                signer_id,
                name,
                signer_type,
                position,
                department_id
            FROM signer
            WHERE requirements IS NOT NULL
               OR position IN ('Dean', 'SA Coordinator', 'Adviser', 'Department Head')
        ";

        $potential_signers = $this->conn->query($signer_sql)->fetchAll(PDO::FETCH_ASSOC);

        $required_signers = [];
        foreach ($potential_signers as $signer) {
            $include = false;
            $position = $signer['position'];
            $signer_id = $signer['signer_id'];

            if ($signer['signer_type'] === 'Organization') {
                $include = true;
            } elseif ($signer['signer_type'] === 'Faculty') {
                if ($position === 'Adviser' && $signer_id == $student['adviser_id']) {
                    $include = true;
                } elseif ($position === 'Department Head' && $signer['department_id'] == $student['department_id']) {
                    $include = true;
                } elseif (in_array($position, ['Dean', 'SA Coordinator'])) {
                    $include = true;
                }
            }

            if ($include) {
                $key = $signer_id;
                if (!isset($required_signers[$key])) {
                    $required_signers[$key] = $signer;
                }
            }
        }
        $required_signers = array_values($required_signers);

        $signature_status = $this->getAllSignaturesByClearanceId($clearance_id);

        $signature_map = [];
        foreach ($signature_status as $sig) {
            $key = $sig['signer_id'];
            $signature_map[$key] = $sig;
        }

        $final_list = [];
        foreach ($required_signers as $req) {
            $key = $req['signer_id'];
            $entry = [
                'signer_type' => $req['signer_type'],
                'signer_id' => $req['signer_id'],
                'signer_name' => $req['name'],
                'position' => $req['position'] ?? 'Organization',
                'signed_status' => 'Not Yet Requested',
                'remarks' => null,
                'uploaded_file' => null,
                'signed_date' => null,
                'signature_id' => null
            ];

            if (isset($signature_map[$key])) {
                $sig = $signature_map[$key];
                $entry['signed_status'] = $sig['signed_status'];
                $entry['remarks'] = $sig['remarks'];
                $entry['uploaded_file'] = $sig['uploaded_file'];
                $entry['signed_date'] = $sig['signed_date'];
                $entry['signature_id'] = $sig['signature_id'];
            }

            $final_list[] = $entry;
        }

        usort($final_list, function($a, $b) {
            $order_map = ['Dean' => 1, 'Department Head' => 2, 'Adviser' => 3, 'SA Coordinator' => 4];
            $posA = $order_map[$a['position']] ?? 100;
            $posB = $order_map[$b['position']] ?? 100;

            if ($posA === 100 && $posB === 100) {
                 return strcmp($a['signer_name'], $b['signer_name']);
            }
            return $posA <=> $posB;
        });

        return $final_list;
    }
}