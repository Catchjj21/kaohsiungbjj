<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "db_config.php";
session_start();

// Security check: Allow any logged-in user
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.html");
    exit;
}

$user_id = $_SESSION['id'];

// --- Check for Active Membership ---
$is_membership_active = false;
// Admins and coaches always have access
if (in_array($_SESSION['role'], ['admin', 'coach'])) {
    $is_membership_active = true;
} else {
    $sql_check = "SELECT id FROM memberships WHERE user_id = ? AND end_date >= CURDATE() LIMIT 1";
    if ($stmt_check = mysqli_prepare($link, $sql_check)) {
        mysqli_stmt_bind_param($stmt_check, "i", $user_id);
        mysqli_stmt_execute($stmt_check);
        mysqli_stmt_store_result($stmt_check);
        if (mysqli_stmt_num_rows($stmt_check) > 0) {
            $is_membership_active = true;
        }
        mysqli_stmt_close($stmt_check);
    }
}


// --- LANGUAGE HANDLING ---
$translations = [
    'en' => [
        'page_title' => 'Technique Library',
        'back_to_dashboard' => 'Back to Dashboard',
        'welcome' => 'Technique Library',
        'description' => 'Browse our curated collection of techniques, updated weekly by our world-class coaches.',
        'filter_by_tag' => 'Filter by Tag...',
        'all_tags' => 'All Techniques',
        'no_techniques' => 'No techniques found for the selected tag.',
        'by_coach' => 'By Coach',
        'renew_membership_title' => 'Membership Required',
        'renew_membership_message' => 'This content is for active members only. Please renew your membership to access our full technique library.',
        'renew_membership_button' => 'Renew Membership',
    ],
    'zh' => [
        'page_title' => '技術庫',
        'back_to_dashboard' => '返回儀表板',
        'welcome' => '技術庫',
        'description' => '瀏覽我們由世界級教練每週更新的精選技術合集。',
        'filter_by_tag' => '按標籤篩選...',
        'all_tags' => '所有技術',
        'no_techniques' => '找不到所選標籤的技術。',
        'by_coach' => '教練',
        'renew_membership_title' => '需要有效會員資格',
        'renew_membership_message' => '此內容僅供有效會員觀看。請續訂您的會員資格以訪問我們的完整技術庫。',
        'renew_membership_button' => '續訂會員資格',
    ]
];
$lang = $_SESSION['lang'] ?? 'en';

// Define tags (should match the management page)
$tags_list = [
    'guard' => '防禦', 'sweep' => '掃技', 'half guard' => '半防禦', 'mount' => '騎乘', 
    'back' => '背部控制', 'guard passing' => '過防禦', 'half guard passing' => '過半防禦', 
    'submission' => '降伏', 'judo' => '柔道', 'wrestling' => '摔跤', 
    'side control' => '側壓', 'kesi gatame' => '袈裟固'
];

// Fetch all technique posts only if membership is active
$technique_posts = [];
if ($is_membership_active) {
    $sql = "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) as coach_name 
            FROM technique_of_the_week t
            LEFT JOIN users u ON t.coach_id = u.id
            ORDER BY t.created_at DESC";

    if ($result = mysqli_query($link, $sql)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $technique_posts[] = $row;
        }
        mysqli_free_result($result);
    }
}
mysqli_close($link);

// Function to get YouTube embed URL from various link formats
function get_youtube_embed_url($url) {
    $video_id = '';
    $pattern = '/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/';
    preg_match($pattern, $url, $matches);
    if (isset($matches[1])) {
        $video_id = $matches[1];
    }
    if (!empty($video_id)) {
        return 'https://www.youtube.com/embed/' . $video_id;
    }
    return ''; // Return empty string if no valid ID found
}

