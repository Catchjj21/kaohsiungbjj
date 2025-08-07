<?php

// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Set content type to JSON
header('Content-Type: application/json');

// IMPORTANT: Adjust this path if your db_config.php is not in the same directory
// as this upload_profile_picture.php file.
// If both signup_handler.php and upload_profile_picture.php are in the same folder
// as db_config.php, then "db_config.php" is correct.
require_once "db_config.php"; 

// Check if user_id and file are provided
if (!isset($_POST['user_id']) || !isset($_FILES['cropped_image'])) {
    http_response_code(400); // Bad Request
    echo json_encode(["success" => false, "message" => "Missing user ID or image file."]);
    exit;
}

$user_id = $_POST['user_id'];
$image = $_FILES['cropped_image'];

// --- File Validation ---
if ($image['error'] !== UPLOAD_ERR_OK) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error during file upload: " . $image['error']]); // Added error code for debugging
    exit;
}

$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
if (!in_array($image['type'], $allowed_types)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid file type. Please upload a JPG, PNG, or GIF."]);
    exit;
}

// --- File Handling ---
// Use an absolute server path for reliability. $_SERVER['DOCUMENT_ROOT'] points to your public_html or htdocs folder.
$upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/user/'; 
if (!is_dir($upload_dir)) {
    // The 'true' parameter makes this recursive, creating parent directories if needed.
    if (!mkdir($upload_dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to create upload directory. Check permissions."]);
        exit;
    }
}

// Generate a unique filename to prevent overwriting
// Using user_id, a timestamp, and a random string for better uniqueness
$filename = $user_id . '_' . time() . '_' . uniqid() . '.jpg';
$destination = $upload_dir . $filename;

// The URL path stored in the database remains relative to the web root
$url_path = 'uploads/user/' . $filename; 

// Move the uploaded file
if (move_uploaded_file($image['tmp_name'], $destination)) {
    // --- Database Update ---
    $sql = "UPDATE users SET profile_picture_url = ? WHERE id = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "si", $url_path, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(["success" => true, "message" => "Profile picture updated successfully.", "new_url" => $url_path]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error: Could not update profile picture URL."]);
        }
        mysqli_stmt_close($stmt);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error: Could not prepare statement."]);
    }
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Could not save the uploaded file. Check directory permissions or file size limits."]);
}

mysqli_close($link);
?>
