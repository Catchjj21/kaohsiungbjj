<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "../db_config.php";
session_start();

// Security check
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    // If not logged in, but requesting an image upload, deny and return JSON
    if (isset($_POST['action']) && $_POST['action'] === 'upload_image') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit;
    }
    header("location: ../admin_login.html");
    exit;
}

// Check for image upload first, as it's an AJAX call from Quill
if (isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    header('Content-Type: application/json');

    // Define the upload directory relative to the script's location
    $upload_dir = __DIR__ . '/../uploads/content_images/';
    $relative_dir = 'uploads/content_images/';

    // Check if the directory exists and is writable
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0775, true)) {
            echo json_encode(['success' => false, 'message' => 'Failed to create upload directory. Check permissions.']);
            exit;
        }
    } elseif (!is_writable($upload_dir)) {
        echo json_encode(['success' => false, 'message' => 'Upload directory is not writable. Please change permissions (e.g., to 775 or 777).']);
        exit;
    }

    // Process the uploaded file
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['image']['tmp_name'];
        $file_info = pathinfo($_FILES['image']['name']);
        $file_extension = $file_info['extension'];
        
        // Generate a cryptographically secure, unique filename
        $new_file_name = bin2hex(random_bytes(16)) . '.' . $file_extension;
        $dest_path = $upload_dir . $new_file_name;

        if (move_uploaded_file($file_tmp_path, $dest_path)) {
            // Success: Return the public URL of the uploaded image
            echo json_encode(['success' => true, 'url' => $relative_dir . $new_file_name]);
        } else {
            // Failure: Return a descriptive error
            echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file. Check directory permissions.']);
        }
    } else {
        // Failure: Return a descriptive error based on the upload error code
        $error_message = 'No file uploaded or an unknown error occurred.';
        if (isset($_FILES['image'])) {
            switch ($_FILES['image']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error_message = 'The uploaded file is too large. Please check your php.ini settings for upload_max_filesize.';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error_message = 'The uploaded file was only partially uploaded.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_message = 'No file was uploaded.';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $error_message = 'Missing a temporary folder for uploads. Check your server\'s PHP configuration.';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $error_message = 'Failed to write file to disk. Check permissions.';
                    break;
            }
        }
        echo json_encode(['success' => false, 'message' => $error_message]);
    }
    exit;
}

// Handle regular form submissions (create, update, delete)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $type = trim($_POST['type']);
            $title = trim($_POST['title']);
            $title_zh = trim($_POST['title_zh']);
            $content = $_POST['content']; // HTML content
            $content_zh = $_POST['content_zh']; // HTML content
            $publish_date = trim($_POST['publish_date']);

            $sql = "INSERT INTO site_content (type, title, title_zh, content, content_zh, publish_date) VALUES (?, ?, ?, ?, ?, ?)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "ssssss", $type, $title, $title_zh, $content, $content_zh, $publish_date);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            break;

        case 'update':
            $post_id = $_POST['post_id'];
            $type = trim($_POST['type']);
            $title = trim($_POST['title']);
            $title_zh = trim($_POST['title_zh']);
            $content = $_POST['content']; // HTML content
            $content_zh = $_POST['content_zh']; // HTML content
            $publish_date = trim($_POST['publish_date']);

            $sql = "UPDATE site_content SET type=?, title=?, title_zh=?, content=?, content_zh=?, publish_date=? WHERE id=?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "ssssssi", $type, $title, $title_zh, $content, $content_zh, $publish_date, $post_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            break;

        case 'delete':
            $post_id = $_POST['post_id'];

            // Optional: You could add logic here to also delete associated image files from the server
            $sql = "DELETE FROM site_content WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "i", $post_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            break;
    }
    mysqli_close($link);
    header("location: manage_content.php");
    exit;
}
