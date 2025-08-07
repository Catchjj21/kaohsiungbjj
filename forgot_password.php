<?php
// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Define the base directory for includes
define('BASE_DIR', __DIR__);

// Include PHPMailer files directly based on your public_html/PHPMailer/src/ structure.
try {
    require_once BASE_DIR . '/PHPMailer/src/Exception.php';
    require_once BASE_DIR . '/PHPMailer/src/PHPMailer.php';
    require_once BASE_DIR . '/PHPMailer/src/SMTP.php';
} catch (Exception $e) {
    echo "<h1>Fatal Error: PHPMailer files not found. Please ensure PHPMailer is correctly placed in your public_html/PHPMailer/src/ directory.</h1>";
    error_log("PHPMailer Include Error: " . $e->getMessage());
    exit; // Stop execution if PHPMailer files are missing
}


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP; // Needed for SMTP settings

// Include database configuration
try {
    if (!file_exists(BASE_DIR . "/db_config.php")) {
        throw new Exception("Database configuration file not found at: " . htmlspecialchars(BASE_DIR . "/db_config.php"));
    }
    require_once BASE_DIR . "/db_config.php";
} catch (Exception $e) {
    echo "<h1>Fatal Error: " . $e->getMessage() . "</h1>";
    error_log("DB Config Error: " . $e->getMessage());
    exit; // Stop execution if db_config is missing
}


