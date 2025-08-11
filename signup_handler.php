<?php

// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set content type to JSON
header('Content-Type: application/json');

// THE FIX: Include the database config FIRST and use the correct path.
// If db_config.php is in the same directory as signup_handler.php, use "db_config.php".
// If it's in a parent directory of public_html, then "../db_config.php" would be correct IF signup_handler.php was in a SUBDIRECTORY of public_html.
// Given the path in the error, signup_handler.php is in public_html, so "db_config.php" is likely correct.
require_once "db_config.php"; 

// Now that the settings are loaded, start the session.
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';


// --- reCAPTCHA Verification ---
$recaptcha_secret = "6LeKvJErAAAAAEpK0ILOC8S_HiHkRsiqf3b8_X3k";
$recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
$response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=".$recaptcha_secret."&response=".$recaptcha_response);
$response_keys = json_decode($response, true);

if(intval($response_keys["success"]) !== 1) {
    echo json_encode(["success" => false, "message" => "reCAPTCHA verification failed. Please go back and try again."]);
    exit;
}

// --- Form Processing ---
if($_SERVER["REQUEST_METHOD"] == "POST"){

    // Initialize variables and errors
    $first_name = $last_name = $email = $password = $dob = $member_type = "";
    $line_id = $address = $chinese_name = "";
    $waiver_acceptance = 0; // Default to 0 (false)

    $first_name_err = $last_name_err = $email_err = $password_err = $dob_err = $member_type_err = "";
    $line_id_err = $address_err = $chinese_name_err = $waiver_acceptance_err = "";

    $lang = isset($_POST['lang']) && $_POST['lang'] === 'zh' ? 'zh' : 'en';
    
    // Validate all fields
    if(empty(trim($_POST["first_name"]))) $first_name_err = "Please enter a first name.";
    else $first_name = trim($_POST["first_name"]);

    if(empty(trim($_POST["last_name"]))) $last_name_err = "Please enter a last name.";
    else $last_name = trim($_POST["last_name"]);

    if(empty(trim($_POST["dob"]))) $dob_err = "Please enter your date of birth.";
    else $dob = trim($_POST["dob"]);

    if(empty(trim($_POST["member_type"]))) $member_type_err = "Please select a membership type.";
    else $member_type = trim($_POST["member_type"]);

    if(empty(trim($_POST["email"]))) $email_err = "Please enter an email.";
    else $email = trim($_POST["email"]);

    if(empty(trim($_POST["password"]))) $password_err = "Please enter a password.";
    elseif(strlen(trim($_POST["password"])) < 6) $password_err = "Password must have at least 6 characters.";
    else $password = trim($_POST["password"]);

    // New fields (optional, so no 'empty' check for error, just retrieve)
    $line_id = trim($_POST["line_id"] ?? '');
    $address = trim($_POST["address"] ?? '');
    $chinese_name = trim($_POST["chinese_name"] ?? '');

    // Waiver acceptance (essential)
    if (!isset($_POST["waiver_acceptance"]) || $_POST["waiver_acceptance"] !== 'on') {
        $waiver_acceptance_err = "You must accept the waiver to sign up.";
    } else {
        $waiver_acceptance = 1; // Set to 1 (true) if checked
    }

    // Check if email is already taken
    if(empty($email_err)) {
        $sql = "SELECT id FROM users WHERE email = ?";
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            $param_email = $email;
            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                if(mysqli_stmt_num_rows($stmt) == 1){
                    $email_err = "This email is already taken.";
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Collect all errors
    $errors = array_filter([$first_name_err, $last_name_err, $email_err, $password_err, $dob_err, $member_type_err, $waiver_acceptance_err]);

    // Proceed if no errors
    if(empty($errors)){
        
        // Get the next available old_card number
        $sql_get_next_card = "SELECT MAX(CAST(old_card AS UNSIGNED)) as max_card FROM users WHERE role = 'member' AND old_card IS NOT NULL AND old_card != '' AND old_card REGEXP '^[0-9]+$'";
        $result_next_card = mysqli_query($link, $sql_get_next_card);
        $row_next_card = mysqli_fetch_assoc($result_next_card);
        $next_card_number = ($row_next_card['max_card'] ?: 0) + 1;
        
        $sql = "INSERT INTO users (first_name, last_name, email, dob, member_type, password_hash, verification_token, line_id, address, chinese_name, waiver_acceptance, old_card) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
        if($stmt = mysqli_prepare($link, $sql)){
            $verification_token = bin2hex(random_bytes(50));
            mysqli_stmt_bind_param($stmt, "sssssssssssi", $param_first_name, $param_last_name, $param_email, $param_dob, $param_member_type, $param_password, $param_token, $param_line_id, $param_address, $param_chinese_name, $param_waiver_acceptance, $param_old_card);
            
            $param_first_name = $first_name;
            $param_last_name = $last_name;
            $param_email = $email;
            $param_dob = $dob;
            $param_member_type = $member_type;
            $param_password = password_hash($password, PASSWORD_DEFAULT);
            $param_token = $verification_token;
            $param_line_id = $line_id;
            $param_address = $address;
            $param_chinese_name = $chinese_name;
            $param_waiver_acceptance = $waiver_acceptance; // Will be 1 (true) if accepted
            $param_old_card = $next_card_number;
            
            if(mysqli_stmt_execute($stmt)){
                // Get the ID of the new user
                $new_user_id = mysqli_insert_id($link);

                // --- Send Notification Email to Admin ---
                try {
                    $admin_mail = new PHPMailer(true);
                    $admin_completion_link = "admin/admin_complete_registration.php?user_id=" . $new_user_id;

                    // Server settings
                    $admin_mail->isSMTP();
                    $admin_mail->Host        = 'mail.stackmail.com';
                    $admin_mail->SMTPAuth    = true;
                    $admin_mail->Username    = 'catchjiujitsu@kaohsiungbjj.com';
                    $admin_mail->Password    = 'Bigtest12';
                    $admin_mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $admin_mail->Port        = 465;
                    $admin_mail->CharSet     = 'UTF-8';

                    // Recipients
                    $admin_mail->setFrom('catchjiujitsu@kaohsiungbjj.com', 'System Notification');
                    $admin_mail->addAddress('catchjiujitsu@gmail.com', 'Admin');

                    // Content
                    $admin_mail->isHTML(true);
                    $admin_mail->Subject = 'DEBUG - ID: ' . $new_user_id . ' - New Signup: ' . htmlspecialchars($first_name);
                    $admin_mail->Body    = "
                        <h2>New Member Has Signed Up</h2>
                        <p>A new user has created an account and is awaiting registration completion.</p>
                        <h3>Member Details:</h3>
                        <ul>
                            <li><strong>Name:</strong> " . htmlspecialchars($first_name) . " " . htmlspecialchars($last_name) . "</li>
                            <li><strong>Email:</strong> " . htmlspecialchars($email) . "</li>
                            <li><strong>Date of Birth:</strong> " . htmlspecialchars($dob) . "</li>
                            <li><strong>Member Type:</strong> " . htmlspecialchars($member_type) . "</li>
                            <li><strong>Line ID:</strong> " . htmlspecialchars($line_id) . "</li>
                            <li><strong>Address:</strong> " . htmlspecialchars($address) . "</li>
                            <li><strong>Chinese Name:</strong> " . htmlspecialchars($chinese_name) . "</li>
                            <li><strong>Waiver Accepted:</strong> " . ($waiver_acceptance ? 'Yes' : 'No') . "</li>
                        </ul>
                        <h3>Action Required:</h3>
                        <p>Please click the link below to complete their registration and set up their membership plan.</p>
                        <p><a href='{$admin_completion_link}' style='background-color: #3b82f6; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Complete Registration</a></p>
                    ";
                    
                    $admin_mail->send();
                } catch (Exception $e) {
                    // Log error if admin email fails, but don't stop the process for the user
                    error_log("Admin notification email could not be sent. Mailer Error: {$admin_mail->ErrorInfo}");
                }


                // --- Send Professional HTML Verification Email to User ---
                $mail = new PHPMailer(true);
                $full_name = $first_name . " " . $last_name;
                $verification_link = "verify.php?email=" . urlencode($email) . "&token=" . urlencode($verification_token);

                try {
                    // Server settings
                    $mail->isSMTP();
                    $mail->Host        = 'mail.stackmail.com';
                    $mail->SMTPAuth    = true;
                    $mail->Username    = 'catchjiujitsu@kaohsiungbjj.com';
                    $mail->Password    = 'Bigtest12';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port        = 465;
                    $mail->CharSet     = 'UTF-8';

                    // Recipients
                    $mail->setFrom('catchjiujitsu@kaohsiungbjj.com', 'Catch Jiu Jitsu');
                    $mail->addAddress($email, $full_name);

                    // Embed logo image
                    $mail->addEmbeddedImage('logo.png', 'logo_cid');

                    // Content
                    $mail->isHTML(true);
                    
                    // English Email Template
                    $html_body_en = "
                        <!DOCTYPE html>
                        <html lang='en'>
                        <head><meta charset='UTF-8'></head>
                        <body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;'>
                            <table align='center' border='0' cellpadding='0' cellspacing='0' width='600' style='border-collapse: collapse; margin-top: 20px; background-color: #ffffff;'>
                                <tr>
                                    <td align='center' bgcolor='#ffffff' style='padding: 40px 0 30px 0; border-bottom: 1px solid #eeeeee;'>
                                        <img src='cid:logo_cid' alt='Catch Jiu Jitsu Logo' width='100' style='display: block;' />
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding: 40px 30px 40px 30px;'>
                                        <h1 style='font-size: 24px; margin: 0;'>Please Verify Your Account</h1>
                                        <p style='margin: 20px 0 30px 0; font-size: 16px; line-height: 1.5;'>
                                            Thank you for signing up, " . htmlspecialchars($full_name) . ". Please click the button below to activate your account.
                                        </p>
                                        <table border='0' cellpadding='0' cellspacing='0' width='100%'>
                                            <tr>
                                                <td align='center'>
                                                    <a href='{$verification_link}' style='background-color: #8b5cf6; color: #ffffff; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>Activate My Account</a>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style='margin: 30px 0 0 0; font-size: 14px;'>If you did not create an account, no further action is required.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td bgcolor='#f4f4f4' style='padding: 30px 30px 30px 30px; text-align: center; color: #888888; font-size: 12px;'>
                                        &copy; " . date("Y") . " Catch Jiu Jitsu. All Rights Reserved.
                                    </td>
                                </tr>
                            </table>
                        </body>
                        </html>";

                    // Chinese Email Template
                    $html_body_zh = "
                        <!DOCTYPE html>
                        <html lang='zh'>
                        <head><meta charset='UTF-8'></head>
                        <body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;'>
                            <table align='center' border='0' cellpadding='0' cellspacing='0' width='600' style='border-collapse: collapse; margin-top: 20px; background-color: #ffffff;'>
                                <tr>
                                    <td align='center' bgcolor='#ffffff' style='padding: 40px 0 30px 0; border-bottom: 1px solid #eeeeee;'>
                                        <img src='cid:logo_cid' alt='Catch 柔術 Logo' width='100' style='display: block;' />
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding: 40px 30px 40px 30px;'>
                                        <h1 style='font-size: 24px; margin: 0;'>請驗證您的帳戶</h1>
                                        <p style='margin: 20px 0 30px 0; font-size: 16px; line-height: 1.5;'>
                                            " . htmlspecialchars($full_name) . " 您好，感謝您的註冊。請點擊下方按鈕以啟用您的帳戶。
                                        </p>
                                        <table border='0' cellpadding='0' cellspacing='0' width='100%'>
                                            <tr>
                                                <td align='center'>
                                                    <a href='{$verification_link}' style='background-color: #8b5cf6; color: #ffffff; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>啟用我的帳戶</a>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style='margin: 30px 0 0 0; font-size: 14px;'>如果您未建立此帳戶，則無需採取任何進一步操作。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td bgcolor='#f4f4f4' style='padding: 30px 30px 30px 30px; text-align: center; color: #888888; font-size: 12px;'>
                                        &copy; " . date("Y") . " Catch 柔術. 版權所有.
                                    </td>
                                </tr>
                            </table>
                        </body>
                        </html>";

                    if ($lang === 'zh') {
                        $mail->Subject = '請驗證您的 Catch 柔術帳戶';
                        $mail->Body    = $html_body_zh;
                    } else {
                        $mail->Subject = 'Please Verify Your Catch Jiu Jitsu Account';
                        $mail->Body    = $html_body_en;
                    }

                    $mail->send();
                    
                    // Respond with success and user_id for the frontend to handle image upload
                    echo json_encode(["success" => true, "message" => "User registered successfully! Please check your email for verification.", "user_id" => $new_user_id]);
                    exit();

                } catch (Exception $e) {
                    error_log("Verification email to user could not be sent. Mailer Error: {$mail->ErrorInfo}");
                    echo json_encode(["success" => false, "message" => "Something went wrong. We could not send a verification email."]);
                    exit();
                }

            } else {
                error_log("User insertion SQL execution error: " . mysqli_error($link));
                echo json_encode(["success" => false, "message" => "Something went wrong during registration. Please try again later."]);
                exit();
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log("SQL prepare statement error: " . mysqli_error($link));
            echo json_encode(["success" => false, "message" => "Database error during registration setup. Please try again later."]);
            exit();
        }
    } else {
        // Return all validation errors
        echo json_encode(["success" => false, "message" => implode(" ", $errors)]);
        exit();
    }
    mysqli_close($link);
} else {
    // Not a POST request
    echo json_encode(["success" => false, "message" => "Invalid request method."]);
    exit();
}
