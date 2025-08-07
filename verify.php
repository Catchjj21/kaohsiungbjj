<?php
require_once "db_config.php";

$message = "";

if(isset($_GET['email']) && isset($_GET['token'])){
    $email = $_GET['email'];
    $token = $_GET['token'];

    // Prepare a select statement to find the user
    $sql = "SELECT id FROM users WHERE email = ? AND verification_token = ? AND is_verified = 0";

    if($stmt = mysqli_prepare($link, $sql)){
        mysqli_stmt_bind_param($stmt, "ss", $email, $token);
        
        if(mysqli_stmt_execute($stmt)){
            mysqli_stmt_store_result($stmt);

            if(mysqli_stmt_num_rows($stmt) == 1){
                // User found, update their status
                $update_sql = "UPDATE users SET is_verified = 1, verification_token = NULL WHERE email = ?";
                if($update_stmt = mysqli_prepare($link, $update_sql)){
                    mysqli_stmt_bind_param($update_stmt, "s", $email);
                    if(mysqli_stmt_execute($update_stmt)){
                        // Redirect to a success page
                        header("location: verification_success.html");
                        exit();
                    } else {
                        $message = "Error updating record. Please try again.";
                    }
                    mysqli_stmt_close($update_stmt);
                }
            } else {
                // No user found or already verified
                $message = "Invalid verification link or account already verified.";
            }
        } else {
            $message = "Error executing query.";
        }
        mysqli_stmt_close($stmt);
    } else {
        $message = "Database error.";
    }
} else {
    $message = "Invalid verification request.";
}

// If we reach here, something went wrong. Redirect to an error page.
// You can pass the message via session or query string if you want to display it.
header("location: verification_error.html");
exit();

?>
