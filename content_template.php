<?php
// Start the session at the very beginning of the script
session_start();

require_once "db_config.php";
$content_type = $_GET['type'] ?? 'news'; // Default to 'news' if not specified

// Set page title based on content type
$page_titles = [
    'news' => ['en' => 'News', 'zh' => '最新消息'],
    'announcement' => ['en' => 'Announcements', 'zh' => '公告'],
    'event' => ['en' => 'Events', 'zh' => '活動']
];
$page_title_en = $page_titles[$content_type]['en'] ?? 'Content';
$page_title_zh = $page_titles[$content_type]['zh'] ?? '內容';

$posts = [];
$sql = "SELECT * FROM site_content WHERE type = ? ORDER BY publish_date DESC";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "s", $content_type);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $posts[] = $row;
    }
    mysqli_stmt_close($stmt);
}
mysqli_close($link);

// --- New code for dashboard button ---

// Get the user's role from the session. Use a default value if not set.
$user_role = $_SESSION['user_role'] ?? '';
$dashboard_url = '';

// Determine the dashboard URL based on the user's role
if ($user_role === 'coach') {
    $dashboard_url = '/coach/coach_dashboard.php';
} elseif ($user_role === 'parent') {
    $dashboard_url = '/parent_dashboard.php';
} elseif ($user_role === 'member') {
    $dashboard_url = '/dashboard.php';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title_en; ?> | Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; }
        /* Add basic styles for the rich text editor output */
        .post-content img { max-width: 100%; height: auto; margin: 16px 0; border-radius: 8px; }
        .post-content h1, .post-content h2, .post-content h3 { font-weight: bold; margin-bottom: 8px; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white shadow-md fixed top-0 left-0 right-0 z-40">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <a href="index.html" class="font-bold text-xl text-gray-800">Catch Jiu Jitsu</a>
            
            <?php if ($dashboard_url): ?>
                <a href="<?php echo $dashboard_url; ?>" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition duration-300 ease-in-out">
                    Back to Dashboard
                </a>
            <?php endif; ?>
            
            </div>
    </nav>
    <div class="container mx-auto px-6 py-24">
        <h1 class="text-4xl font-black text-gray-800 text-center mb-10"><?php echo $page_title_en; ?> / <?php echo $page_title_zh; ?></h1>
        <div class="max-w-4xl mx-auto space-y-8">
            <?php if (empty($posts)): ?>
                <p class="text-center text-gray-500">No content found for this section.</p>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <div class="bg-white p-8 rounded-2xl shadow-lg">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($post['title']); ?></h2>
                                <h3 class="text-xl text-gray-600"><?php echo htmlspecialchars($post['title_zh']); ?></h3>
                            </div>
                            <span class="text-sm text-gray-500 mt-2 sm:mt-0"><?php echo date('F j, Y', strtotime($post['publish_date'])); ?></span>
                        </div>
                        <div class="post-content">
                            <?php echo $post['content']; ?>
                        </div>
                        <div class="post-content mt-4 border-t pt-4 text-gray-600 italic">
                            <?php echo $post['content_zh']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>