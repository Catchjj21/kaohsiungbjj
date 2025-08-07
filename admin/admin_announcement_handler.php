<?php
session_start();
// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in and has appropriate role
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once "../db_config.php"; // Adjust path as needed for your db_config.php

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title_en = trim($_POST['title_en'] ?? '');
    $title_zh = trim($_POST['title_zh'] ?? '');
    $content_en = trim($_POST['content_en'] ?? '');
    $content_zh = trim($_POST['content_zh'] ?? '');

    if (empty($title_en) || empty($title_zh) || empty($content_en) || empty($content_zh)) {
        echo json_encode(['success' => false, 'message' => 'All announcement fields are required.']);
        exit;
    }

    // Start a transaction
    mysqli_begin_transaction($link);

    try {
        // Deactivate all previous announcements (optional, but ensures only one active announcement)
        $sql_deactivate_old = "UPDATE announcements SET is_active = FALSE";
        if (!mysqli_query($link, $sql_deactivate_old)) {
            throw new Exception("Error deactivating old announcements: " . mysqli_error($link));
        }

        // Insert the new announcement
        $sql_insert_announcement = "INSERT INTO announcements (title_en, title_zh, content_en, content_zh, is_active) VALUES (?, ?, ?, ?, TRUE)";
        if ($stmt_insert = mysqli_prepare($link, $sql_insert_announcement)) {
            mysqli_stmt_bind_param($stmt_insert, "ssss", $title_en, $title_zh, $content_en, $content_zh);
            if (!mysqli_stmt_execute($stmt_insert)) {
                throw new Exception("Error inserting new announcement: " . mysqli_error($link));
            }
            $new_announcement_id = mysqli_insert_id($link);
            mysqli_stmt_close($stmt_insert);

            // Update all users' last_announcement_viewed_id to 0
            // This ensures everyone sees the new announcement once.
            $sql_reset_user_views = "UPDATE users SET last_announcement_viewed_id = 0";
            if (!mysqli_query($link, $sql_reset_user_views)) {
                throw new Exception("Error resetting user announcement views: " . mysqli_error($link));
            }

            mysqli_commit($link); // Commit the transaction
            echo json_encode(['success' => true, 'message' => 'Announcement published successfully! All members will see it on their next login.']);

        } else {
            throw new Exception("Error preparing announcement insert statement: " . mysqli_error($link));
        }
    } catch (Exception $e) {
        mysqli_rollback($link); // Rollback on error
        error_log("Announcement creation error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to publish announcement: ' . $e->getMessage()]);
    } finally {
        mysqli_close($link);
    }

} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
