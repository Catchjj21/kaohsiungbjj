<?php

// It's good practice to start output buffering at the very top.
// This captures any accidental output (like warnings or whitespace from included files)
// and allows you to clean it before sending your JSON response.
ob_start();

// Enable error reporting for debugging. On a live production server,
// you should log errors to a file instead of displaying them.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// --- Main Response Array ---
// We'll build our response in this array and encode it once at the end.
$response = ["success" => false, "message" => "An unknown error occurred."];

// Set the final content type. This header will be sent when the script finishes.
header('Content-Type: application/json');

// --- Database Connection ---
// IMPORTANT: Ensure your 'db_config.php' file does NOT output anything.
// It should only define connection variables or create the $link object.
// Any whitespace, HTML, or echo statements in that file will break the JSON response.
require_once "db_config.php";

// Check for a database connection error after including the config.
if (!$link) {
    http_response_code(500);
    $response["message"] = "Database connection failed. Check db_config.php.";
    // Clean any output buffer content (like potential connection errors)
    ob_end_clean();
    echo json_encode($response);
    exit;
}

// --- Input Validation ---
if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
    http_response_code(400); // Bad Request
    $response["message"] = "User ID is missing or empty.";
} elseif (!isset($_FILES['cropped_image'])) {
    http_response_code(400); // Bad Request
    $response["message"] = "No image file was received.";
} elseif ($_FILES['cropped_image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(500);
    $response["message"] = "Error during file upload. Code: " . $_FILES['cropped_image']['error'];
} else {
    // --- File Validation ---
    $image = $_FILES['cropped_image'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $file_type = mime_content_type($image['tmp_name']); // More reliable than $_FILES['type']

    if (!in_array($file_type, $allowed_types)) {
        http_response_code(400);
        $response["message"] = "Invalid file type. Please upload a JPG, PNG, or GIF.";
    } else {
        // All initial checks passed, proceed with file handling.
        $user_id = $_POST['user_id'];

        // --- File Handling ---
        // Use an absolute server path for reliability.
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/user/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                http_response_code(500);
                $response["message"] = "Failed to create upload directory. Check server permissions.";
            }
        }

        if (is_dir($upload_dir)) {
            // Generate a unique filename. Using the extension from the reliable mime type.
            $extension = str_replace('image/', '', $file_type);
            $filename = $user_id . '_' . time() . '_' . uniqid() . '.' . $extension;
            $destination = $upload_dir . $filename;
            $url_path = 'uploads/user/' . $filename;

            if (move_uploaded_file($image['tmp_name'], $destination)) {
                // --- Database Update ---
                $sql = "UPDATE users SET profile_picture_url = ? WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "si", $url_path, $user_id);
                    if (mysqli_stmt_execute($stmt)) {
                        http_response_code(200); // OK
                        $response = [
                            "success" => true,
                            "message" => "Profile picture updated successfully.",
                            "new_url" => $url_path
                        ];
                    } else {
                        http_response_code(500);
                        $response["message"] = "Database error: Could not execute update.";
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    http_response_code(500);
                    $response["message"] = "Database error: Could not prepare statement.";
                }
            } else {
                http_response_code(500);
                $response["message"] = "Could not save the uploaded file. Check directory permissions.";
            }
        }
    }
}

mysqli_close($link);

// --- Final Output ---
// Clean (erase) the output buffer and stop buffering
ob_end_clean();

// Echo the final JSON response
echo json_encode($response);

// It's best practice to omit the closing PHP tag in files that contain only PHP.
// This prevents accidental whitespace from being sent.