$message_data = [
    'en' => '',
    'zh' => ''
];
$message_type = ''; // 'success' or 'error'

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"] ?? '');
    $selected_lang = $_POST["selected_lang"] ?? 'en'; // Get the language from the form, default to 'en'

    if (empty($email)) {
        $message_data['en'] = "Please enter your email address.";
        $message_data['zh'] = "請輸入您的電子郵件地址。";
        $message_type = 'error';
    } else {
        try {
            // 1. Check if the email exists in your database
            $sql_check_email = "SELECT id FROM users WHERE email = ?";
            if ($stmt_check = mysqli_prepare($link, $sql_check_email)) {
                mysqli_stmt_bind_param($stmt_check, "s", $email);
                mysqli_stmt_execute($stmt_check);
                $result_check = mysqli_stmt_get_result($stmt_check);

                if (mysqli_num_rows($result_check) > 0) {
                    $user = mysqli_fetch_assoc($result_check);
                    $user_id = $user['id'];

                    // 2. Generate a unique token
                    $token = bin2hex(random_bytes(32)); // Generate a 64-character hex token
                    $expires = date("Y-m-d H:i:s", strtotime('+1 hour')); // Token valid for 1 hour

                    // 3. Store the token and its expiry time in the database
                    // Ensure your 'password_resets' table exists and its user_id column matches users.id
                    $sql_insert_token = "INSERT INTO password_resets (user_id, token, expires) VALUES (?, ?, ?)";
                    if ($stmt_insert = mysqli_prepare($link, $sql_insert_token)) {
                        mysqli_stmt_bind_param($stmt_insert, "iss", $user_id, $token, $expires);
                        mysqli_stmt_execute($stmt_insert);
                        mysqli_stmt_close($stmt_insert);

                        // 4. Send an email to the user with a link containing the token using PHPMailer
                        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/reset_password.php?token=" . $token; // Adjust domain as needed

                        $subject_en = "Password Reset Request for Catch Jiu Jitsu";
                        $subject_zh = "Catch 柔術密碼重設請求";

                        // HTML Body for email
                        $html_body_en = "
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <meta charset='utf-8'>
                                <title>{$subject_en}</title>
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; }
                                    .header { text-align: center; margin-bottom: 20px; }
                                    .button { display: inline-block; padding: 10px 20px; margin-top: 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; }
                                    .footer { margin-top: 30px; text-align: center; font-size: 0.8em; color: #777; }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <div class='header'>
                                        <h2>Password Reset Request</h2>
                                    </div>
                                    <p>Dear Member,</p>
                                    <p>You have requested to reset your password for your Catch Jiu Jitsu account.</p>
                                    <p>Please click on the following link to reset your password:</p>
                                    <p><a href=\"{$reset_link}\" class='button'>Reset Your Password</a></p>
                                    <p>Alternatively, you can copy and paste the following link into your browser:</p>
                                    <p>{$reset_link}</p>
                                    <p>This link will expire in 1 hour.</p>
                                    <p>If you did not request a password reset, please ignore this email.</p>
                                    <p>Sincerely,</p>
                                    <p>Catch Jiu Jitsu Team</p>
                                    <div class='footer'>
                                        <p>&copy; " . date("Y") . " Catch Jiu Jitsu. All Rights Reserved.</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                        ";

                        $html_body_zh = "
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <meta charset='utf-8'>
                                <title>{$subject_zh}</title>
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; }
                                    .header { text-align: center; margin-bottom: 20px; }
                                    .button { display: inline-block; padding: 10px 20px; margin-top: 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; }
                                    .footer { margin-top: 30px; text-align: center; font-size: 0.8em; color: #777; }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <div class='header'>
                                        <h2>密碼重設請求</h2>
                                    </div>
                                    <p>親愛的會員您好：</p>
                                    <p>您已請求重設您的 Catch 柔術帳戶密碼。</p>
                                    <p>請點擊以下連結重設您的密碼：</p>
                                    <p><a href=\"{$reset_link}\" class='button'>重設您的密碼</a></p>
                                    <p>或者，您可以將以下連結複製並貼到您的瀏覽器中：</p>
                                    <p>{$reset_link}</p>
                                    <p>此連結將於 1 小時後失效。</p>
                                    <p>如果您沒有請求重設密碼，請忽略此電子郵件。</p>
                                    <p>此致,</p>
                                    <p>Catch 柔術團隊 敬上</p>
                                    <div class='footer'>
                                        <p>&copy; " . date("Y") . " Catch Jiu Jitsu. 版權所有。</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                        ";

                        // Plain text body (for clients that don't render HTML)
                        $alt_body_en = "Dear Member,\n\nYou have requested to reset your password for your Catch Jiu Jitsu account.\nPlease click on the following link to reset your password:\n{$reset_link}\n\nThis link will expire in 1 hour.\nIf you did not request a password reset, please ignore this email.\n\nSincerely,\nCatch Jiu Jitsu Team";
                        $alt_body_zh = "親愛的會員您好：\n\n您已請求重設您的 Catch 柔術帳戶密碼。\n請點擊以下連結重設您的密碼：\n{$reset_link}\n\n此連結將於 1 小時後失效。\n如果您沒有請求重設密碼，請忽略此電子郵件。\n\n此致,\nCatch 柔術團隊 敬上";


                        $mail = new PHPMailer(true); // Enable exceptions

                        try {
                            // Disable verbose debug output for production
                            $mail->SMTPDebug = SMTP::DEBUG_OFF; 

                            // Server settings - Your provided details
                            $mail->isSMTP();                                            // Send using SMTP
                            $mail->Host       = 'mail.stackmail.com';                   // SMTP server
                            $mail->SMTPAuth   = true;                                   // Enable SMTP authentication
                            $mail->Username   = 'catchjiujitsu@kaohsiungbjj.com';       // SMTP username
                            $mail->Password   = 'Bigtest12';                            // SMTP password
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            // Enable SMTPS encryption (SSL/TLS)
                            $mail->Port       = 465;                                    // TCP port to connect to

                            // Recipients - IMPORTANT: Set From address to match authenticated username
                            $mail->setFrom('catchjiujitsu@kaohsiungbjj.com', 'Catch Jiu Jitsu'); // Sender email MUST match SMTP Username
                            $mail->addReplyTo('info@kaohsiungbjj.com', 'Catch Jiu Jitsu Info'); // Add a reply-to address
                            $mail->addAddress($email);                                  // Add a recipient

                            // Set character set for the email
                            $mail->CharSet = 'UTF-8'; // Crucial for proper display of non-ASCII characters

                            // Content - Set based on selected_lang
                            $mail->isHTML(true);                                        // Set email format to HTML
                            
                            if ($selected_lang === 'zh') {
                                // Correctly call encodeHeader as a non-static method on the $mail object
                                $mail->Subject = $mail->EncodeHeader($subject_zh, 'UTF-8', 'B'); // 'B' for Base64 encoding
                                $mail->Body    = $html_body_zh;
                                $mail->AltBody = $alt_body_zh;
                            } else { // Default to English
                                // Correctly call encodeHeader as a non-static method on the $mail object
                                $mail->Subject = $mail->EncodeHeader($subject_en, 'UTF-8', 'B'); // 'B' for Base64 encoding
                                $mail->Body    = $html_body_en;
                                $mail->AltBody = $alt_body_en;
                            }

                            $mail->send();
                            // Email sent successfully, set success message for display
                            $message_data['en'] = "If an account with that email exists, a password reset link has been sent to " . htmlspecialchars($email) . ". Please check your inbox (and spam folder).";
                            $message_data['zh'] = "如果該電子郵件地址存在帳戶，密碼重設連結已發送到 " . htmlspecialchars($email) . "。請檢查您的收件箱（和垃圾郵件文件夾）。";
                            $message_type = 'success';

                        } catch (Exception $e) {
                            error_log("PHPMailer Error: " . $mail->ErrorInfo);
                            // For security (prevent user enumeration), always show generic success to the user,
                            // even if email sending fails. Log the actual error.
                            $message_data['en'] = "If an account with that email exists, a password reset link has been sent to " . htmlspecialchars($email) . ". Please check your inbox (and spam folder).";
                            $message_data['zh'] = "如果該電子郵件地址存在帳戶，密碼重設連結已發送到 " . htmlspecialchars($email) . "。請檢查您的收件箱（和垃圾郵件文件夾）。";
                            $message_type = 'success';
                        }

                    } else {
                        throw new Exception("Error preparing token insert statement: " . mysqli_error($link));
                    }
                } else {
                    // Email not found, but return generic success to prevent user enumeration
                    $message_data['en'] = "If an account with that email exists, a password reset link has been sent to " . htmlspecialchars($email) . ". Please check your inbox (and spam folder).";
                    $message_data['zh'] = "如果該電子郵件地址存在帳戶，密碼重設連結已發送到 " . htmlspecialchars($email) . "。請檢查您的收件箱（和垃圾郵件文件夾）。";
                    $message_type = 'success';
                }
                mysqli_stmt_close($stmt_check);
            } else {
                throw new Exception("Error preparing email check statement: " . mysqli_error($link));
            }
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            $message_data['en'] = "An error occurred during the password reset request. Please try again later.";
            $message_data['zh'] = "密碼重設請求期間發生錯誤。請稍後再試。";
            $message_type = 'error';
        }
    }
}

