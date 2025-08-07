<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// From /admin/member_directory.php, go up one level to /public_html/ to find db_config.php
require_once "../db_config.php"; 
session_start();

// --- AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_members') {
    header('Content-Type: application/json');

    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $search_term = $_POST['search_term'] ?? '';
    $filter = $_POST['filter'] ?? 'all';
    $search_param = "%" . $search_term . "%";

    // ---!!! UPDATED SQL: Added dynamic filtering for member_type !!!---
    $sql = "
        SELECT 
            id, first_name, last_name, chinese_name, profile_picture_url, belt_color,
            TIMESTAMPDIFF(YEAR, dob, CURDATE()) AS age
        FROM users
        WHERE 
            (role = 'member') AND 
            (first_name LIKE ? OR last_name LIKE ? OR chinese_name LIKE ?)
    ";

    // Add the member type filter condition to the SQL query
    if ($filter === 'adult') {
        $sql .= " AND member_type = 'Adult'";
    } elseif ($filter === 'kid') {
        $sql .= " AND member_type LIKE '%Kid%'";
    }

    $sql .= " ORDER BY first_name, last_name";

    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "sss", $search_param, $search_param, $search_param);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $members = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => true, 'members' => $members]);
    exit;
}
// --- END AJAX HANDLER ---

// Page Security
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    header("location: ../login.html");
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Member Directory - Catch Jiu Jitsu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .card {
            background-color: white;
            border-radius: 1rem;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.07), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        .loader {
            width: 40px; height: 40px; border: 4px solid #e5e7eb;
            border-top: 4px solid #3b82f6; border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .belt-bar {
            height: 10px; width: 80%; margin: 0.75rem auto 0;
            border-radius: 9999px; border: 1px solid rgba(0,0,0,0.1);
        }
        .filter-btn {
            padding: 0.5rem 1rem; border-radius: 0.75rem; font-weight: 600;
            background-color: #e5e7eb; color: #4b5563; transition: all 0.2s;
        }
        .filter-btn.active {
            background-color: #3b82f6; color: white;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 sm:px-6 py-12 max-w-7xl">
        <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Member Directory</h1>
                <p class="text-gray-500 mt-1">Search and view all active members.</p>
            </div>
            <a href="coach_dashboard.php" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                &larr; Back to Dashboard
            </a>
        </div>

        <!-- Search and Filter Controls -->
        <div class="flex flex-col sm:flex-row gap-4 mb-8">
            <input type="text" id="search-input" placeholder="Search by name (English or Chinese)..." class="w-full p-4 text-lg border-2 border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-300 focus:border-blue-500 transition">
            <div id="filter-buttons" class="flex-shrink-0 flex items-center space-x-2">
                <button class="filter-btn active" data-filter="all">All</button>
                <button class="filter-btn" data-filter="adult">Adults</button>
                <button class="filter-btn" data-filter="kid">Kids</button>
            </div>
        </div>

        <!-- Member Grid -->
        <div id="member-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
            <div id="loader-container" class="col-span-full flex justify-center p-12">
                <div class="loader"></div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('search-input');
        const memberGrid = document.getElementById('member-grid');
        const loaderContainer = document.getElementById('loader-container');
        const filterButtons = document.getElementById('filter-buttons');
        let debounceTimer;
        let currentFilter = 'all';

        const beltColors = {
            'White': '#f8fafc', 'Blue': '#3b82f6', 'Purple': '#8b5cf6', 
            'Brown': '#78350f', 'Black': '#18181b'
        };

        async function searchMembers(searchTerm = '', filter = 'all') {
            loaderContainer.style.display = 'flex';
            memberGrid.innerHTML = '';
            memberGrid.appendChild(loaderContainer);

            const formData = new FormData();
            formData.append('action', 'search_members');
            formData.append('search_term', searchTerm);
            formData.append('filter', filter);

            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                const result = await response.json();

                loaderContainer.style.display = 'none';
                memberGrid.innerHTML = '';

                if (result.success && result.members.length > 0) {
                    result.members.forEach(member => {
                        const profilePic = member.profile_picture_url ? `../uploads/${member.profile_picture_url.replace('uploads/', '')}` : 'https://placehold.co/200x200/e2e8f0/475569?text=:)';
                        const beltName = member.belt_color ? member.belt_color.split(' ')[0] : '';
                        const beltColor = beltColors[beltName] || '#6b7280';
                        
                        const card = document.createElement('div');
                        card.className = 'card text-center p-6';
                        card.innerHTML = `
                            <img src="${profilePic}" alt="Profile Picture" class="w-32 h-32 rounded-full object-cover mx-auto border-4 border-white shadow-md">
                            <h3 class="mt-4 text-xl font-bold text-gray-800">${member.first_name} ${member.last_name}</h3>
                            ${member.chinese_name ? `<p class="text-gray-500">${member.chinese_name}</p>` : ''}
                            <div class="belt-bar" style="background-color: ${beltColor};"></div>
                            <p class="mt-3 text-sm bg-gray-100 text-gray-600 font-semibold inline-block px-3 py-1 rounded-full">${member.age} years old</p>
                        `;
                        memberGrid.appendChild(card);
                    });
                } else {
                    memberGrid.innerHTML = `<p class="col-span-full text-center text-gray-500 p-12">No members found.</p>`;
                }
            } catch (error) {
                console.error('Search failed:', error);
                loaderContainer.style.display = 'none';
                memberGrid.innerHTML = `<p class="col-span-full text-center text-red-500 p-12">An error occurred while searching.</p>`;
            }
        }

        searchInput.addEventListener('keyup', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                searchMembers(searchInput.value, currentFilter);
            }, 300);
        });

        filterButtons.addEventListener('click', (e) => {
            if (e.target.tagName === 'BUTTON') {
                document.querySelector('.filter-btn.active').classList.remove('active');
                e.target.classList.add('active');
                currentFilter = e.target.dataset.filter;
                searchMembers(searchInput.value, currentFilter);
            }
        });

        // Initial load of all members
        searchMembers('', 'all');
    });
    </script>
</body>
</html>
