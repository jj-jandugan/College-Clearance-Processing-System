<?php
require_once "Database.php";

class Signer extends Database {

    public function __construct() {
        $this->connect();
    }

    public function getSignerDetails($signer_id) {
        $sql = "SELECT name, signer_type, position, department_id, requirements, profile_picture
                FROM signer WHERE signer_id = :sid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && isset($result['name'])) {
                $nameParts = explode(' ', trim($result['name']));
                $result['fName'] = $nameParts[0] ?? '';
                $result['lName'] = end($nameParts) ?? '';
                $result['mName'] = (count($nameParts) > 2) ? implode(' ', array_slice($nameParts, 1, -1)) : '';
            }

            return $result;
        } catch (PDOException $e) {
            error_log("Error fetching signer details: " . $e->getMessage());
            return false;
        }
    }
    public function getDashboardSummary($signer_id) {
        $summary = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];

        $sql = "SELECT signed_status AS status, COUNT(*) AS total
                FROM clearance_signature
                WHERE signer_id = :sid
                AND signed_status IN ('Pending', 'Approved', 'Rejected', 'Cancelled')
                GROUP BY signed_status";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $summary['Pending'] = $results['Pending'] ?? 0;
            $summary['Approved'] = $results['Approved'] ?? 0;
            $summary['Rejected'] = ($results['Rejected'] ?? 0) + ($results['Cancelled'] ?? 0);

            return $summary;

        } catch (PDOException $e) {
            error_log("Error fetching signer summary: " . $e->getMessage());
            return $summary;
        }
    }

    public function getRecentRequests($signer_id, $limit = 5) {
        $sql = "SELECT sig.clearance_id, s.school_id, CONCAT(s.fName, ' ', s.lName) as student_name,
                       sig.signed_date, sig.signed_status
                FROM clearance_signature sig
                JOIN clearance c ON sig.clearance_id = c.clearance_id
                JOIN student s ON c.student_id = s.student_id
                WHERE sig.signer_id = :sid
                AND sig.signed_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY sig.signed_date DESC
                LIMIT :lim";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
            $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching recent requests: " . $e->getMessage());
            return [];
        }
    }

    public function getPendingRequests($signer_id, $search_term = null, $filter_value = null, $filter_name = null) {
        $sql = "SELECT sig.signature_id, sig.clearance_id, sig.sign_order, sig.uploaded_file,
                    c.student_id,
                    CONCAT(s.fName, ' ', s.lName) as student_name,
                    s.school_id,
                    s.department_id,
                    s.course,
                    s.year_level,
                    s.section
            FROM clearance_signature sig
            JOIN clearance c ON sig.clearance_id = c.clearance_id
            JOIN student s ON c.student_id = s.student_id
            WHERE sig.signer_id = :sid
              AND sig.signed_status = 'Pending'
              AND c.status = 'Pending'";

        $params = [':sid' => $signer_id];

        if ($search_term) {
            $search_param = '%' . $search_term . '%';
            $sql .= " AND (s.school_id LIKE :search OR CONCAT(s.fName, ' ', s.lName) LIKE :search)";
            $params[':search'] = $search_param;
        }

        if (!empty($filter_value)) {
            if ($filter_name === 'section_filter') {
                $sql .= " AND CONCAT(s.course, s.year_level, s.section) = :class_group";
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

    public function getHistoryRequests($signer_id, $search_term = null, $dept_filter = null) {
        $sql = "SELECT sig.*, s.school_id, c.student_id, CONCAT(s.fName, ' ', s.lName) as student_name
                FROM clearance_signature sig
                JOIN clearance c ON sig.clearance_id = c.clearance_id
                JOIN student s ON c.student_id = s.student_id
                WHERE sig.signer_id = :sid
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
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);

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

    public function getAssignedClassGroups($signer_id) {
        try {
            $sql = "SELECT DISTINCT
                        s.course,
                        s.year_level,
                        s.section,
                        CONCAT(s.course, s.year_level, s.section) AS class_group
                    FROM student s
                    WHERE s.adviser_id = :sid
                    ORDER BY s.course, s.year_level, s.section";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
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
                        course, year_level, section
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

    public function getPendingSignatureCount($signer_id) {
        $sql = "
            SELECT COUNT(signature_id)
            FROM clearance_signature
            WHERE signer_id = :sid
            AND signed_status = 'Pending'";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn() ?? 0;
        } catch (PDOException $e) {
            error_log("Error fetching pending signature count: " . $e->getMessage());
            return 0;
        }
    }

    public function getSignerNotifications($signer_id, $limit = 10) {
        $sql = "
            SELECT
                cs.signature_id,
                cs.clearance_id,
                cs.signed_status,
                cs.is_read,
                c.date_requested,
                CONCAT(s.fName, ' ', s.lName) as student_name,
                s.school_id,
                s.course,
                s.year_level,
                s.section
            FROM clearance_signature cs
            JOIN clearance c ON cs.clearance_id = c.clearance_id
            JOIN student s ON c.student_id = s.student_id
            WHERE cs.signer_id = :sid
              AND cs.is_read < 2
              AND cs.signed_status IN ('Pending', 'Cancelled')
            ORDER BY cs.signature_id DESC
            LIMIT :limit";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching signer notifications: " . $e->getMessage());
            return [];
        }
    }

    public function markNotificationRead($signature_id) {
        $sql = "UPDATE clearance_signature
                SET is_read = 1
                WHERE signature_id = :id AND is_read = 0";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $signature_id]);
    }

    public function clearNotification($signature_id) {
        $sql = "UPDATE clearance_signature
                SET is_read = 2
                WHERE signature_id = :id AND is_read < 2";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $signature_id]);
    }

    public function markAllNotificationsRead($signer_id) {
        $sql = "UPDATE clearance_signature
                SET is_read = 1
                WHERE signer_id = :sid
                AND is_read = 0";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':sid' => $signer_id]);
    }

    public function deleteAllNotifications($signer_id) {
        $sql = "UPDATE clearance_signature
                SET is_read = 2
                WHERE signer_id = :sid
                AND is_read < 2";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':sid' => $signer_id]);
    }

    public function getSignerProfileData($signer_id) {
        $sql = "SELECT name, signer_type, position, department_id, requirements, profile_picture
                FROM signer WHERE signer_id = :sid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching signer profile: " . $e->getMessage());
            return false;
        }
    }

    public function updateSignerProfile($signer_id, $data, $external_conn) {
        if (isset($data['fName']) && isset($data['lName'])) {
            $mName = isset($data['mName']) && !empty($data['mName']) ? ' ' . trim($data['mName']) . ' ' : ' ';
            $data['name'] = trim($data['fName'] . $mName . $data['lName']);
        }

        $sql = "UPDATE signer SET
                name = :name,
                profile_picture = :pp
                WHERE signer_id = :sid";
        try {
            $stmt = $external_conn->prepare($sql);
            return $stmt->execute([
                ':name' => $data['name'],
                ':pp' => $data['profile_picture'],
                ':sid' => $signer_id
            ]);
        } catch (PDOException $e) {
            error_log("Error updating signer profile: " . $e->getMessage());
            throw $e;
        }
    }

    public function getSignerName($signer_id) {
        $sql = "SELECT name FROM signer WHERE signer_id = :sid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':sid', $signer_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error fetching signer name: " . $e->getMessage());
            return null;
        }
    }
}
