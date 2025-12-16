<?php
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
require_once 'PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Notification {
    private static function getConfig() {
    return [
        'Host' => 'smtp.gmail.com',
        'SMTPAuth' => true,
        'Username' => 'ccslearance.system@gmail.com',
        'Password' => 'xtraykblioumqkma',
        'SMTPSecure' => PHPMailer::ENCRYPTION_SMTPS,
        'Port' => 465,
        'setFromEmail' => 'ccslearance.system@gmail.com',
        'setFromName' => 'CCS Clearance System',
        ];
    }

    private static function sendEmail($recipient_email, $subject, $body) {
        $config = self::getConfig();
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $config['Host'];
            $mail->SMTPAuth   = $config['SMTPAuth'];
            $mail->Username   = $config['Username'];
            $mail->Password   = $config['Password'];
            $mail->SMTPSecure = $config['SMTPSecure'];
            $mail->Port       = $config['Port'];
            $mail->setFrom($config['setFromEmail'], $config['setFromName']);
            $mail->addAddress($recipient_email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mail Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    private static function getEmailTemplate($title, $content) {
        $primary_color = '#0F280B';
        $text_color = '#FFFFFF';
        $border_color = '#333333';

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: ' . $primary_color . '; margin: 0; padding: 0;">

    <div style="width: 100%; max-width: 600px; margin: 30px auto; background-color: ' . $primary_color . '; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);">

        <div style="padding: 20px 25px; text-align: center; border-radius: 8px 8px 0 0; border-bottom: 1px solid ' . $border_color . ';">
            <h1 style="color: ' . $text_color . '; margin: 0; font-size: 24px; font-weight: 600;">CCS Clearance System</h1>
        </div>

        <div class="content-box" style="padding: 25px 35px; color: ' . $text_color . '; line-height: 1.6; font-size: 16px; text-align: left;">

            <h2 style="color: ' . $text_color . '; font-size: 20px; margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid ' . $border_color . ';">' . $title . '</h2>

            ' . $content . '

        </div>

        <div style="padding: 10px 25px; text-align: center; font-size: 12px; color: #999999; border-top: 1px solid ' . $border_color . '; border-radius: 0 0 8px 8px;">
            <p style="margin: 0; color: #AAAAAA;">This email was sent by the CCS Clearance System. Please do not reply to this automated message.</p>
        </div>

    </div>
</body>
</html>';
    }

    public static function sendVerificationEmail($recipient_email, $otp) {
        $subject = "Clearance System: Your Account Verification Code";

        $content = '
            <p>Dear User,</p>
            <p>Thank you for registering! Please use the 6-digit code below to verify your email address and activate your account on the verification page.</p>
            <div style="text-align: center; margin: 25px 0;">
                <span style="font-size: 32px; font-weight: bold; color: #4CAF50; border: 2px solid #4CAF50; padding: 10px 20px; border-radius: 8px; letter-spacing: 5px;">
                    ' . htmlspecialchars($otp) . '
                </span>
            </div>
            <p>This code is only valid for a single use for verification.</p>
        ';

        $body = self::getEmailTemplate(
            'Account Verification Code',
            $content
        );

        return self::sendEmail($recipient_email, $subject, $body);
    }

    public static function sendNewRequestAlert($recipient_email, $clearance_id, $signer_type, $sign_order) {
        return true;
    }

    public static function sendClearanceCompletedAlert($recipient_email, $clearance_id) {
        $subject = "✅ Clearance Completed: Certificate Available!";
        $certificate_url = "http://localhost/clearance/student/certificate.php?clearance_id=" . urlencode($clearance_id);

        $content = '
            <p>Dear Student,</p>
            <p>Congratulations! All required signatures for your clearance request (ID #' . htmlspecialchars($clearance_id) . ') have been successfully approved.</p>
            <p>You are now officially CLEARED. Click the link below to view and download your Certificate of Clearance.</p>
            <p style="margin: 30px 0 0 0; text-align: center;"><a href="' . $certificate_url . '" style="color: #4CAF50;">View Certificate</a></p>
        ';

        $body = self::getEmailTemplate(
            'Clearance Completed: Certificate Ready!',
            $content
        );

        return self::sendEmail($recipient_email, $subject, $body);
    }

    public static function sendSignerActionAlert($recipient_email, $clearance_id, $signer_name, $signed_status) {
        $subject = "Clearance Update: Request #{$clearance_id} was {$signed_status}";
        $portal_url = "http://localhost/clearance/student/status.php";

        $status_color = ($signed_status == 'Approved' ? '#4CAF50' : '#C0392B');

        $content = '
            <p>Dear Student,</p>
            <p>There has been an update on your clearance request (ID #' . htmlspecialchars($clearance_id) . ').</p>

            <div style="border: 1px solid #333333; padding: 15px; border-radius: 6px; margin-top: 25px; background-color: #1A3E15;">
                <p style="margin-top: 0; margin-bottom: 10px; font-weight: bold; color: #FFFFFF; font-size: 16px;">Action Details:</p>
                <ul style="list-style-type: none; padding: 0; margin: 0; font-size: 14px;">
                    <li style="padding: 5px 0; border-bottom: 1px dashed #333333;">
                        <span style="display: inline-block; width: 120px; color: #AAAAAA;">Signer:</span>
                        <strong style="color: #FFFFFF;">' . htmlspecialchars($signer_name) . '</strong>
                    </li>
                    <li style="padding: 5px 0;">
                        <span style="display: inline-block; width: 120px; color: #AAAAAA;">Status:</span>
                        <strong style="color: ' . $status_color . ';">' . htmlspecialchars($signed_status) . '</strong>
                    </li>
                </ul>
            </div>
            <p style="margin: 20px 0 0 0;">Please check your clearance status in the portal for details.</p>
            <p style="text-align: center;"><a href="' . $portal_url . '" style="color: #4CAF50;">Go to Clearance Status</a></p>
        ';

        $body = self::getEmailTemplate(
            'Clearance Update: ' . htmlspecialchars($signed_status),
            $content
        );

        return self::sendEmail($recipient_email, $subject, $body);
    }

    public static function sendNewCycleAlert($recipient_email, $school_year, $term) {
        $subject = "🔔 New Clearance Cycle Started: {$school_year} - {$term}";
        $portal_url = "http://localhost/clearance/student/request.php";

        $content = '
            <p>Dear User,</p>
            <p>A new clearance cycle has been officially started by the administration for the Academic Cycle: <strong>' . htmlspecialchars($school_year) . ' - ' . htmlspecialchars($term) . '</strong>.</p>
            <p>Please log in to the CCS Clearance System to check your current clearance status, or for faculty and organization users, to manage new pending requests.</p>
            <p style="margin: 30px 0 0 0; text-align: center;"><a href="' . $portal_url . '" style="color: #4CAF50;">Go to Clearance Portal</a></p>
        ';

        $body = self::getEmailTemplate(
            'New Clearance Cycle Available',
            $content
        );

        return self::sendEmail($recipient_email, $subject, $body);
    }

    public static function sendCycleEndingSoonAlert($recipient_email, $school_year, $term, $days_remaining) {
        $subject = "⚠️ Action Required: Clearance Cycle for {$school_year} - {$term} Ending Soon!";
        $portal_url = "http://localhost/clearance/index.php";

        $content = '
            <p>Dear User,</p>
            <p>This is an important system announcement. The current clearance cycle for the Academic Cycle: <strong>' . htmlspecialchars($school_year) . ' - ' . htmlspecialchars($term) . '</strong> is scheduled to conclude very soon.</p>
            <p>You have approximately <strong>' . htmlspecialchars($days_remaining) . ' day(s)</strong> remaining to complete your pending tasks (approvals or submissions).</p>
            <p>Please log in immediately to finalize your obligations before the cycle closes.</p>
            <p style="margin: 30px 0 0 0; text-align: center;"><a href="' . $portal_url . '" style="color: #C0392B;">Go to Clearance Portal Now</a></p>
        ';

        $body = self::getEmailTemplate(
            'Clearance Cycle Ending Soon',
            $content
        );

        return self::sendEmail($recipient_email, $subject, $body);
    }

    public static function sendSignerTaskAlert($recipient_email, $clearance_id, $student_name, $action_type, $signer_role_details) {
        $subject = "ACTION: Clearance Request #{$clearance_id} - " . ucwords($action_type);
        $portal_url = "http://localhost/clearance/index.php";

        $type_text = '';
        $action_text = '';
        $status_color = '#0F280B';

        switch ($action_type) {
            case 'new request':
                $type_text = 'New Request Submitted';
                $action_text = 'Student ' . htmlspecialchars($student_name) . ' submitted a file requiring your signature.';
                $status_color = '#4CAF50';
                $portal_url = "http://localhost/clearance/faculty/pending.php";
                break;
            case 'request canceled':
                $type_text = 'Request Canceled by Student';
                $action_text = 'Student ' . htmlspecialchars($student_name) . ' has cancelled their pending request.';
                $status_color = '#C0392B';
                $portal_url = "http://localhost/clearance/faculty/history.php";
                break;
            case 'pending reminder':
                $type_text = 'Pending Request Reminder';
                $action_text = 'You have a request from ' . htmlspecialchars($student_name) . ' pending for over 4 days. Please take action.';
                $status_color = '#D4AC0D';
                $portal_url = "http://localhost/clearance/faculty/pending.php";
                break;
            default:
                $type_text = 'Update';
                $action_text = 'A clearance action requires your attention.';
                $status_color = '#36451C';
                break;
        }

        $content = '
            <p>Dear Signer,</p>
            <p>' . $action_text . '</p>

            <div style="border: 1px solid #333333; padding: 15px; border-radius: 6px; margin-top: 25px; background-color: #1A3E15;">
                <p style="margin-top: 0; margin-bottom: 10px; font-weight: bold; color: #FFFFFF; font-size: 16px;">Notification Details:</p>
                <ul style="list-style-type: none; padding: 0; margin: 0; font-size: 14px;">
                    <li style="padding: 5px 0; border-bottom: 1px dashed #333333;">
                        <span style="display: inline-block; width: 120px; color: #AAAAAA;">Type:</span>
                        <strong style="color: ' . $status_color . ';">' . $type_text . '</strong>
                    </li>
                    <li style="padding: 5px 0; border-bottom: 1px dashed #333333;">
                        <span style="display: inline-block; width: 120px; color: #AAAAAA;">Clearance ID:</span>
                        <strong style="color: #FFFFFF;">' . htmlspecialchars($clearance_id) . '</strong>
                    </li>
                    <li style="padding: 5px 0;">
                        <span style="display: inline-block; width: 120px; color: #AAAAAA;">Date & Time:</span>
                        <strong style="color: #FFFFFF;">' . date('M d, Y h:i A') . '</strong>
                    </li>
                </ul>
            </div>
            <p style="margin: 30px 0 0 0; text-align: center;"><a href="' . $portal_url . '" style="color: #4CAF50;">Go to Pending Requests</a></p>
        ';

        $body = self::getEmailTemplate(
            'Clearance Task: ' . $type_text,
            $content
        );

        return self::sendEmail($recipient_email, $subject, $body);
    }


    public function getPendingSignatureCount($org_id) {
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
   public static function sendPasswordResetOtp($recipient_email, $otp) {
        $subject = "Password Reset Request - CCS Clearance";

        $content = '
            <p>Dear User,</p>
            <p>We received a request to reset the password associated with your account.</p>
            <p>Please enter the following 6-digit code to proceed:</p>

            <div style="text-align: center; margin: 30px 0;">
                <span style="font-size: 32px; font-weight: bold; color: #4CAF50; border: 2px dashed #4CAF50; padding: 10px 30px; border-radius: 8px; letter-spacing: 5px; background-color: #0F280B;">
                    ' . htmlspecialchars($otp) . '
                </span>
            </div>

            <p style="font-size: 0.9em; color: #ccc;">If you did not request a password reset, please ignore this email. Your account remains secure.</p>
        ';

        $body = self::getEmailTemplate(
            'Password Reset Request',
            $content
        );

        return self::sendEmail($recipient_email, $subject, $body);
    }
}