<?php
// Start output buffering to prevent unwanted output from breaking the JSON response
ob_start();

// Disable error display for a cleaner JSON response
ini_set('display_errors', 'Off');
error_reporting(E_ALL); // Still log errors, but don't display them

// IMPORTANT: Include the database configuration file BEFORE session_start()
require_once "../db_config.php";

session_start();

// Check if user is logged in and has appropriate role
// FIX: Changed "loggedin" to "admin_loggedin" for consistency
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || ($_SESSION["role"] !== 'admin' && $_SESSION["role"] !== 'coach')){
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$class_id = $_POST['class_id'] ?? null;
$name_en = trim($_POST['name_en'] ?? '');
$name_zh = trim($_POST['name_zh'] ?? '');
$day_of_week = $_POST['day_of_week'] ?? '';
$start_time = $_POST['start_time'] ?? '';
$end_time = $_POST['end_time'] ?? '';
$coach_id = $_POST['coach_id'] ?? null;
$capacity = $_POST['capacity'] ?? null; // Retrieve capacity
$age = $_POST['age'] ?? ''; // Retrieve age
$is_active = isset($_POST['is_active']) ? 1 : 0; // Checkbox value

// Ensure $link is available from db_config.php
if (!isset($link) || !$link) {
    echo json_encode(['success' => false, 'message' => 'Database connection not established.']);
    exit;
}

try {
    if ($action === 'add') {
        if (empty($name_en) || empty($name_zh) || empty($day_of_week) || empty($start_time) || empty($end_time) || empty($capacity) || empty($age)) {
            throw new Exception("All class fields (English name, Chinese name, day, start time, end time, capacity, and age) are required for adding.");
        }

        $sql = "INSERT INTO classes (name, name_zh, day_of_week, start_time, end_time, coach_id, capacity, is_active, age) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssiiis", $name_en, $name_zh, $day_of_week, $start_time, $end_time, $coach_id, $capacity, $is_active, $age);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error adding class: " . mysqli_error($link));
            }
            // Clean the output buffer before echoing the JSON
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Class added successfully!']);
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Error preparing add class statement: " . mysqli_error($link));
        }
    } elseif ($action === 'edit') {
        if (empty($class_id) || empty($name_en) || empty($name_zh) || empty($day_of_week) || empty($start_time) || empty($end_time) || empty($capacity) || empty($age)) {
            throw new Exception("All class fields (ID, English name, Chinese name, day, start time, end time, capacity, and age) are required for editing.");
        }

        $sql = "UPDATE classes SET name = ?, name_zh = ?, day_of_week = ?, start_time = ?, end_time = ?, coach_id = ?, capacity = ?, is_active = ?, age = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssiiisi", $name_en, $name_zh, $day_of_week, $start_time, $end_time, $coach_id, $capacity, $is_active, $age, $class_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error updating class: " . mysqli_error($link));
            }
            // Clean the output buffer before echoing the JSON
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Class updated successfully!']);
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Error preparing update class statement: " . mysqli_error($link));
        }
    } elseif ($action === 'delete') {
        if (empty($class_id)) {
            throw new Exception("Class ID is required for deletion.");
        }
        $sql = "DELETE FROM classes WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error deleting class: " . mysqli_error($link));
            }
            // Clean the output buffer before echoing the JSON
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Class deleted successfully!']);
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Error preparing delete class statement: " . mysqli_error($link));
        }
    } else {
        throw new Exception("Invalid action specified.");
    }
} catch (Exception $e) {
    error_log("Manage Classes Handler Error: " . $e->getMessage());
    // Clean the output buffer on error, then echo JSON
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if (isset($link) && is_object($link) && mysqli_ping($link)) {
        mysqli_close($link);
    }
}
?>