<?php
require_once "Database.php";

class Faculty extends Database {

    public function __construct() {
        $this->connect();
    }

    public function getFacultyDetails($faculty_id) {
        $sql = "SELECT fName, lName, position, department_id, course_assigned FROM faculty WHERE faculty_id = :fid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching faculty details: " . $e->getMessage());
            return false;
        }
    }

    public function getDashboardSummary($faculty_id) {
        $summary = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];

        $sql = "SELECT signed_status AS status, COUNT(*) AS total
                FROM clearance_signature
                WHERE signer_ref_id = :fid
                AND signer_type = 'Faculty'
                AND signed_status IN ('Pending', 'Approved', 'Rejected', 'Cancelled')
                GROUP BY signed_status";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $summary['Pending'] = $results['Pending'] ?? 0;
            $summary['Approved'] = $results['Approved'] ?? 0;
            $summary['Rejected'] = ($results['Rejected'] ?? 0) + ($results['Cancelled'] ?? 0);

            return $summary;

        } catch (PDOException $e) {
            error_log("Error fetching faculty summary: " . $e->getMessage());
            return $summary;
        }
    }

    public function getRecentRequests($faculty_id, $limit = 5) {
        $sql = "SELECT sig.clearance_id, s.school_id, CONCAT(s.fName, ' ', s.lName) as student_name,
                       sig.signed_date, sig.signed_status
                FROM clearance_signature sig
                JOIN clearance c ON sig.clearance_id = c.clearance_id
                JOIN student s ON c.student_id = s.student_id
                WHERE sig.signer_ref_id = :fid
                AND sig.signer_type = 'Faculty'
                AND sig.signed_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY sig.signed_date DESC
                LIMIT :lim";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);
            $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching recent requests: " . $e->getMessage());
            return [];
        }
    }


    public function getPendingRequests($faculty_id, $search_term = null, $filter_value = null, $filter_name = null) {

    $sql = "SELECT sig.signature_id, sig.clearance_id, sig.sign_order, sig.uploaded_file,
                    c.student_id,
                    CONCAT(s.fName, ' ', s.lName) as student_name,
                    s.school_id,
                    s.department_id,
                    s.course,
                    s.year_level,
                    s.section_id
            FROM clearance_signature sig
            JOIN clearance c ON sig.clearance_id = c.clearance_id
            JOIN student s ON c.student_id = s.student_id
            WHERE sig.signer_ref_id = :fid
              AND sig.signed_status = 'Pending'
              AND sig.signer_type = 'Faculty'
              AND c.status = 'Pending'";

    $params = [':fid' => $faculty_id];

    if ($search_term) {
        $search_param = '%' . $search_term . '%';
        $sql .= " AND (s.school_id LIKE :search OR CONCAT(s.fName, ' ', s.lName) LIKE :search)";
        $params[':search'] = $search_param;
    }

    if (!empty($filter_value)) {
        if ($filter_name === 'section_filter') {
            $sql .= " AND CONCAT(s.course, s.year_level, s.section_id) = :class_group";
            $params[':class_group'] = $filter_value;
        } elseif (is_numeric($filter_value)) {
            $sql .= " AND s.department_id = :dept_filter";
            $params[':dept_filter'] = (int)$filter_value;
        }
    }

    $sql .= " ORDER BY c.date_requested ASC";

    try {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching pending requests: " . $e->getMessage());
        return [];
    }
}

    public function checkPrerequisites($clearance_id, $signer_order) {
        $sql = "SELECT COUNT(*)
                FROM clearance_signature
                WHERE clearance_id = :cid AND sign_order < :sorder AND signed_status = 'Pending'";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':cid', $clearance_id, PDO::PARAM_INT);
            $stmt->bindParam(':sorder', $signer_order, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchColumn() == 0;
        } catch (PDOException $e) {
            error_log("Prerequisite check error: " . $e->getMessage());
            return false;
        }
    }

    public function signClearance($signature_id, $status, $remarks = NULL) {
        $sql = "UPDATE clearance_signature SET signed_status = :status, signed_date = CURRENT_TIMESTAMP(), remarks = :remarks WHERE signature_id = :sig_id";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':remarks', $remarks);
            $stmt->bindParam(':sig_id', $signature_id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Sign clearance error: " . $e->getMessage());
            return false;
        }
    }

    public function getAllStudents() {
        $sql = "SELECT student_id, CONCAT(fName, ' ', lName) as name, department_id FROM student";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching all students: " . $e->getMessage());
            return [];
        }
    }

    public function getHistoryRequests($faculty_id, $search_term = null, $dept_filter = null) {
        $sql = "SELECT sig.*, s.school_id, c.student_id, CONCAT(s.fName, ' ', s.lName) as student_name
                FROM clearance_signature sig
                JOIN clearance c ON sig.clearance_id = c.clearance_id
                JOIN student s ON c.student_id = s.student_id
                WHERE sig.signer_ref_id = :fid
                AND sig.signed_status IN ('Approved','Rejected', 'Cancelled')";

        if ($search_term) {
            $sql .= " AND (s.school_id LIKE :search OR CONCAT(s.fName, ' ', s.lName) LIKE :search)";
        }
        if ($dept_filter) {
            $sql .= " AND s.department_id = :dept_filter";
        }

        $sql .= " ORDER BY sig.signed_date DESC";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);

            if ($search_term) {
                $search_param = '%' . $search_term . '%';
                $stmt->bindParam(':search', $search_param);
            }
            if ($dept_filter) {
                $stmt->bindParam(':dept_filter', $dept_filter, PDO::PARAM_INT);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching history requests: " . $e->getMessage());
            return [];
        }
    }

    public function getAssignedClassGroups($faculty_id) {
        try {
            $sql = "SELECT DISTINCT
                        s.course,
                        s.year_level,
                        s.section_id,
                        CONCAT(s.course, s.year_level, s.section_id) AS class_group
                    FROM student s
                    WHERE s.adviser_id = :fid
                    ORDER BY s.course, s.year_level, s.section_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching assigned class groups: " . $e->getMessage());
            return [];
        }
    }

    public function getAssignedStudents($adviser_id, $search_term = null) {
        if (!$this->conn) return [];

        $sql = "SELECT student_id, school_id, fName, lName,
                        CONCAT(fName, ' ', lName) as name,
                        course, year_level, section_id
                FROM student
                WHERE adviser_id = :aid";

        if ($search_term) {
            $sql .= " AND (school_id LIKE :search OR CONCAT(fName, ' ', lName) LIKE :search)";
        }

        $sql .= " ORDER BY lName ASC";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':aid', $adviser_id, PDO::PARAM_INT);

            if ($search_term) {
                $search_param = '%' . $search_term . '%';
                $stmt->bindParam(':search', $search_param);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching advisee list: " . $e->getMessage());
            return [];
        }
    }

        public function getPendingSignatureCount($faculty_id) {
        // Assuming Faculty.php connects to the database via $this->conn
        $sql = "
            SELECT COUNT(signature_id)
            FROM clearance_signature
            WHERE signer_ref_id = :fid
            AND signer_type = 'Faculty'
            AND signed_status = 'Pending'";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn() ?? 0;
        } catch (PDOException $e) {
            error_log("Error fetching pending signature count for faculty: " . $e->getMessage());
            return 0;
        }
    }

    public function getFacultyNotifications($faculty_id, $limit = 10) {
        $sql = "
            SELECT
                cs.signature_id,
                cs.clearance_id,
                cs.signed_status,
                cs.is_read, -- FETCH is_read STATUS for styling
                c.date_requested,
                CONCAT(s.fName, ' ', s.lName) as student_name,
                s.school_id,
                s.course,
                s.year_level,
                s.section_id
            FROM clearance_signature cs
            JOIN clearance c ON cs.clearance_id = c.clearance_id
            JOIN student s ON c.student_id = s.student_id
            WHERE cs.signer_type = 'Faculty'
              AND cs.signer_ref_id = :fid
              -- Filter: Exclude manually deleted (is_read = 2)
              AND cs.is_read < 2
              AND cs.signed_status IN ('Pending', 'Cancelled')
            ORDER BY cs.signature_id DESC -- Newest first
            LIMIT :limit";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching faculty notifications: " . $e->getMessage());
            return [];
        }
    }

    public function markNotificationRead($signature_id) {
        $sql = "UPDATE clearance_signature SET is_read = 1 WHERE signature_id = :id AND signer_type = 'Faculty' AND is_read = 0";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $signature_id]);
    }

    public function clearNotification($signature_id) {
        $sql = "UPDATE clearance_signature SET is_read = 2 WHERE signature_id = :id AND signer_type = 'Faculty' AND is_read < 2";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $signature_id]);
    }

    public function markAllNotificationsRead($faculty_id) {
        $sql = "UPDATE clearance_signature cs
                SET cs.is_read = 1
                WHERE cs.signer_type = 'Faculty'
                AND cs.signer_ref_id = :fid
                AND cs.is_read = 0";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':fid' => $faculty_id]);
    }

    public function deleteAllNotifications($faculty_id) {
        $sql = "UPDATE clearance_signature cs
                SET cs.is_read = 2
                WHERE cs.signer_type = 'Faculty'
                AND cs.signer_ref_id = :fid
                AND cs.is_read < 2";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':fid' => $faculty_id]);
    }

    public function getFacultyProfileData($faculty_id) {
        $sql = "SELECT fName, mName, lName, position, department_id, course_assigned,
                       profile_picture
                FROM faculty WHERE faculty_id = :fid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $faculty_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching faculty profile: " . $e->getMessage());
            return false;
        }
    }

    public function updateFacultyProfile($faculty_id, $data, $external_conn) {
        $sql = "UPDATE faculty SET
                fName = :fn, mName = :mn, lName = :ln,
                profile_picture = :pp
                WHERE faculty_id = :fid";
        try {
            $stmt = $external_conn->prepare($sql);
            return $stmt->execute([
                ':fn' => $data['fName'],
                ':mn' => $data['mName'],
                ':ln' => $data['lName'],
                ':pp' => $data['profile_picture'],
                ':fid' => $faculty_id
            ]);
        } catch (PDOException $e) {
            error_log("Error updating faculty profile: " . $e->getMessage());
            throw $e;
        }
    }


}