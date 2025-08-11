<?php
// Start the session to access session variables.
session_start();

// Check if a parent session has been saved.
if (isset($_SESSION['parent_session'])) {
    // Overwrite the current session with the saved parent session data.
    $_SESSION["loggedin"] = true;
    $_SESSION["id"] = $_SESSION['parent_session']['id'];
    $_SESSION["full_name"] = $_SESSION['parent_session']['full_name'];
    $_SESSION["email"] = $_SESSION['parent_session']['email'];
    $_SESSION["role"] = $_SESSION['parent_session']['role'];
    
    // Unset the parent session variable to prevent infinite loops.
    unset($_SESSION['parent_session']);
    
    // Redirect the user back to the parent's dashboard.
    header("location: parents_dashboard.php");
    exit;
} else {
    // If no parent session is found, just redirect to the regular login page.
    header("location: login.php");
    exit;
}
?>
