<?php
require_once "Database.php";
require_once "Clearance.php";

class Organization extends Database {
    protected $conn;

    public function __construct() {
        $this->conn = $this->connect();
    }

    public function getOrgDetails($org_id) {
        $sql = "SELECT org_name, requirements FROM organization WHERE org_id = :oid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':oid', $org_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching organization details: " . $e->getMessage());
            return null;
        }
    }

    public function getRequirements($org_id) {
        $details = $this->getOrgDetails($org_id);
        return $details['requirements'] ?? '';
    }

    public function updateRequirements($org_id, $requirements) {
        if (!$this->conn) return false;

        $sql = "UPDATE organization SET requirements = :req WHERE org_id = :oid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':req', $requirements);
            $stmt->bindParam(':oid', $org_id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Organization Requirement Update Error: " . $e->getMessage());
            return false;
        }
    }

    public function getOrgNameById($org_id) {
        $sql = "SELECT org_name FROM organization WHERE org_id = :oid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':oid', $org_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn() ?? "Unknown Organization";
        } catch (PDOException $e) {
            error_log("Error fetching organization name: " . $e->getMessage());
            return "Unknown Organization";
        }
    }

public function getDashboardSummary($org_id) {
    $summary = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];

    $sql = "
        SELECT cs.signed_status AS status, COUNT(*) AS total
        FROM clearance_signature cs
        INNER JOIN (
            SELECT clearance_id, MAX(signature_id) AS latest_sig
            FROM clearance_signature
            WHERE signer_ref_id = :org_id AND signer_type = 'Organization'
            GROUP BY clearance_id
        ) latest ON cs.signature_id = latest.latest_sig
        WHERE cs.signer_ref_id = :org_id
          AND cs.signer_type = 'Organization'
          AND cs.signed_status IN ('Pending', 'Approved', 'Rejected', 'Cancelled')
        GROUP BY cs.signed_status
    ";

    try {
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':org_id', $org_id, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $summary['Pending'] = $results['Pending'] ?? 0;
        $summary['Approved'] = $results['Approved'] ?? 0;
        $summary['Rejected'] = $results['Rejected'] ?? 0;
        $summary['Cancelled'] = $results['Cancelled'] ?? 0;

        return $summary;
    } catch (PDOException $e) {
        error_log("Error fetching organization summary: " . $e->getMessage());
        return $summary;
    }
}

    public function getRecentRequests($org_id, $limit = 5) {
        $sql = "SELECT sig.clearance_id, s.school_id, CONCAT(s.fName, ' ', s.lName) as student_name,
                       sig.signed_date, sig.signed_status
                FROM clearance_signature sig
                JOIN clearance c ON sig.clearance_id = c.clearance_id
                JOIN student s ON c.student_id = s.student_id
                WHERE sig.signer_ref_id = :org_id
                AND sig.signer_type = 'Organization'
                AND sig.signed_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY sig.signed_date DESC
                LIMIT :lim";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':org_id', $org_id, PDO::PARAM_INT);
            $stmt->bindParam(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching recent requests: " . $e->getMessage());
            return [];
        }
    }

    public function getPendingSignatureCount($org_id) {
        $sql = "
            SELECT COUNT(signature_id)
            FROM clearance_signature
            WHERE signer_ref_id = :fid
            AND signer_type = 'Organization'
            AND signed_status = 'Pending'";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':fid', $org_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn() ?? 0;
        } catch (PDOException $e) {
            error_log("Error fetching pending signature count for organization: " . $e->getMessage());
            return 0;
        }
    }

    public function getOrgNotifications($org_id, $limit = 10) {
        $sql = "SELECT
            cs.signature_id,
            cs.clearance_id,
            cs.signed_status,
            cs.is_read,
            c.date_requested,
            CONCAT(s.fName, ' ', s.lName) as student_name,
            s.school_id
        FROM clearance_signature cs
        JOIN clearance c ON cs.clearance_id = c.clearance_id
        JOIN student s ON c.student_id = s.student_id
        WHERE cs.signer_type = 'Organization'
          AND cs.signer_ref_id = :org_id
          -- Filter: Exclude manually deleted (is_read = 2)
          AND cs.is_read < 2
          AND cs.signed_status IN ('Pending', 'Cancelled') -- The two events that trigger alerts
        ORDER BY cs.signature_id DESC -- Newest first
        LIMIT :limit";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':org_id', $org_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching organization notifications: " . $e->getMessage());
            return [];
        }
    }

    public function markNotificationRead($signature_id) {
        $sql = "UPDATE clearance_signature SET is_read = 1 WHERE signature_id = :id AND signer_type = 'Organization' AND is_read = 0";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $signature_id]);
    }

    public function clearNotification($signature_id) {
        $sql = "UPDATE clearance_signature SET is_read = 2 WHERE signature_id = :id AND signer_type = 'Organization' AND is_read < 2";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $signature_id]);
    }

    public function markAllNotificationsRead($org_id) {
        $sql = "UPDATE clearance_signature cs
                SET cs.is_read = 1
                WHERE cs.signer_type = 'Organization'
                AND cs.signer_ref_id = :org_id
                AND cs.is_read = 0";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':org_id' => $org_id]);
    }

    public function deleteAllNotifications($org_id) {
        $sql = "UPDATE clearance_signature cs
                SET cs.is_read = 2
                WHERE cs.signer_type = 'Organization'
                AND cs.signer_ref_id = :org_id
                AND cs.is_read < 2";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':org_id' => $org_id]);
    }

    public function countPendingSignatures($org_id, $search_term = null) {
        $sql = "SELECT COUNT(*)
                FROM clearance_signature cs
                JOIN clearance c ON cs.clearance_id = c.clearance_id
                JOIN student s ON c.student_id = s.student_id
                WHERE cs.signer_type = 'Organization'
                AND cs.signer_ref_id = :org_id
                AND cs.signed_status = 'Pending'";

        if ($search_term) {
            $sql .= " AND (s.school_id LIKE :search OR CONCAT(s.fName, ' ', s.lName) LIKE :search)";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':org_id', $org_id);
        if ($search_term) {
            $search_param = '%' . $search_term . '%';
            $stmt->bindParam(':search', $search_param);
        }
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    public function countHistorySignatures($org_id, $search_term = null) {
        $sql = "SELECT COUNT(*)
                FROM clearance_signature cs
                JOIN clearance c ON cs.clearance_id = c.clearance_id
                JOIN student s ON c.student_id = s.student_id
                WHERE cs.signer_type = 'Organization'
                AND cs.signer_ref_id = :org_id
                AND cs.signed_status IN ('Approved', 'Rejected', 'Cancelled')";

        if ($search_term) {
            $sql .= " AND (s.school_id LIKE :search OR CONCAT(s.fName, ' ', s.lName) LIKE :search)";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':org_id', $org_id);
        if ($search_term) {
            $search_param = '%' . $search_term . '%';
            $stmt->bindParam(':search', $search_param);
        }
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    public function getPendingRequests($org_id, $search_term = null) {
        $sql = "SELECT
                    cs.*,
                    c.student_id,
                    CONCAT(s.fName, ' ', s.lName) AS student_name,
                    s.school_id,
                    s.department_id
                FROM clearance_signature cs
                JOIN clearance c ON cs.clearance_id = c.clearance_id
                JOIN student s ON c.student_id = s.student_id
                WHERE cs.signer_type = 'Organization'
                AND cs.signer_ref_id = :org_id
                AND cs.signed_status = 'Pending'
                AND c.status = 'Pending'";

        if ($search_term) {
            $sql .= " AND (s.school_id LIKE :search OR CONCAT(s.fName, ' ', s.lName) LIKE :search)";
        }

        $sql .= " ORDER BY cs.signed_date ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':org_id', $org_id);
        if ($search_term) {
            $search_param = '%' . $search_term . '%';
            $stmt->bindParam(':search', $search_param);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getHistoryRequests($org_id, $search_term = null) {
        $query = "
            SELECT
                c.clearance_id,
                CONCAT(s.fName, ' ', s.lName) AS student_name,
                s.student_id,
                s.school_id,
                cs.signed_status,
                cs.signed_date,
                cs.remarks
            FROM clearance_signature cs
            INNER JOIN clearance c ON cs.clearance_id = c.clearance_id
            INNER JOIN student s ON c.student_id = s.student_id
            WHERE cs.signer_type = 'Organization'
              AND cs.signer_ref_id = :org_id
              AND cs.signed_status IN ('Approved', 'Rejected', 'Cancelled')";

        if ($search_term) {
            $query .= " AND (s.school_id LIKE :search OR CONCAT(s.fName, ' ', s.lName) LIKE :search)";
        }

        $query .= " ORDER BY cs.signed_date DESC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':org_id', $org_id);
            if ($search_term) {
                $search_param = '%' . $search_term . '%';
                $stmt->bindParam(':search', $search_param);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching history requests: " . $e->getMessage());
            return [];
        }
    }

    public function signClearance($signature_id, $status, $remarks = NULL) {
        $sql = "UPDATE clearance_signature
                SET signed_status = :status,
                    signed_date = CURRENT_TIMESTAMP(),
                    remarks = :remarks
                WHERE signature_id = :sig_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':remarks', $remarks);
        $stmt->bindParam(':sig_id', $signature_id);

        if ($stmt->execute()) {
            $signerInfoQuery = "SELECT clearance_id, signer_type, signer_ref_id
                                FROM clearance_signature WHERE signature_id = :sig_id";
            $sigStmt = $this->conn->prepare($signerInfoQuery);
            $sigStmt->bindParam(':sig_id', $signature_id);
            $sigStmt->execute();
            $signer_details = $sigStmt->fetch(PDO::FETCH_ASSOC);

            if ($signer_details) {
                $clearance_id = $signer_details['clearance_id'];
                require_once "Clearance.php";
                $clearanceObj = new Clearance();
                $clearanceObj->sendSignerActionNotification(
                    $clearance_id,
                    $signer_details['signer_type'],
                    $signer_details['signer_ref_id'],
                    $status
                );
            }

            $clearanceQuery = "SELECT clearance_id FROM clearance_signature WHERE signature_id = :sig_id";
            $clearStmt = $this->conn->prepare($clearanceQuery);
            $clearStmt->bindParam(':sig_id', $signature_id);
            $clearStmt->execute();
            $clearance_id = $clearStmt->fetchColumn();

           $checkQuery = "SELECT COUNT(*) FROM clearance_signature
                           WHERE clearance_id = :cid AND signed_status = 'Pending'";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':cid', $clearance_id);
            $checkStmt->execute();
            $pendingCount = $checkStmt->fetchColumn();

            if ($pendingCount == 0) {
                $rejectedQuery = "SELECT COUNT(*) FROM clearance_signature
                                  WHERE clearance_id = :cid AND signed_status = 'Rejected'";
                $rejStmt = $this->conn->prepare($rejectedQuery);
                $rejStmt->bindParam(':cid', $clearance_id);
                $rejStmt->execute();
                $rejectedCount = $rejStmt->fetchColumn();

                $finalStatus = ($rejectedCount > 0) ? 'Rejected' : 'Approved';

                $updateClearance = "UPDATE clearance
                                    SET status = :finalStatus,
                                        date_completed = CURRENT_TIMESTAMP()
                                    WHERE clearance_id = :cid";
                $updateStmt = $this->conn->prepare($updateClearance);
                $updateStmt->bindParam(':finalStatus', $finalStatus);
                $updateStmt->bindParam(':cid', $clearance_id);
                $updateStmt->execute();
            }

            return true;
        }
        return false;
    }

    public function getClearanceIdBySignature($signature_id) {
    $sql = "SELECT clearance_id FROM clearance_signature WHERE signature_id = :sid LIMIT 1";
    $stmt = $this->conn->prepare($sql);
    $stmt->bindParam(':sid', $signature_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn();
}

public function getOrgProfileData($org_id) {
        $sql = "SELECT org_name, requirements, profile_picture
                FROM organization WHERE org_id = :oid";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':oid', $org_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching organization profile: " . $e->getMessage());
            return null;
        }
    }

    public function updateOrgProfile($org_id, $data, $external_conn) {
        $sql = "UPDATE organization SET
                org_name = :oname,
                profile_picture = :pp
                WHERE org_id = :oid";
        try {
            $stmt = $external_conn->prepare($sql);
            return $stmt->execute([
                ':oname' => $data['org_name'],
                ':pp' => $data['profile_picture'],
                ':oid' => $org_id
            ]);
        } catch (PDOException $e) {
            error_log("Error updating organization profile: " . $e->getMessage());
            throw $e;
        }
    }

}