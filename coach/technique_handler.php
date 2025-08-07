<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database configuration BEFORE starting the session.
require_once "../db_config.php";
session_start();

// --- SECURITY CHECK: Only admins or coaches can perform these actions ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: You do not have permission.']);
    exit;
}

// --- MAIN LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $coach_id = $_SESSION['id'];

    switch ($action) {
        case 'create':
        case 'update':
            $title = trim($_POST['title'] ?? '');
            $youtube_url = trim($_POST['youtube_url'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $tags_array = $_POST['tags'] ?? [];
            $tags = implode(',', $tags_array); // Convert array of tags to a comma-separated string
            $post_id = $_POST['post_id'] ?? null;

            if (empty($title) || empty($youtube_url)) {
                echo json_encode(['success' => false, 'message' => 'Title and YouTube URL are required.']);
                exit;
            }

            if ($action === 'create') {
                $sql = "INSERT INTO technique_of_the_week (coach_id, title, youtube_url, description, tags) VALUES (?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "issss", $coach_id, $title, $youtube_url, $description, $tags);
            } else { // Update
                $sql = "UPDATE technique_of_the_week SET title = ?, youtube_url = ?, description = ?, tags = ? WHERE id = ? AND coach_id = ?";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "ssssii", $title, $youtube_url, $description, $tags, $post_id, $coach_id);
            }

            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(['success' => true, 'message' => 'Technique saved successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error. Could not save technique.']);
            }
            mysqli_stmt_close($stmt);
            break;

        case 'delete':
            $post_id = $_POST['post_id'] ?? 0;
            if ($post_id > 0) {
                $sql = "DELETE FROM technique_of_the_week WHERE id = ? AND coach_id = ?";
                if ($_SESSION['role'] === 'admin') {
                    $sql = "DELETE FROM technique_of_the_week WHERE id = ?";
                }
                
                $stmt = mysqli_prepare($link, $sql);
                if ($_SESSION['role'] === 'admin') {
                    mysqli_stmt_bind_param($stmt, "i", $post_id);
                } else {
                    mysqli_stmt_bind_param($stmt, "ii", $post_id, $coach_id);
                }

                if (mysqli_stmt_execute($stmt)) {
                    if (mysqli_stmt_affected_rows($stmt) > 0) {
                        echo json_encode(['success' => true, 'message' => 'Technique deleted successfully.']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Could not delete or permission denied.']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error.']);
                }
                mysqli_stmt_close($stmt);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
            }
            break;
    }
    mysqli_close($link);
    exit;
}
?>
