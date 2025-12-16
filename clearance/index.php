<?php
ob_start();
session_start();
require_once "classes/Database.php";
require_once "classes/Account.php";

$accountObj = new Account();

$email = "";
$password = "";
$otp = "";
$error = "";
$showVerificationForm = false;
$showSetupForm = false;
$verificationEmail = "";
$setupEmail = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (isset($_POST['setup_account'])) {
        $placeholder_email = trim(htmlspecialchars($_POST['placeholder_email']));
        $new_email = trim(htmlspecialchars($_POST['new_email']));
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($new_email) || empty($new_password) || empty($confirm_password)) {
            $error = "❌ Account setup failed. All fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error = "❌ Passwords do not match.";
        } else {
            $result = $accountObj->setupAccount($placeholder_email, $new_email, $new_password);
            if ($result === true) {
                $error = "✅ Account setup complete! An OTP has been sent to " . htmlspecialchars($new_email) . ". Please enter the code below to finalize activation.";
                $showSetupForm = false;
                $showVerificationForm = true;
                $verificationEmail = $new_email;
            } else {
                $error = "⚠️ Account setup failed: " . $result;
                $showSetupForm = true;
                $setupEmail = $placeholder_email;
            }
        }
    } elseif (isset($_POST['verify_otp'])) {
        $otpEmail = trim(htmlspecialchars($_POST['otp_email']));
        $otpCode = trim(htmlspecialchars($_POST['otp_code']));

        if (empty($otpEmail) || empty($otpCode)) {
            $error = "❌ Verification failed. Email and OTP are required.";
        } elseif (!is_numeric($otpCode) || strlen($otpCode) != 6) {
            $error = "❌ Invalid OTP format. Please enter the 6-digit code.";
        } else {
            if ($accountObj->verifyAccount($otpEmail, $otpCode)) {
                $error = "✅ Success! Your account has been verified. You may now log in.";
                $showVerificationForm = false;
            } else {
                $error = "⚠️ Verification failed. The Email or OTP is invalid, expired, or your account is already active.";
                $showVerificationForm = true;
                $verificationEmail = $otpEmail;
            }
        }
    } else {
        $email = trim(htmlspecialchars($_POST["email"]));
        $password = $_POST["password"];

        if (empty($email) || empty($password)) {
            $error = "Both email and password are required.";
        } else {
            $accountObj->email = $email;
            $accountObj->password = $password;

            $user = $accountObj->login();

            if ($user && is_array($user)) {

                if (strpos($user['email'], '@clearance.local') !== false) {
                     $error = "❌ Account pending setup. Please set your official email and permanent password.";
                     $showSetupForm = true;
                     $setupEmail = $user['email'];
                } elseif (isset($user['is_verified']) && $user['is_verified'] == 0) {
                    $verification_otp = $accountObj->generateOtp();
                    $update_sql = "UPDATE account SET verification_token = :otp WHERE email = :email AND is_verified = 0";
                    $update_stmt = $accountObj->getConnection()->prepare($update_sql);
                    $update_stmt->bindParam(':otp', $verification_otp);
                    $update_stmt->bindParam(':email', $user['email']);
                    $update_stmt->execute();
                    Notification::sendVerificationEmail($user['email'], $verification_otp);

                    $error = "❌ Account not yet verified. An OTP has been sent to your email. Please enter the code below to continue.";
                    $showVerificationForm = true;
                    $verificationEmail = $email;
                } else {
                    $_SESSION['account_id'] = $user['account_id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['ref_id'] = $accountObj->ref_id;

                    switch ($user['role']) {
                        case 'student':
                            header("Location: student/dashboard.php");
                            break;
                        case 'faculty':
                            header("Location: faculty/dashboard.php");
                            break;
                        case 'organization':
                            header("Location: organization/dashboard.php");
                            break;
                        case 'admin':
                            header("Location: admin/dashboard.php");
                            break;
                        default:
                            $error = "Invalid role assigned.";
                    }
                    exit;
                }
            } else {
                $error = "❌ Invalid email or password.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS Clearance - Login</title>
    <link rel="stylesheet" href="assets/css/login_style.css">
</head>
<body>

    <div class="login-container">

        <div class="logo-container">
            <img src="assets/img/wmsu_logo.png" alt="WMSU Logo">
            <img src="assets/img/ccs_logo.png" alt="CCS Logo">
        </div>

        <h2>CCS Clearance System</h2>

        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($showSetupForm): ?>
            <form action="index.php" method="POST" novalidate>
                <input type="hidden" name="placeholder_email" value="<?= htmlspecialchars($setupEmail) ?>">
                <h3>Phase 2: Account Setup</h3>
                <p style="color:white; font-size:0.9em; margin:10px 0;">Set your official email address and a permanent password. An OTP will be sent for final verification.</p>
                <div class="form-group">
                    <label for="new_email">Official Email:</label>
                    <input type="email" id="new_email" name="new_email" required placeholder="your.name@official.com">
                </div>
                <div class="form-group">
                    <label for="new_password">New Password:</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <input type="hidden" name="setup_account" value="1">
                <button type="submit" class="login-button">Finalize Setup & Send OTP</button>
            </form>

        <?php elseif ($showVerificationForm): ?>
            <form action="index.php" method="POST" novalidate>
                <h3>Account Verification</h3>
                <div class="form-group">
                    <label for="otp_email">Email:</label>
                    <input type="email" id="otp_email" name="otp_email" value="<?= htmlspecialchars($verificationEmail) ?>" readonly required>
                </div>
                <div class="form-group">
                    <label for="otp_code">6-Digit OTP from Email:</label>
                    <input type="text" id="otp_code" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="123456">
                </div>
                <input type="hidden" name="verify_otp" value="1">
                <button type="submit" class="login-button">Verify Account</button>
            </form>
            <div class="footer-links" style="justify-content: center; margin-top: 10px;">
                <p style="font-size: 0.8rem; color: white;">If you did not receive a code, please ensure your login email and password are correct, and try logging in again to resend the code.</p>
            </div>
        <?php else: ?>
            <form action="index.php" method="POST" novalidate>
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="login-button">Login</button>
            </form>
        <?php endif; ?>

        <div class="footer-links">
            <a href="registration/student.php">Create an Account</a>
            <a href="forgot_password.php">Forgot Password?</a>
        </div>
    </div>

</body>
</html>