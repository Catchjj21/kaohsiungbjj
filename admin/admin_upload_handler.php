<?php
// Include the database configuration file FIRST for consistency
require_once "../db_config.php";

// Initialize the session
session_start();

// Check if the user is logged in and is an admin or coach
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
    exit;
}

// The target directory for uploads.
$upload_dir = "../uploads/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Get the user ID from the POST data (sent from the form)
$user_id_to_update = $_POST['user_id'] ?? null;

if (!$user_id_to_update) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "User ID not provided."]);
    exit;
}

// Check if a file was uploaded
if (isset($_FILES['cropped_image']) && $_FILES['cropped_image']['error'] == 0) {
    $image = $_FILES['cropped_image'];

    // --- File Validation ---
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($image['type'], $allowed_types)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid file type. Please upload a JPG, PNG, or GIF."]);
        exit;
    }

    // Generate a unique filename
    $extension = pathinfo($image['name'], PATHINFO_EXTENSION);
    $unique_filename = 'user_' . $user_id_to_update . '_' . time() . '.' . $extension;
    $destination = $upload_dir . $unique_filename;
    $db_path = "uploads/" . $unique_filename; // Path to store in DB

    // Move the uploaded file
    if (move_uploaded_file($image['tmp_name'], $destination)) {
        // --- Update Database ---
        $sql = "UPDATE users SET profile_picture_url = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "si", $db_path, $user_id_to_update);
            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(["status" => "success", "message" => "Profile picture updated."]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Failed to update database."]);
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to save the uploaded file."]);
    }
} else {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "No file was uploaded or an error occurred."]);
}

mysqli_close($link);
?>
