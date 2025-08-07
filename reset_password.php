<?php
// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Define the base directory for includes
define('BASE_DIR', __DIR__);

// Include database configuration
require_once BASE_DIR . "/db_config.php";

$message = '';
$message_type = ''; // 'success' or 'error'
$token_valid = false;
$user_id = null;
$token = $_GET['token'] ?? ''; // Get token from URL

// Retrieve message from session if redirected (for initial load or errors not leading to successful reset)
if (isset($_SESSION['reset_message'])) {
    $message = $_SESSION['reset_message'];
    $message_type = $_SESSION['reset_message_type'];
    unset($_SESSION['reset_message']);
    unset($_SESSION['reset_message_type']);
}

if (empty($token)) {
    $message = "Invalid or missing password reset token.";
    $message_type = 'error';
} else {
    // Validate the token
    $sql_validate_token = "SELECT user_id FROM password_resets WHERE token = ? AND expires > NOW()";
    if ($stmt_validate = mysqli_prepare($link, $sql_validate_token)) {
        mysqli_stmt_bind_param($stmt_validate, "s", $token);
        mysqli_stmt_execute($stmt_validate);
        $result_validate = mysqli_stmt_get_result($stmt_validate);

        if (mysqli_num_rows($result_validate) > 0) {
            $row = mysqli_fetch_assoc($result_validate);
            $user_id = $row['user_id'];
            $token_valid = true;
        } else {
            $message = "Password reset token is invalid or has expired. Please request a new one.";
            $message_type = 'error';
        }
        mysqli_stmt_close($stmt_validate);
    } else {
        $message = "Database error during token validation.";
        $message_type = 'error';
    }
}

// Handle password reset form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && $token_valid) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $submitted_token = $_POST['token'] ?? ''; // Get token from hidden input

    // Re-validate token on POST to prevent race conditions
    $sql_revalidate_token = "SELECT user_id FROM password_resets WHERE token = ? AND expires > NOW()";
    if ($stmt_revalidate = mysqli_prepare($link, $sql_revalidate_token)) {
        mysqli_stmt_bind_param($stmt_revalidate, "s", $submitted_token);
        mysqli_stmt_execute($stmt_revalidate);
        $result_revalidate = mysqli_stmt_get_result($stmt_revalidate);

        if (mysqli_num_rows($result_revalidate) === 0) {
            $message = "Password reset token is invalid or has expired. Please request a new one.";
            $message_type = 'error';
            // No need to set $token_valid = false here, as we're redirecting
        }
        mysqli_stmt_close($stmt_revalidate);
    } else {
        $message = "Database error during token re-validation.";
        $message_type = 'error';
    }


    if ($token_valid) { // Proceed only if token is still valid after re-validation
        if (empty($new_password) || empty($confirm_password)) {
            $message = "Please enter and confirm your new password.";
            $message_type = 'error';
        } elseif ($new_password !== $confirm_password) {
            $message = "Passwords do not match.";
            $message_type = 'error';
        } elseif (strlen($new_password) < 6) { // Example: password minimum length
            $message = "Password must be at least 6 characters long.";
            $message_type = 'error';
        } else {
            // Hash the new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Update user's password
            $sql_update_password = "UPDATE users SET password_hash = ? WHERE id = ?";
            if ($stmt_update = mysqli_prepare($link, $sql_update_password)) {
                mysqli_stmt_bind_param($stmt_update, "si", $hashed_password, $user_id);
                if (mysqli_stmt_execute($stmt_update)) {
                    // Invalidate the token after use
                    $sql_delete_token = "DELETE FROM password_resets WHERE token = ?";
                    if ($stmt_delete = mysqli_prepare($link, $sql_delete_token)) {
                        mysqli_stmt_bind_param($stmt_delete, "s", $submitted_token);
                        mysqli_stmt_execute($stmt_delete);
                        mysqli_stmt_close($stmt_delete);
                    }

                    // Set success message for the login page
                    $_SESSION['login_message'] = [
                        'en' => "Your password has been reset successfully. You can now log in with your new password.",
                        'zh' => "您的密碼已成功重設。您現在可以使用新密碼登入。"
                    ];
                    $_SESSION['login_message_type'] = 'success';

                    // Redirect to login page
                    mysqli_close($link); // Close connection before redirect
                    header("Location: login.html");
                    exit;
                } else {
                    $message = "Error updating password: " . mysqli_error($link);
                    $message_type = 'error';
                }
                mysqli_stmt_close($stmt_update);
            } else {
                $message = "Database error during password update.";
                $message_type = 'error';
            }
        }
    }
}