// Store messages in session to persist across redirect
if (!empty($message_data['en']) && $_SERVER["REQUEST_METHOD"] == "POST") { // Only redirect if it was a POST request
    $_SESSION['form_message'] = $message_data;
    $_SESSION['form_message_type'] = $message_type;
    header("Location: forgot_password.php");
    exit;
}

// Retrieve message from session if redirected
if (isset($_SESSION['form_message'])) {
    $message_data = $_SESSION['form_message'];
    $message_type = $_SESSION['form_message_type'];
    unset($_SESSION['form_message']); // Clear message after displaying
    unset($_SESSION['form_message_type']);
}

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            padding-top: 80px; /* Adjust for fixed header height */
        }
        .text-gradient {
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            background-image: linear-gradient(to right, #3b82f6, #8b5cf6);
        }
        .hero-bg {
            background-color: #f3f4f6;
            background-image:
                radial-gradient(at 47% 33%, hsl(200.00, 0%, 100%) 0, transparent 59%),
                radial-gradient(at 82% 65%, hsl(215.00, 70%, 85%) 0, transparent 55%);
        }
        /* Styles for the modal (retained for consistency, though not directly used on this page) */
        .modal {
            transition: opacity 0.25s ease;
        }
        /* Custom scrollbar for timetable (retained for consistency) */
        .timetable-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .timetable-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .timetable-container::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        .timetable-container::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        /* Gallery and Art Description styles (retained for consistency, though not directly used on this page) */
        .gallery-img {
            transition: transform 0.3s ease;
        }
        .gallery-img:hover {
            transform: scale(1.05);
        }
        .art-description {
            transition: all 0.3s ease-in-out;
        }
    </style>
</head>
<body class="bg-gray-50 hero-bg"> <!-- Using hero-bg for the background -->

    <!-- Header from main page -->
    <nav id="navbar" class="bg-white shadow-md fixed top-0 left-0 right-0 z-40">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center h-20">
                <a href="index.html" class="flex items-center space-x-2">
                    <img src="logo.png" alt="Catch Jiu Jitsu Logo" class="h-12 w-12" onerror="this.onerror=null;this.src='https://placehold.co/48x48/e0e0e0/333333?text=Logo';">
                    <span class="font-bold text-xl text-gray-800">Catch Jiu Jitsu</span>
                </a>

                <div class="hidden md:flex items-center space-x-4">
                    <!-- Removed specific navigation links not relevant to forgot password page -->
                    <a href="login.html" class="text-gray-600 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium lang" data-lang-en="Back to Login" data-lang-zh="返回登入">Back to Login</a>
                    
                    <div id="lang-switcher-desktop" class="pl-4 flex items-center space-x-2 text-sm border-l border-gray-300 ml-4">
                        <button id="lang-en-desktop" class="font-bold text-blue-600">EN</button>
                        <span class="text-gray-300">|</span>
                        <button id="lang-zh-desktop" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                    </div>
                </div>

                <div class="md:hidden flex items-center">
                    <button id="mobile-menu-button" class="text-gray-800 hover:text-blue-600 focus:outline-none">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <div id="mobile-menu" class="md:hidden hidden bg-white border-t border-gray-200">
            <!-- Mobile menu links, simplified for this page -->
            <a href="login.html" class="block py-3 px-4 text-sm text-gray-600 hover:bg-gray-100 lang nav-link" data-lang-en="Back to Login" data-lang-zh="返回登入">Back to Login</a>
            
            <div class="py-3 px-4 border-t">
                <div id="lang-switcher-mobile" class="flex items-center space-x-4 text-base mt-2">
                    <button id="lang-en-mobile" class="font-bold text-blue-600">English</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh-mobile" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Forgot Password Form Section - Main Content -->
    <div class="min-h-screen flex flex-col items-center justify-center pt-24 pb-12 px-4">
        <div class="w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-2xl p-8 md:p-10">
                <div class="text-center mb-8">
                    <h1 class="text-3xl md:text-4xl font-black text-gray-800 tracking-tight lang" data-lang-en="Forgot Password?" data-lang-zh="忘記密碼？">Forgot Password?</h1>
                    <p class="mt-2 text-gray-500 lang" data-lang-en="Enter your email to reset your password." data-lang-zh="輸入您的電子郵件以重設密碼。">Enter your email to reset your password.</p>
                </div>

                <?php if (!empty($message_data['en'])): // Check if any message exists to display the div ?>
                <div id="message-display" class="mb-6 px-4 py-3 rounded-lg relative
                    <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                    <span class="block sm:inline lang"
                          data-lang-en="<?php echo htmlspecialchars($message_data['en']); ?>"
                          data-lang-zh="<?php echo htmlspecialchars($message_data['zh']); ?>">
                        <?php echo htmlspecialchars($message_data['en']); // Default to English initially ?>
                    </span>
                </div>
                <?php endif; ?>

                <form action="forgot_password.php" method="POST" class="space-y-6">
                    <div>
                        <label for="email" class="block mb-2 text-sm font-medium text-gray-900 lang" data-lang-en="Email" data-lang-zh="電子郵件">Email</label>
                        <input type="email" id="email" name="email" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-3" required>
                    </div>
                    <div>
                        <button type="submit" class="w-full text-white bg-blue-600 hover:bg-blue-700 font-bold rounded-lg text-base px-5 py-3 text-center transition shadow-lg lang" data-lang-en="Send Reset Link" data-lang-zh="發送重設連結">Send Reset Link</button>
                    </div>
                    <!-- Hidden input to send selected language to PHP -->
                    <input type="hidden" name="selected_lang" id="selected-lang-input" value="en">
                </form>

                <div class="mt-8 text-center">
                    <p class="text-sm text-gray-600">
                        <span class="lang" data-lang-en="Remember your password?" data-lang-zh="還記得您的密碼嗎？">Remember your password?</span>
                        <a href="login.html" class="font-medium text-blue-600 hover:underline lang" data-lang-en="Log In" data-lang-zh="登入">Log In</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer from main page -->
    <footer class="bg-gray-800 text-white py-12">
        <div class="container mx-auto px-4 text-center">
            <h3 class="text-2xl font-bold mb-4 lang" data-lang-en="Follow Us" data-lang-zh="追蹤我們">Follow Us</h3>
            <div class="max-w-xl mx-auto">
                <p class="text-gray-400 mb-2 lang" data-lang-en="Catch Jiu Jitsu" data-lang-zh="Catch柔術">Catch Jiu Jitsu</p>
                <p class="text-gray-400 mb-6 lang" data-lang-en="3F, No. 79, Jhonghua 3rd Rd, Qianjin District, Kaohsiung City, 801" data-lang-zh="801高雄市前金區中華三路79號3樓">3F, No. 79, Jhonghua 3rd Rd, Qianjin District, Kaohsiung City, 801</p>
            </div>
            <div class="flex justify-center space-x-6">
                <a href="https://www.facebook.com/catchjiujitsu" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:opacity-80 transition" aria-label="Facebook">
                    <img src="facebook.png" alt="Facebook" class="w-8 h-8">
                </a>
                <a href="https://www.instagram.com/catchjiujitsu" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:opacity-80 transition" aria-label="Instagram">
                    <img src="instagram.png" alt="Instagram" class="w-8 h-8">
                </a>
                <a href="https://line.me/R/ti/p/@catchjiujitsu" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:opacity-80 transition" aria-label="Line">
                    <img src="line.png" alt="Line" class="w-8 h-8">
                </a>
            </div>
            <div class="mt-8 pt-8 border-t border-gray-700 text-sm text-gray-500">
                <p>&copy; <span id="current-year-footer"></span> Catch Jiu Jitsu. All Rights Reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Modals from main page (kept for consistency, but hidden/not used directly on this page) -->
    <div id="schedule-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden"></div>
    <div id="contact-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden"></div>
    <div id="gallery-modal" class="modal fixed inset-0 z-[60] flex items-center justify-center bg-black bg-opacity-80 p-4 hidden"></div>
    <div id="dan-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden"></div>
    <div id="steven-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden"></div>
    <div id="xiaoniu-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden"></div>
    <div id="youth-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden"></div>
    <div id="junior-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const langElements = document.querySelectorAll('.lang');
            // Use an object to make currentLang mutable across functions, or pass it
            const currentLang = { value: 'en' }; 

            const langEnDesktop = document.getElementById('lang-en-desktop');
            const langZhDesktop = document.getElementById('lang-zh-desktop');
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            const navLinks = mobileMenu.querySelectorAll('.nav-link'); // Select links specifically within mobile menu

            // Add mobile language switchers if they exist
            const langEnMobile = document.getElementById('lang-en-mobile');
            const langZhMobile = document.getElementById('lang-zh-mobile');

            // Hidden input for language
            const selectedLangInput = document.getElementById('selected-lang-input');

            // Set current year in footer
            const currentYearFooter = document.getElementById('current-year-footer');
            if (currentYearFooter) {
                currentYearFooter.textContent = new Date().getFullYear();
            }

            function updateSwitcherStyles(lang) {
                if (lang === 'en') {
                    if (langEnDesktop) langEnDesktop.className = 'font-bold text-blue-600';
                    if (langZhDesktop) langZhDesktop.className = 'font-normal text-gray-500 hover:text-blue-600';
                    if (langEnMobile) langEnMobile.className = 'font-bold text-blue-600';
                    if (langZhMobile) langZhMobile.className = 'font-normal text-gray-500 hover:text-blue-600';
                } else { // lang === 'zh'
                    if (langEnDesktop) langEnDesktop.className = 'font-normal text-gray-500 hover:text-blue-600';
                    if (langZhDesktop) langZhDesktop.className = 'font-bold text-blue-600';
                    if (langEnMobile) langEnMobile.className = 'font-normal text-gray-500 hover:text-blue-600';
                    if (langZhMobile) langZhMobile.className = 'font-bold text-blue-600';
                }
            }

            // Function to set language
            function setLanguage(lang) {
                currentLang.value = lang;
                langElements.forEach(el => {
                    // For general language elements (like titles, paragraphs)
                    const text = el.getAttribute(`data-lang-${lang}`);
                    if (text) {
                        el.innerHTML = text; // Use innerHTML for elements that might contain entities like &apos;
                    }
                });
                updateSwitcherStyles(lang);
                // Update hidden input field with current language
                if (selectedLangInput) {
                    selectedLangInput.value = lang;
                }
            }

            // Language toggle events
            if (langEnDesktop) langEnDesktop.addEventListener('click', () => setLanguage('en'));
            if (langZhDesktop) langZhDesktop.addEventListener('click', () => setLanguage('zh'));
            if (langEnMobile) langEnMobile.addEventListener('click', () => setLanguage('en'));
            if (langZhMobile) langZhMobile.addEventListener('click', () => setLanguage('zh'));

            // --- Mobile Menu ---
            if (mobileMenuButton) {
                mobileMenuButton.addEventListener('click', () => {
                    mobileMenu.classList.toggle('hidden');
                });
            }
            
            // Close mobile menu when a link is clicked
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    mobileMenu.classList.add('hidden');
                });
            });

            // Set initial language based on browser preference
            const userLang = navigator.language || navigator.userLanguage;
            const defaultLang = userLang.startsWith('zh') ? 'zh' : 'en';
            setLanguage(defaultLang);
        });
    </script>

</body>
</html>
