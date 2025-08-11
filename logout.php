<?php
// Include the standardized session management
require_once "session_manager.php";

// Destroy the session using the standardized function
destroySession();

// Redirect to home page
header("location: index.html");
exit;
?>
