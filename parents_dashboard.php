<?php
// Turn on error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database configuration from the current directory.
// This assumes db_config.php is in the same folder.
require_once "./db_config.php";

// Start the session.
session_start();

// --- LANGUAGE HANDLING ---
$translations = [
    'en' => [
        'portal_title' => 'Family Portal',
        'logout' => 'Logout',
        'welcome' => 'Welcome, ',
        'manage_accounts_text' => "Manage your family's accounts and bookings here.",
        'your_family' => 'Your Family',
        'no_members_found' => 'No family members found. Please contact an administrator to link your family accounts.',
        'member_type' => 'Member Type:',
        'manage_account' => 'Manage Account',
        'profile_pic_alt' => 'Profile Picture',
        'fallback_avatar_alt' => 'Fallback Avatar'
    ],
    'zh' => [
        'portal_title' => '家庭门户',
        'logout' => '登出',
        'welcome' => '欢迎, ',
        'manage_accounts_text' => '在此处管理您家人的账户和预订。',
        'your_family' => '您的家人',
        'no_members_found' => '未找到家人。请联系管理员以链接您的家庭账户。',
        'member_type' => '会员类型:',
        'manage_account' => '管理账户',
        'profile_pic_alt' => '头像',
        'fallback_avatar_alt' => '默认头像'
    ]
];

// Determine the language
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $translations)) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    // Detect browser language if no session language is set
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $_SESSION['lang'] = in_array($browser_lang, ['zh']) ? 'zh' : 'en';
}
$lang = $_SESSION['lang'];

// Check if the user is logged in AND has the 'parent' role.
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'parent') {
    header("location: login.php");
    exit;
}

// Get the current user's ID to fetch their family members.
$parent_user_id = $_SESSION["id"];

// --- Fetch Family Members ---
// Now also fetching the profile_picture_url
$family_members = [];
$sql = "SELECT id, first_name, last_name, member_type, profile_picture_url FROM users WHERE parent_id = ?";

if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $parent_user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        $family_members[] = $row;
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($link);

// Get the current URL with existing query parameters
$current_url = htmlspecialchars($_SERVER['PHP_SELF']);
$query_params = $_GET;
unset($query_params['lang']);
$query_string = http_build_query($query_params);
$separator = empty($query_string) ? '?' : '&';
?>
 
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title>Family Dashboard - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; }
        .profile-picture {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #e5e7eb;
        }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800"><?php echo $translations[$lang]['portal_title']; ?></span>
            <div class="flex items-center space-x-4">
                <!-- Language Toggle -->
                <div class="text-gray-600">
                    <a href="<?php echo $current_url . ($query_string ? '?' . $query_string : '') . ($separator) . 'lang=en'; ?>" class="hover:text-blue-600 <?php echo $lang === 'en' ? 'font-bold' : ''; ?>">EN</a> |
                    <a href="<?php echo $current_url . ($query_string ? '?' . $query_string : '') . ($separator) . 'lang=zh'; ?>" class="hover:text-blue-600 <?php echo $lang === 'zh' ? 'font-bold' : ''; ?>">中</a>
                </div>
                <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">
                    <?php echo $translations[$lang]['logout']; ?>
                </a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800"><?php echo $translations[$lang]['welcome'] . htmlspecialchars($_SESSION["full_name"]); ?>!</h1>
        <p class="mt-2 text-lg text-gray-600"><?php echo $translations[$lang]['manage_accounts_text']; ?></p>

        <!-- Family Members Section -->
        <div class="mt-8">
            <h2 class="text-2xl font-bold text-gray-800"><?php echo $translations[$lang]['your_family']; ?></h2>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (empty($family_members)): ?>
                    <p class="text-gray-600"><?php echo $translations[$lang]['no_members_found']; ?></p>
                <?php else: ?>
                    <?php foreach ($family_members as $member): ?>
                        <div class="bg-white p-8 rounded-2xl shadow-lg flex items-center space-x-6">
                            <!-- Profile Picture -->
                            <?php 
                                $profile_pic_path = !empty($member['profile_picture_url']) 
                                    // CORRECTED: Use the path directly from the database.
                                    ? htmlspecialchars($member['profile_picture_url'])
                                    : 'https://placehold.co/80x80/E5E7EB/A9A9A9?text=👤';
                            ?>
                            <img src="<?php echo $profile_pic_path; ?>" 
                                 alt="<?php echo $translations[$lang]['profile_pic_alt']; ?>" 
                                 class="profile-picture">

                            <div class="flex-grow">
                                <h3 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($member['full_name']); ?></h3>
                                <p class="text-gray-600 mt-1">
                                    <span class="font-semibold"><?php echo $translations[$lang]['member_type']; ?></span> <?php echo htmlspecialchars($member['member_type']); ?>
                                </p>
                                <!-- This button would link to a page to manage a specific child's account -->
                                <a href="manage_child.php?id=<?php echo $member['id']; ?>" class="mt-4 inline-block bg-blue-600 text-white font-bold py-2 px-4 rounded-lg text-center hover:bg-blue-700 transition">
                                    <?php echo $translations[$lang]['manage_account']; ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