// Determine the correct dashboard link based on user role
$dashboard_link = "dashboard.php";
if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'coach':
            $dashboard_link = "coach/coach_dashboard.php";
            break;
        case 'parent':
            $dashboard_link = "parents_dashboard.php";
            break;
        case 'admin':
            $dashboard_link = "admin/admin_dashboard.php";
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title class="lang" data-lang-en="Technique Library" data-lang-zh="技術庫">Technique Library</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .prose img { max-width: 100%; height: auto; border-radius: 0.5rem; }
        .video-container {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
            height: 0;
            overflow: hidden;
            max-width: 100%;
            background: #000;
            border-radius: 0.75rem;
        }
        .video-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800 lang" data-lang-en="Technique Library" data-lang-zh="技術庫">Technique Library</span>
            <div class="flex items-center space-x-4">
                <div id="lang-switcher-desktop" class="flex items-center space-x-2 text-sm">
                    <button id="lang-en" class="font-bold text-blue-600">EN</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
                <a href="<?php echo $dashboard_link; ?>" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition lang" data-lang-en="Back to Dashboard" data-lang-zh="返回儀表板">Back to Dashboard</a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-black text-gray-800 lang" data-lang-en="Technique Library" data-lang-zh="技術庫">Technique Library</h1>
            <p class="mt-2 text-lg text-gray-600 max-w-2xl mx-auto lang" data-lang-en="Browse our curated collection of techniques, updated weekly by our world-class coaches." data-lang-zh="瀏覽我們由世界級教練每週更新的精選技術合集。">Browse our curated collection of techniques, updated weekly by our world-class coaches.</p>
        </div>

        <?php if ($is_membership_active): ?>
            <!-- Filter Dropdown -->
            <div class="mt-8 max-w-sm mx-auto">
                <select id="tag-filter" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all" class="lang" data-lang-en="All Techniques" data-lang-zh="所有技術">All Techniques</option>
                    <?php foreach ($tags_list as $en_tag => $zh_tag): ?>
                        <option value="<?php echo htmlspecialchars($en_tag); ?>" class="lang" data-lang-en="<?php echo htmlspecialchars(ucwords($en_tag)); ?>" data-lang-zh="<?php echo htmlspecialchars($zh_tag); ?>"><?php echo htmlspecialchars(ucwords($en_tag)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Techniques Grid -->
            <div id="technique-grid" class="mt-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php if (empty($technique_posts)): ?>
                    <p class="col-span-full text-center text-gray-500 lang" data-lang-en="No techniques posted yet." data-lang-zh="尚未發布任何技巧。">No techniques posted yet.</p>
                <?php else: ?>
                    <?php foreach ($technique_posts as $post): ?>
                        <div class="technique-card bg-white rounded-2xl shadow-lg overflow-hidden flex flex-col" data-tags="<?php echo htmlspecialchars($post['tags']); ?>">
                            <div class="video-container">
                                <?php $embed_url = get_youtube_embed_url($post['youtube_url']); ?>
                                <?php if (!empty($embed_url)): ?>
                                    <iframe src="<?php echo $embed_url; ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                                <?php endif; ?>
                            </div>
                            <div class="p-6 flex-grow flex flex-col">
                                <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($post['title']); ?></h3>
                                <p class="text-sm text-gray-500 mt-1"><span class="lang" data-lang-en="By Coach" data-lang-zh="教練">By Coach</span> <?php echo htmlspecialchars($post['coach_name']); ?> &bull; <?php echo date('M d, Y', strtotime($post['created_at'])); ?></p>
                                <div class="prose prose-sm mt-4 text-gray-600 flex-grow">
                                    <?php echo $post['description']; ?>
                                </div>
                                <div class="mt-4 border-t pt-4">
                                    <div class="flex flex-wrap gap-2">
                                        <?php 
                                        $post_tags = explode(',', $post['tags']);
                                        foreach ($post_tags as $tag): 
                                            if (!empty($tag)): ?>
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800"><?php echo htmlspecialchars($tag); ?></span>
                                            <?php endif; 
                                        endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div id="no-results" class="hidden text-center text-gray-500 mt-8">
                <p class="lang" data-lang-en="No techniques found for the selected tag." data-lang-zh="找不到所選標籤的技術。">No techniques found for the selected tag.</p>
            </div>
        <?php else: ?>
            <!-- Membership Expired Message -->
            <div class="text-center mt-12 bg-white p-8 rounded-2xl shadow-lg max-w-2xl mx-auto">
                <h2 class="text-3xl font-bold text-red-600 lang" data-lang-en="Membership Required" data-lang-zh="需要有效會員資格">Membership Required</h2>
                <p class="mt-4 text-lg text-gray-700 lang" data-lang-en="This content is for active members only. Please renew your membership to access our full technique library." data-lang-zh="此內容僅供有效會員觀看。請續訂您的會員資格以訪問我們的完整技術庫。">This content is for active members only. Please renew your membership to access our full technique library.</p>
                <a href="dashboard.php#billing-view-section" class="mt-6 inline-block bg-blue-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-blue-700 transition lang" data-lang-en="Renew Membership" data-lang-zh="續訂會員資格">Renew Membership</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const translations = <?php echo json_encode($translations); ?>;
            let currentLang = localStorage.getItem('coachLang') || 'en';

            function updateLanguage() {
                document.querySelectorAll('.lang').forEach(el => {
                    const text = el.getAttribute('data-lang-' + currentLang);
                    if (text) el.textContent = text;
                });
                document.getElementById('lang-en').classList.toggle('font-bold', currentLang === 'en');
                document.getElementById('lang-en').classList.toggle('text-blue-600', currentLang === 'en');
                document.getElementById('lang-zh').classList.toggle('font-bold', currentLang === 'zh');
                document.getElementById('lang-zh').classList.toggle('text-blue-600', currentLang === 'zh');
            }

            function setLanguage(lang) {
                currentLang = lang;
                localStorage.setItem('coachLang', lang);
                updateLanguage();
            }

            document.getElementById('lang-en').addEventListener('click', () => setLanguage('en'));
            document.getElementById('lang-zh').addEventListener('click', () => setLanguage('zh'));

            const tagFilter = document.getElementById('tag-filter');
            const techniqueGrid = document.getElementById('technique-grid');
            const noResults = document.getElementById('no-results');

            if (tagFilter) {
                tagFilter.addEventListener('change', function() {
                    const selectedTag = this.value;
                    let visibleCount = 0;

                    techniqueGrid.querySelectorAll('.technique-card').forEach(card => {
                        const cardTags = card.dataset.tags;
                        if (selectedTag === 'all' || (cardTags && cardTags.includes(selectedTag))) {
                            card.style.display = 'flex';
                            visibleCount++;
                        } else {
                            card.style.display = 'none';
                        }
                    });

                    if (visibleCount === 0) {
                        noResults.classList.remove('hidden');
                    } else {
                        noResults.classList.add('hidden');
                    }
                });
            }

            updateLanguage();
        });
    </script>
</body>
</html>
