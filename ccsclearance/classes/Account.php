<?php
require_once "Database.php";
require_once "Notification.php";

class Account extends Database {
    public $email;
    public $password;
    public $role;
    public $ref_id;

    public function __construct() {
        $this->connect();
    }

    public function generateOtp() {
        return str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function getConnection() {
        return $this->conn;
    }

    public function register($fName = null, $mName = null, $lName = null, $dept_id = null, $position = null, $org_name = null, $course = null, $year_level = null, $section_id = null, $adviser_id = null) {
        if (!$this->conn) {
            return "Internal Error: Database connection object is missing.";
        }

        try {
            $this->conn->beginTransaction();

            $checkEmail = $this->conn->prepare("SELECT email FROM account WHERE email = :email");
            $checkEmail->bindParam(":email", $this->email);
            $checkEmail->execute();

            if ($checkEmail->rowCount() > 0) {
                $this->conn->rollBack();
                return "Email address is already registered.";
            }

            if ($this->role == "student") {
                $checkRefId = $this->conn->prepare("SELECT school_id FROM student WHERE school_id = :sid");
                $checkRefId->bindParam(":sid", $this->ref_id);
                $checkRefId->execute();

                if ($checkRefId->rowCount() > 0) {
                    $this->conn->rollBack();
                    return "School ID is already registered.";
                }
            }

            $hashedPassword = password_hash($this->password, PASSWORD_DEFAULT);

            $verification_otp = $this->generateOtp();

            $sql = "INSERT INTO account (email, password, role, is_verified, verification_token)
                VALUES (:email, :password, :role, 0, :token)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $this->email);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':role', $this->role);
            $stmt->bindParam(':token', $verification_otp);
            $stmt->execute();

            $account_id = $this->conn->lastInsertId();

            if ($this->role == "student") {
                $sql_ref = "INSERT INTO student (school_id, account_id, fName, mName, lName, department_id, course, year_level, section, adviser_id)
                            VALUES (:sid, :aid, :fn, :mn, :ln, :dept, :course, :lvl, :sec, :adv)";
                $stmt_ref = $this->conn->prepare($sql_ref);
                $stmt_ref->bindParam(':sid', $this->ref_id);
                $stmt_ref->bindParam(':aid', $account_id);
                $stmt_ref->bindParam(':fn', $fName);
                $stmt_ref->bindParam(':mn', $mName);
                $stmt_ref->bindParam(':ln', $lName);
                $stmt_ref->bindParam(':dept', $dept_id);
                $stmt_ref->bindParam(':course', $course);
                $stmt_ref->bindParam(':lvl', $year_level);
                $stmt_ref->bindParam(':sec', $section_id);
                $stmt_ref->bindValue(':adv', $adviser_id ?? null, $adviser_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            }
            elseif ($this->role == "signer") {
                $full_name = trim($fName . ' ' . $mName . ' ' . $lName);
                $full_name = preg_replace('/\s+/', ' ', $full_name);

                $signer_type = !empty($org_name) ? 'Organization' : 'Faculty';
                $name = !empty($org_name) ? $org_name : $full_name;

                $sql_ref = "INSERT INTO signer (account_id, name, signer_type, position, department_id, requirements)
                            VALUES (:aid, :name, :stype, :pos, :dept, :req)";
                $stmt_ref = $this->conn->prepare($sql_ref);
                $stmt_ref->bindParam(':aid', $account_id);
                $stmt_ref->bindParam(':name', $name);
                $stmt_ref->bindParam(':stype', $signer_type);
                $stmt_ref->bindValue(':pos', $signer_type === 'Faculty' ? $position : null);
                $stmt_ref->bindValue(':dept', $signer_type === 'Faculty' ? $dept_id : null, PDO::PARAM_INT);
                $stmt_ref->bindValue(':req', $course ?? null);
            }
            elseif ($this->role == "admin") {
                $stmt_ref = null;
            }

            if (isset($stmt_ref)) {
                $stmt_ref->execute();
            }

            Notification::sendVerificationEmail($this->email, $verification_otp);

            $this->conn->commit();
            return true;

            } catch (PDOException $e) {
            $this->conn->rollBack();
            return "Database Error during INSERT: " . $e->getMessage();
        }
    }

