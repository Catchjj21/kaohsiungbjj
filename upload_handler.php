<?php
// Initialize the session
session_start();

// Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
    exit;
}

// Include the database configuration file
require_once "db_config.php";

// The target directory for uploads.
// Make sure this directory exists and is writable by the server.
$upload_dir = "uploads/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Check if a file was uploaded
if (isset($_FILES['cropped_image']) && $_FILES['cropped_image']['error'] == 0) {
    $image = $_FILES['cropped_image'];
    $user_id = $_SESSION["id"];

    // --- File Validation ---
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($image['type'], $allowed_types)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid file type. Please upload a JPG, PNG, or GIF."]);
        exit;
    }

    // Generate a unique filename to prevent overwriting
    $extension = pathinfo($image['name'], PATHINFO_EXTENSION);
    $unique_filename = uniqid('user_' . $user_id . '_', true) . '.' . $extension;
    $destination = $upload_dir . $unique_filename;

    // Move the uploaded file to the destination
    if (move_uploaded_file($image['tmp_name'], $destination)) {
        // --- Update Database ---
        $sql = "UPDATE users SET profile_picture_url = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "si", $destination, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(["status" => "success", "message" => "Profile picture updated.", "filepath" => $destination]);
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