// Close connection if not already closed by successful redirect
if (isset($link) && is_object($link) && mysqli_ping($link)) {
    mysqli_close($link);
}

// If there's a message from current execution (not from session redirect for success)
// store it to be displayed on the current page.
if (!empty($message) && $_SERVER["REQUEST_METHOD"] == "POST") {
    $_SESSION['reset_message'] = $message;
    $_SESSION['reset_message_type'] = $message_type;
    header("Location: reset_password.php?token=" . urlencode($token)); // Keep token in URL for state
    exit;
}

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Catch Jiu Jitsu</title>
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

    <!-- Reset Password Form Section - Main Content -->
    <div class="min-h-screen flex flex-col items-center justify-center pt-24 pb-12 px-4">
        <div class="w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-2xl p-8 md:p-10">
                <div class="text-center mb-8">
                    <h1 class="text-3xl md:text-4xl font-black text-gray-800 tracking-tight lang" data-lang-en="Reset Your Password" data-lang-zh="重設您的密碼">Reset Your Password</h1>
                    <p class="mt-2 text-gray-500 lang" data-lang-en="Enter your new password below." data-lang-zh="在下方輸入您的新密碼。">Enter your new password below.</p>
                </div>

                <?php if (!empty($message)): ?>
                <div id="message-display" class="mb-6 px-4 py-3 rounded-lg relative
                    <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                    <span class="block sm:inline lang"
                          data-lang-en="<?php echo htmlspecialchars($message); ?>"
                          data-lang-zh="<?php echo htmlspecialchars($message); ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </span>
                </div>
                <?php endif; ?>

                <?php if ($token_valid): ?>
                <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST" class="space-y-6">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div>
                        <label for="new_password" class="block mb-2 text-sm font-medium text-gray-900 lang" data-lang-en="New Password" data-lang-zh="新密碼">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-3" required>
                    </div>
                    <div>
                        <label for="confirm_password" class="block mb-2 text-sm font-medium text-gray-900 lang" data-lang-en="Confirm New Password" data-lang-zh="確認新密碼">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-3" required>
                    </div>
                    <div>
                        <button type="submit" class="w-full text-white bg-blue-600 hover:bg-blue-700 font-bold rounded-lg text-base px-5 py-3 text-center transition shadow-lg lang" data-lang-en="Reset Password" data-lang-zh="重設密碼">Reset Password</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="mt-8 text-center">
                    <p class="text-sm text-gray-600">
                        <span class="lang" data-lang-en="If you need to reset your password, please request a new link." data-lang-zh="如果您需要重設密碼，請請求新的連結。">If you need to reset your password, please request a new link.</span>
                        <a href="forgot_password.php" class="font-medium text-blue-600 hover:underline lang" data-lang-en="Request New Link" data-lang-zh="請求新連結">Request New Link</a>
                    </p>
                </div>
                <?php endif; ?>
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
            const currentLang = { value: 'en' }; 

            const langEnDesktop = document.getElementById('lang-en-desktop');
            const langZhDesktop = document.getElementById('lang-zh-desktop');
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            const navLinks = mobileMenu.querySelectorAll('.nav-link'); 

            const langEnMobile = document.getElementById('lang-en-mobile');
            const langZhMobile = document.getElementById('lang-zh-mobile');

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

            function setLanguage(lang) {
                currentLang.value = lang;
                langElements.forEach(el => {
                    const text = el.getAttribute(`data-lang-${lang}`);
                    if (text) {
                        el.innerHTML = text;
                    }
                });
                updateSwitcherStyles(lang);
                // No hidden input for language on this page's form as it's not submitting language preference
            }

            if (langEnDesktop) langEnDesktop.addEventListener('click', () => setLanguage('en'));
            if (langZhDesktop) langZhDesktop.addEventListener('click', () => setLanguage('zh'));
            if (langEnMobile) langEnMobile.addEventListener('click', () => setLanguage('en'));
            if (langZhMobile) langZhMobile.addEventListener('click', () => setLanguage('zh'));

            if (mobileMenuButton) {
                mobileMenuButton.addEventListener('click', () => {
                    mobileMenu.classList.toggle('hidden');
                });
            }
            
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
