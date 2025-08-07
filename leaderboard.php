<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Since this file is in the public folder, db_config.php is in the same directory.
require_once "db_config.php"; 
session_start();

// --- AJAX HANDLER ---
// This block handles requests to fetch leaderboard data.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_leaderboard') {
    header('Content-Type: application/json');

    // This endpoint is public, so no session check is needed here.

    $filter = $_POST['filter'] ?? 'adult';
    
    $sql = "
        SELECT 
            u.first_name, 
            u.last_name, 
            u.profile_picture_url,
            COUNT(b.id) AS class_count
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        WHERE
            b.attended = 1
            AND u.role = 'member'
    ";

    if ($filter === 'adult') {
        $sql .= " AND u.member_type = 'Adult'";
    } else { // 'kid'
        $sql .= " AND u.member_type LIKE '%Kid%'";
    }

    $sql .= "
        GROUP BY u.id, u.first_name, u.last_name, u.profile_picture_url
        ORDER BY class_count DESC
        LIMIT 10
    ";

    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $leaderboard = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => true, 'leaderboard' => $leaderboard]);
    exit;
}
// --- END AJAX HANDLER ---

// No page security needed as this is a public-facing page.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Leaderboard - Catch Jiu Jitsu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        /* ---!!! UPDATED TO LIGHT THEME !!!--- */
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .card {
            background-color: white;
            border-radius: 1rem;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.07), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }
        .filter-btn {
            padding: 0.5rem 1.5rem; border-radius: 9999px; font-weight: 600;
            background-color: #e5e7eb; color: #4b5563; transition: all 0.2s;
            border: 2px solid transparent;
        }
        .filter-btn.active {
            background-color: #3b82f6; color: white;
        }
        .leaderboard-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 1rem;
            transition: all 0.2s ease-in-out;
        }
        .leaderboard-item:hover {
            transform: scale(1.02);
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            border-color: #3b82f6;
        }
        .rank-circle {
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.25rem;
            color: white;
            flex-shrink: 0;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
        }
        .rank-1 { background-color: #f59e0b; } /* Amber-500 */
        .rank-2 { background-color: #a8a29e; } /* Stone-400 */
        .rank-3 { background-color: #a16207; } /* Yellow-700 */
        .rank-other { background-color: #6b7280; } /* Gray-500 */
        .loader {
            width: 50px; height: 50px; border: 5px solid #e5e7eb;
            border-top: 5px solid #3b82f6; border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="text-gray-800">
    <div class="container mx-auto px-4 sm:px-6 py-12 max-w-4xl">
        <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
            <div>
                <h1 class="text-4xl font-black text-gray-900 lang" data-lang-en="Attendance Leaderboard" data-lang-zh="出席排行榜">Attendance Leaderboard</h1>
                <p class="text-gray-500 mt-1 lang" data-lang-en="Recognizing our most dedicated members." data-lang-zh="表彰我們最敬業的會員。">Recognizing our most dedicated members.</p>
            </div>
            <div class="flex items-center gap-4">
                <div id="lang-switcher-desktop" class="flex items-center space-x-2 text-sm">
                    <button id="lang-en-desktop" class="font-bold text-blue-600">EN</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh-desktop" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
                <a href="dashboard.php" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                    <span class="lang" data-lang-en="&larr; Back to Dashboard" data-lang-zh="&larr; 返回儀表板">&larr; Back to Dashboard</span>
                </a>
            </div>
        </div>

        <!-- Filter Controls -->
        <div id="filter-buttons" class="flex justify-center items-center space-x-4 mb-8">
            <button class="filter-btn active lang" data-filter="adult" data-lang-en="Adults" data-lang-zh="成人">Adults</button>
            <button class="filter-btn lang" data-filter="kid" data-lang-en="Kids" data-lang-zh="兒童">Kids</button>
        </div>

        <!-- Leaderboard List -->
        <div id="leaderboard-list" class="space-y-3">
            <div id="loader-container" class="flex justify-center p-12">
                <div class="loader"></div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const leaderboardList = document.getElementById('leaderboard-list');
        const filterButtons = document.getElementById('filter-buttons');
        let currentFilter = 'adult';
        let currentLang = localStorage.getItem('preferredLang') || 'en';

        function updateLanguage() {
            document.querySelectorAll('.lang').forEach(el => {
                const text = el.getAttribute(`data-lang-${currentLang}`);
                if (text) el.textContent = text;
            });
            const allEnBtns = document.querySelectorAll('#lang-en-desktop');
            const allZhBtns = document.querySelectorAll('#lang-zh-desktop');
            allEnBtns.forEach(btn => {
                btn.classList.toggle('font-bold', currentLang === 'en');
                btn.classList.toggle('text-blue-600', currentLang === 'en');
                btn.classList.toggle('text-gray-500', currentLang !== 'en');
            });
            allZhBtns.forEach(btn => {
                btn.classList.toggle('font-bold', currentLang === 'zh');
                btn.classList.toggle('text-blue-600', currentLang === 'zh');
                btn.classList.toggle('text-gray-500', currentLang !== 'zh');
            });
        }

        function setLanguage(lang) {
            currentLang = lang;
            localStorage.setItem('preferredLang', lang);
            updateLanguage();
        }

        document.querySelectorAll('#lang-en-desktop').forEach(btn => btn.addEventListener('click', () => setLanguage('en')));
        document.querySelectorAll('#lang-zh-desktop').forEach(btn => btn.addEventListener('click', () => setLanguage('zh')));

        async function fetchLeaderboard(filter = 'adult') {
            leaderboardList.innerHTML = `<div id="loader-container" class="flex justify-center p-12"><div class="loader"></div></div>`;

            const formData = new FormData();
            formData.append('action', 'get_leaderboard');
            formData.append('filter', filter);

            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                const result = await response.json();

                leaderboardList.innerHTML = '';

                if (result.success && result.leaderboard.length > 0) {
                    result.leaderboard.forEach((member, index) => {
                        const rank = index + 1;
                        const profilePic = member.profile_picture_url ? `uploads/${member.profile_picture_url.replace('uploads/', '')}` : 'https://placehold.co/80x80/e2e8f0/475569?text=:)';
                        
                        let rankClass = 'rank-other';
                        if (rank === 1) rankClass = 'rank-1';
                        if (rank === 2) rankClass = 'rank-2';
                        if (rank === 3) rankClass = 'rank-3';

                        const item = document.createElement('div');
                        item.className = 'leaderboard-item flex items-center gap-4';
                        item.innerHTML = `
                            <div class="rank-circle ${rankClass}">${rank}</div>
                            <img src="${profilePic}" alt="Profile Picture" class="w-16 h-16 rounded-full object-cover border-2 border-gray-200">
                            <div class="flex-grow">
                                <p class="text-xl font-bold text-gray-800">${member.first_name} ${member.last_name}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-2xl font-black text-blue-600">${member.class_count}</p>
                                <p class="text-sm text-gray-500 font-semibold lang" data-lang-en="Classes" data-lang-zh="課程">Classes</p>
                            </div>
                        `;
                        leaderboardList.appendChild(item);
                    });
                } else {
                    leaderboardList.innerHTML = `<p class="text-center text-gray-500 p-12 lang" data-lang-en="No attendance data found for this category yet." data-lang-zh="此類別尚無出席數據。">No attendance data found for this category yet.</p>`;
                }
            } catch (error) {
                console.error('Fetch failed:', error);
                leaderboardList.innerHTML = `<p class="text-center text-red-500 p-12 lang" data-lang-en="An error occurred while fetching the leaderboard." data-lang-zh="讀取排行榜時發生錯誤。">An error occurred while fetching the leaderboard.</p>`;
            }
            updateLanguage();
        }

        filterButtons.addEventListener('click', (e) => {
            if (e.target.tagName === 'BUTTON') {
                document.querySelector('.filter-btn.active').classList.remove('active');
                e.target.classList.add('active');
                currentFilter = e.target.dataset.filter;
                fetchLeaderboard(currentFilter);
            }
        });

        // Initial Load
        fetchLeaderboard('adult');
        updateLanguage();
    });
    </script>
</body>
</html>