    public function login() {
        if (!$this->conn) {
            $this->connect();
        }

        $sql = "SELECT * FROM account WHERE email = :email LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':email', $this->email);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (password_verify($this->password, $user['password'])) {

                if ($user['role'] === 'admin') {
                    $this->ref_id = $user['account_id'];
                } else {
                    $this->ref_id = $this->getRefId($user['account_id'], $user['role']);
                }
                return $user;
            } else {
                return false;
            }
        }

        return false;
    }

    private function getRefId($account_id, $role) {
        if ($role == 'student') {
            $sql = "SELECT student_id AS ref_id FROM student WHERE account_id = :aid";
        } elseif ($role == 'signer') {
            $sql = "SELECT signer_id AS ref_id FROM signer WHERE account_id = :aid";
        } elseif ($role == 'admin') {
            return $account_id;
        } else {
            return $account_id;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':aid', $account_id);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['ref_id'] ?? null;
    }

    public function verifyAccount($email, $otp) {
        if (!$this->conn) return false;

        try {
            $this->conn->beginTransaction();

            $sql = "SELECT account_id FROM account
                    WHERE email = :email AND verification_token = :otp AND is_verified = 0";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':otp', $otp);
            $stmt->execute();
            $user_id = $stmt->fetchColumn();

            if (!$user_id) {
                $this->conn->rollBack();
                return false;
            }

            $update_sql = "UPDATE account
                           SET is_verified = 1, verification_token = NULL
                           WHERE account_id = :aid";
            $update_stmt = $this->conn->prepare($update_sql);
            $update_stmt->bindParam(':aid', $user_id, PDO::PARAM_INT);
            $update_stmt->execute();

            $this->conn->commit();
            return true;

        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Verification Database Error: " . $e->getMessage());
            return false;
        }
    }

    public function setupAccount($placeholder_email, $new_email, $new_password) {
        if (!$this->conn) return "Database connection error.";

        $checkNewEmail = $this->conn->prepare("SELECT email FROM account WHERE email = :email AND is_verified = 1");
        $checkNewEmail->bindParam(":email", $new_email);
        $checkNewEmail->execute();
        if ($checkNewEmail->rowCount() > 0) {
            return "The new email address is already in use by a verified account.";
        }

        try {
            $this->conn->beginTransaction();

            $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
            $verification_otp = $this->generateOtp();

            $sql = "UPDATE account
                    SET email = :new_email,
                        password = :password,
                        verification_token = :otp
                    WHERE email = :placeholder_email
                    AND is_verified = 0";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':new_email', $new_email);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':otp', $verification_otp);
            $stmt->bindParam(':placeholder_email', $placeholder_email);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                $this->conn->rollBack();
                return "Account not found or already verified. Please try logging in again.";
            }

            Notification::sendVerificationEmail($new_email, $verification_otp);

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            if ($e->getCode() == 23000) {
                return "Database Error: The new email is already in use.";
            }
            error_log("Setup Account Error: " . $e->getMessage());
            return "Database Error during account setup.";
        }
    }

    public function getAccountDetailsByRefId($ref_id, $role) {
        $table_map = [
            'student' => ['table' => 'student', 'id_col' => 'student_id'],
            'signer' => ['table' => 'signer', 'id_col' => 'signer_id'],
        ];

        if (!isset($table_map[$role])) return null;

        $t = $table_map[$role]['table'];
        $c = $table_map[$role]['id_col'];

        $sql = "SELECT a.account_id, a.email, a.password, a.role, a.is_verified
                FROM account a
                JOIN $t r ON a.account_id = r.account_id
                WHERE r.$c = :rid";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':rid', $ref_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateAccountDetails($account_id, $new_email, $new_password = null, $external_conn = null) {
        $conn_to_use = $external_conn ?: $this->conn;
        $sql = "UPDATE account SET email = :email";
        $params = [':email' => $new_email, ':aid' => $account_id];

        if (!empty($new_password)) {
            $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
            $sql .= ", password = :password";
            $params[':password'] = $hashedPassword;
        }

        $sql .= " WHERE account_id = :aid";

        try {
            $stmt = $conn_to_use->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Account Update Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function initiatePasswordReset($email) {
        if (!$this->conn) $this->connect();
        $stmt = $this->conn->prepare("SELECT account_id FROM account WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() == 0) {
            return false;
        }

        $otp = $this->generateOtp();
        $update = $this->conn->prepare("UPDATE account SET verification_token = :otp WHERE email = :email");
        $update->bindParam(':otp', $otp);
        $update->bindParam(':email', $email);

        if ($update->execute()) {
            Notification::sendPasswordResetOtp($email, $otp);
            return true;
        }
        return false;
    }

    public function resetPassword($email, $otp, $newPassword) {
        if (!$this->conn) $this->connect();
        $stmt = $this->conn->prepare("SELECT account_id FROM account WHERE email = :email AND verification_token = :otp");
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':otp', $otp);
        $stmt->execute();

        if ($stmt->rowCount() == 0) {
            return "Invalid OTP or Email.";
        }

        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $this->conn->prepare("UPDATE account SET password = :pass, verification_token = NULL WHERE email = :email");
        $update->bindParam(':pass', $hashed);
        $update->bindParam(':email', $email);

        if ($update->execute()) {
            return true;
        }
        return "Database error during password update.";
    }
}