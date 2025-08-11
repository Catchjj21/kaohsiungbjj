<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

// Include the standardized session management
require_once "session_manager.php";

// Include the database config FIRST.
// This loads the session cookie settings BEFORE the session is started.
require_once "db_config.php";

// Check if the user is already logged in as an admin
if(isLoggedIn(['admin', 'coach'])){
    header("location: admin/admin_dashboard.php");
    exit;
}

// Define variables to hold form data and any login errors
$email = "";
$login_err = "";

// Process the form only when it's submitted via POST
if($_SERVER["REQUEST_METHOD"] == "POST"){

    // 1. VALIDATE FORM INPUTS
    if(empty(trim($_POST["email"]))){
        $login_err = "Please enter your email.";
    } else{
        $email = trim($_POST["email"]);
    }
    
    if(empty(trim($_POST["password"]))){
        $login_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // 2. VALIDATE CREDENTIALS AGAINST DATABASE
    if(empty($login_err)){
        $sql = "SELECT id, first_name, last_name, email, password_hash, role FROM users WHERE email = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            $param_email = $email;
            
            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                
                if(mysqli_stmt_num_rows($stmt) == 1){                      
                    mysqli_stmt_bind_result($stmt, $id, $first_name, $last_name, $email_from_db, $hashed_password, $role);
                    if(mysqli_stmt_fetch($stmt)){
                        if(password_verify($password, $hashed_password)){
                            if($role === 'admin' || $role === 'coach'){
                                // Create standardized session for admin login
                                $session_data = createStandardizedSession($id, $first_name, $last_name, $email_from_db, $role, true);
                                
                                // Get redirect URL for admin login
                                $redirect_url = getRedirectUrl($role, true);
                                
                                header("location: " . $redirect_url);
                                exit;
                            } else {
                                $login_err = "Access Denied. This portal is for authorized staff only.";
                            }
                        } else{
                            $login_err = "The email or password you entered is incorrect.";
                        }
                    }
                } else{
                    $login_err = "The email or password you entered is incorrect.";
                }
            } else{
                $login_err = "Oops! Something went wrong on our end. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        } else {
            $login_err = "Database error. Could not prepare your request.";
        }
    }
    
    mysqli_close($link);

    // FIX: Instead of redirecting on error, display the error directly for debugging.
    if(!empty($login_err)){
        echo "<p style='color:red;'><strong>Login Error:</strong> " . htmlspecialchars($login_err) . "</p>";
        exit;
    }

} else {
    header("location: admin_login.html");
    exit;
}
?>
