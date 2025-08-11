<?php
// Include the standardized session management
require_once "session_manager.php";

// Include the database configuration file
require_once "db_config.php";

// Define variables and initialize with empty values
$email = $password = "";
$email_err = $password_err = $login_err = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){

    // Check if email is empty
    if(empty(trim($_POST["email"]))){
        $email_err = "Please enter email.";
    } else{
        $email = trim($_POST["email"]);
    }
    
    // Check if password is empty
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate credentials
    if(empty($email_err) && empty($password_err)){
        // Prepare a select statement
        $sql = "SELECT id, first_name, last_name, email, password_hash, role FROM users WHERE email = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            $param_email = $email;
            
            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                
                // Check if email exists, if yes then verify password
                if(mysqli_stmt_num_rows($stmt) == 1){                      
                    // Bind first_name and last_name to separate variables
                    mysqli_stmt_bind_result($stmt, $id, $first_name, $last_name, $email_from_db, $hashed_password, $role);
                    
                    if(mysqli_stmt_fetch($stmt)){
                        if(password_verify($password, $hashed_password)){
                            // Password is correct, create standardized session
                            $session_data = createStandardizedSession($id, $first_name, $last_name, $email_from_db, $role, false);
                            
                            // Get redirect URL based on role
                            $redirect_url = getRedirectUrl($role, false);
                            
                            // Redirect to appropriate dashboard
                            header("location: " . $redirect_url);
                            exit; // Always good practice to exit after a header redirect
                        } else{
                            // Password is not valid
                            $login_err = "Invalid email or password.";
                        }
                    }
                } else{
                    // Email doesn't exist
                    $login_err = "Invalid email or password.";
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Close connection
    mysqli_close($link);

    // If there was a login error, redirect back to login page with an error message
    if(!empty($login_err)) {
        header("location: login.html?error=" . urlencode($login_err));
        exit;
    }
}
?>
