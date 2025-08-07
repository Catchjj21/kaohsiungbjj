<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is an admin or coach.
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    header("location: ../admin_login.html");
    exit;
}

// Include the database configuration file
require_once "../db_config.php";

// Check if the form was submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    $action = $_POST['action'] ?? '';
    $class_id = $_POST['id'] ?? '';

    // --- DELETE ACTION ---
    if($action === 'delete' && !empty($class_id)){
        $sql = "DELETE FROM classes WHERE id = ?";
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } 
    // --- SAVE (UPDATE/INSERT) ACTION ---
    elseif ($action === 'save') {
        // Get form data
        $name = $_POST['name'];
        $name_zh = $_POST['name_zh'];
        $day_of_week = $_POST['day_of_week'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $coach_id = !empty($_POST['coach_id']) ? $_POST['coach_id'] : NULL;
        $capacity = $_POST['capacity'];
        $is_active = $_POST['is_active'];

        if(empty($class_id)){
            // --- INSERT NEW CLASS ---
            $sql = "INSERT INTO classes (name, name_zh, day_of_week, start_time, end_time, coach_id, capacity, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            if($stmt = mysqli_prepare($link, $sql)){
                mysqli_stmt_bind_param($stmt, "sssssiis", $name, $name_zh, $day_of_week, $start_time, $end_time, $coach_id, $capacity, $is_active);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            // --- UPDATE EXISTING CLASS ---
            $sql = "UPDATE classes SET name = ?, name_zh = ?, day_of_week = ?, start_time = ?, end_time = ?, coach_id = ?, capacity = ?, is_active = ? WHERE id = ?";
            if($stmt = mysqli_prepare($link, $sql)){
                mysqli_stmt_bind_param($stmt, "sssssiisi", $name, $name_zh, $day_of_week, $start_time, $end_time, $coach_id, $capacity, $is_active, $class_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }

    // Redirect back to the class management page
    header("location: manage_classes.php");
    exit;
}
?>
