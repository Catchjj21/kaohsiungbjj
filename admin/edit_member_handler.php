<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

// Disable error display and enable full error reporting for debugging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Function to delete the old profile picture file. Moved to a global scope for better practice.
function deleteOldProfilePicture($link, $user_id) {
    $sql_get_old_pic = "SELECT profile_picture_url FROM users WHERE id = ?";
    if($stmt_get_pic = mysqli_prepare($link, $sql_get_old_pic)){
        mysqli_stmt_bind_param($stmt_get_pic, "i", $user_id);
        if(mysqli_stmt_execute($stmt_get_pic)){
            mysqli_stmt_bind_result($stmt_get_pic, $old_url);
            if(mysqli_stmt_fetch($stmt_get_pic)){
                $old_file_path = __DIR__ . '/../' . $old_url;
                if (!empty($old_url) && file_exists($old_file_path) && is_writable($old_file_path) && strpos($old_url, 'placehold.co') === false) {
                    unlink($old_file_path);
                }
            }
        }
        mysqli_stmt_close($stmt_get_pic);
    }
}

// THE FIX: Include the database config FIRST.
require_once "../db_config.php";

// Now that the settings are loaded, start the session.
session_start();

// FIX: Changed $_SESSION["loggedin"] to $_SESSION["admin_loggedin"] and role check to in_array for consistency
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    header("location: ../admin_login.html");
    exit;
}

if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Retrieve all form fields
    $user_id = $_POST['user_id'];
    $membership_id = $_POST['membership_id'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone_number = trim($_POST['phone_number']);
    
    $member_type = trim($_POST['member_type']);
    $role = trim($_POST['role']);
    
    $belt_color = trim($_POST['belt_color']);
    $dob = trim($_POST['dob']);
    $line_id = trim($_POST['line_id']);
    $address = trim($_POST['address']);
    $chinese_name = trim($_POST['chinese_name']);
    
    $default_language = trim($_POST['default_language']);
    $old_card = trim($_POST['old_card']);
    
    // FIX: The form now sends the plan ID, not the name.
    $membership_plan_id = trim($_POST['membership_plan_id'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $class_credits = !empty($_POST['class_credits']) ? (int)$_POST['class_credits'] : NULL;
    $cropped_image_data = $_POST['cropped_image_data'] ?? null;
    $profile_picture_url = null; // Initialize the variable for the new profile picture URL

    // Process the cropped image data if it exists
    if (!empty($cropped_image_data)) {
        deleteOldProfilePicture($link, $user_id);
        $upload_dir = '../uploads/profile_pictures/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // FIX: Added robust checks for malformed image data to prevent fatal errors
        $data_parts = explode(';', $cropped_image_data);
        if (count($data_parts) < 2) {
            error_log("Invalid cropped image data format (semicolon missing).");
            // Graceful exit instead of a crash
            header("location: manage_members.php?error=image_data_invalid");
            exit;
        }
        $data_and_encoding = $data_parts[1];

        $encoded_parts = explode(',', $data_and_encoding);
        if (count($encoded_parts) < 2) {
            error_log("Invalid cropped image data format (comma missing).");
            // Graceful exit instead of a crash
            header("location: manage_members.php?error=image_data_invalid");
            exit;
        }
        $base64_data = $encoded_parts[1];

        $image_data = base64_decode($base64_data);
        
        if ($image_data === false) {
            error_log("Failed to base64 decode image data.");
            // Graceful exit instead of a crash
            header("location: manage_members.php?error=image_decode_failed");
            exit;
        }

        $filename = uniqid('profile_', true) . '.jpg';
        $file_path = $upload_dir . $filename;
        $relative_path = 'uploads/profile_pictures/' . $filename;

        if (file_put_contents($file_path, $image_data)) {
            $profile_picture_url = $relative_path;
        } else {
            error_log("Error saving the new profile picture to file: " . $file_path);
            echo "Error saving the new profile picture.";
            exit;
        }
    } else {
        $sql_get_old_pic = "SELECT profile_picture_url FROM users WHERE id = ?";
        if($stmt_get_pic = mysqli_prepare($link, $sql_get_old_pic)){
            mysqli_stmt_bind_param($stmt_get_pic, "i", $user_id);
            if(mysqli_stmt_execute($stmt_get_pic)){
                mysqli_stmt_bind_result($stmt_get_pic, $old_url);
                mysqli_stmt_fetch($stmt_get_pic);
                $profile_picture_url = $old_url;
            }
            mysqli_stmt_close($stmt_get_pic);
        }
    }

    $sql_update_user = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone_number = ?, member_type = ?, role = ?, belt_color = ?, dob = ?, line_id = ?, address = ?, chinese_name = ?, profile_picture_url = ?, default_language = ?, old_card = ? WHERE id = ?";
    if($stmt_user = mysqli_prepare($link, $sql_update_user)){
        mysqli_stmt_bind_param($stmt_user, "ssssssssssssssi", 
                               $first_name, $last_name, $email, $phone_number, $member_type,
                               $role, $belt_color, $dob, $line_id, $address, $chinese_name,
                               $profile_picture_url, $default_language, $old_card, $user_id);
        
        if(!mysqli_stmt_execute($stmt_user)){
            echo "Error updating user: " . mysqli_error($link);
            exit;
        }
        mysqli_stmt_close($stmt_user);
    } else {
        echo "Error preparing user update statement: " . mysqli_error($link);
        exit;
    }

    // NEW: Get the plan name based on the submitted ID
    $membership_type_plan = '';
    if (!empty($membership_plan_id)) {
        $sql_get_plan = "SELECT plan_name FROM membership_plans WHERE id = ?";
        if ($stmt_plan = mysqli_prepare($link, $sql_get_plan)) {
            mysqli_stmt_bind_param($stmt_plan, "i", $membership_plan_id);
            if (mysqli_stmt_execute($stmt_plan)) {
                $result_plan = mysqli_stmt_get_result($stmt_plan);
                if ($row_plan = mysqli_fetch_assoc($result_plan)) {
                    $membership_type_plan = $row_plan['plan_name'];
                }
            }
            mysqli_stmt_close($stmt_plan);
        }
    }

    // Handle membership details update, insertion, or deletion
    if (!empty($membership_plan_id) && !empty($start_date) && !empty($end_date)) { // FIX: Check against plan ID
        if (!empty($membership_id)) {
            $sql_membership = "UPDATE memberships SET membership_type = ?, start_date = ?, end_date = ?, class_credits = ? WHERE id = ?";
            if($stmt_membership = mysqli_prepare($link, $sql_membership)){
                mysqli_stmt_bind_param($stmt_membership, "sssii", $membership_type_plan, $start_date, $end_date, $class_credits, $membership_id);
                if(!mysqli_stmt_execute($stmt_membership)){
                    echo "Error updating membership: " . mysqli_error($link);
                    exit;
                }
                mysqli_stmt_close($stmt_membership);
            } else {
                echo "Error preparing membership update statement: " . mysqli_error($link);
                exit;
            }
        } else {
            $sql_membership = "INSERT INTO memberships (user_id, membership_type, start_date, end_date, class_credits, status) VALUES (?, ?, ?, ?, ?, 'active')";
            if($stmt_membership = mysqli_prepare($link, $sql_membership)){
                mysqli_stmt_bind_param($stmt_membership, "isssi", $user_id, $membership_type_plan, $start_date, $end_date, $class_credits);

                if(!mysqli_stmt_execute($stmt_membership)){
                    echo "Error inserting membership: " . mysqli_error($link);
                    exit;
                }
                mysqli_stmt_close($stmt_membership);
            } else {
                echo "Error preparing membership insert statement: " . mysqli_error($link);
                exit;
            }
        }
    } else {
        if(!empty($membership_id)) {
            $sql_delete_membership = "DELETE FROM memberships WHERE id = ?";
            if($stmt_delete = mysqli_prepare($link, $sql_delete_membership)){
                mysqli_stmt_bind_param($stmt_delete, "i", $membership_id);
                if(!mysqli_stmt_execute($stmt_delete)){
                    echo "Error deleting membership: " . mysqli_error($link);
                    exit;
                }
                mysqli_stmt_close($stmt_delete);
            } else {
                echo "Error preparing membership delete statement: " . mysqli_error($link);
                exit;
            }
        }
    }
    
    mysqli_close($link);

    header("location: manage_members.php");
    exit;
} else {
    header("location: manage_members.php");
    exit;
}
