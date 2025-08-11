<?php
/*
 * The Ultimate Gym Check-In System
 * Version: 3.9 (Enhanced Features)
 * Description: A complete, modern, and robust check-in system.
 */

// --- GLOBAL ERROR HANDLER ---
function handle_exception($e) {
    error_log("Check-In System Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
set_exception_handler('handle_exception');


// --- ROUTER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'get_member_details') {
        get_member_details($input['member_id'] ?? '');
    } elseif ($action === 'check_in_multiple_bookings') {
        check_in_multiple_bookings($input['booking_ids'] ?? [], $input['user_id'] ?? 0);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
    }
    exit;
}


// --- CORE FUNCTIONS ---
function get_member_details($member_id) {
    header('Content-Type: application/json');

    if (empty($member_id) || !is_numeric($member_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid Member ID format.']);
        return;
    }
    
    $db_config_path = __DIR__ . '/../db_config.php';
    if (!file_exists($db_config_path)) {
        throw new Exception("Configuration Error: db_config.php not found. The server is looking for it at this exact path: " . realpath(__DIR__ . '/..') . '/db_config.php');
    }
    require_once $db_config_path;
    
    session_start();
    if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true) {
        echo json_encode(['success' => false, 'message' => 'Authentication Error. Please log in again.']);
        return;
    }

    // --- SQL QUERY (Added u.belt_color) ---
    $sql = "
        SELECT 
            u.id, u.first_name, u.last_name, u.profile_picture_url, u.chinese_name, u.default_language, u.belt_color,
            m.end_date, m.membership_type, m.class_credits,
            (SELECT COUNT(*) FROM bookings WHERE user_id = u.id AND booking_date >= DATE_FORMAT(CURDATE(), '%Y-01-01') AND attended = 1) as year_classes,
            (SELECT COUNT(*) FROM bookings WHERE user_id = u.id AND booking_date >= CURDATE() - INTERVAL 30 DAY AND attended = 1) as month_classes
        FROM 
            users u
        LEFT JOIN 
            memberships m ON u.id = m.user_id AND m.status = 'active' AND m.end_date >= CURDATE()
        WHERE 
            u.old_card = ? AND u.role IN ('member', 'coach', 'admin')
        LIMIT 1
    ";

    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "s", $member_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($member_data = mysqli_fetch_assoc($result)) {
        $user_db_id = $member_data['id'];
        
        $checkin_today_sql = "SELECT COUNT(*) AS count FROM bookings WHERE user_id = ? AND booking_date = CURDATE() AND attended = 1";
        $stmt_checkin = mysqli_prepare($link, $checkin_today_sql);
        mysqli_stmt_bind_param($stmt_checkin, "i", $user_db_id);
        mysqli_stmt_execute($stmt_checkin);
        $checkin_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_checkin))['count'];
        mysqli_stmt_close($stmt_checkin);
        
        $member_data['already_checked_in'] = $checkin_count > 0;
        $member_data['is_active'] = ($member_data['end_date'] !== null);
        
        // --- NEW: Fetch attended classes if already checked in ---
        $member_data['attended_classes_today'] = [];
        if ($member_data['already_checked_in']) {
             $attended_sql = "
                SELECT c.start_time, c.name, c.name_zh, TIMESTAMPDIFF(MINUTE, c.start_time, c.end_time) AS duration 
                FROM bookings b 
                JOIN classes c ON b.class_id = c.id 
                WHERE b.user_id = ? AND b.booking_date = CURDATE() AND b.attended = 1";
            $stmt_attended = mysqli_prepare($link, $attended_sql);
            mysqli_stmt_bind_param($stmt_attended, "i", $user_db_id);
            mysqli_stmt_execute($stmt_attended);
            $attended_result = mysqli_stmt_get_result($stmt_attended);
            while ($class_row = mysqli_fetch_assoc($attended_result)) {
                $member_data['attended_classes_today'][] = $class_row;
            }
            mysqli_stmt_close($stmt_attended);
        }

        $member_data['classes_booked'] = [];
        if ($member_data['is_active'] && !$member_data['already_checked_in']) {
            $booked_classes_sql = "
                SELECT b.id, c.start_time, c.name, c.name_zh, TIMESTAMPDIFF(MINUTE, c.start_time, c.end_time) AS duration 
                FROM bookings b 
                JOIN classes c ON b.class_id = c.id 
                WHERE b.user_id = ? AND b.booking_date = CURDATE() AND b.attended = 0";
            $stmt_classes = mysqli_prepare($link, $booked_classes_sql);
            mysqli_stmt_bind_param($stmt_classes, "i", $user_db_id);
            mysqli_stmt_execute($stmt_classes);
            $classes_result = mysqli_stmt_get_result($stmt_classes);
            while ($class_row = mysqli_fetch_assoc($classes_result)) {
                $member_data['classes_booked'][] = $class_row;
            }
            mysqli_stmt_close($stmt_classes);
        }

        echo json_encode(['success' => true, 'data' => $member_data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
    }
    mysqli_stmt_close($stmt);
    mysqli_close($link);
}

function check_in_multiple_bookings($booking_ids, $user_id) {
    header('Content-Type: application/json');

    if (empty($booking_ids) || !is_array($booking_ids) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid Booking IDs or User ID.']);
        return;
    }

    $db_config_path = __DIR__ . '/../db_config.php';
    if (!file_exists($db_config_path)) {
        throw new Exception("Configuration Error: 'db_config.php' not found.");
    }
    require_once $db_config_path;

    $update_sql = "UPDATE bookings SET attended = 1 WHERE id = ? AND user_id = ? AND booking_date = CURDATE()";
    $stmt = mysqli_prepare($link, $update_sql);
    
    $success_count = 0;
    foreach ($booking_ids as $booking_id) {
        mysqli_stmt_bind_param($stmt, "ii", $booking_id, $user_id);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
            $success_count++;
        }
    }
    
    if ($success_count > 0) {
        echo json_encode(['success' => true, 'message' => "Successfully checked in for {$success_count} class(es)!"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No bookings were updated. They may have already been checked in.']);
    }
    mysqli_stmt_close($stmt);
    mysqli_close($link);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Check-In System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .card {
            background-color: white;
            border-radius: 1.5rem;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            transition: all 0.3s ease-in-out;
        }
        .status-badge {
            border-radius: 9999px; padding: 0.25rem 0.75rem; font-size: 0.875rem;
            font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .status-active { background-color: #dcfce7; color: #166534; }
        .status-inactive { background-color: #fee2e2; color: #991b1b; }
        .class-label {
            transition: all 0.2s ease-in-out;
            cursor: pointer;
        }
        .class-label:hover {
            border-color: #3b82f6; /* blue-500 */
            background-color: #eff6ff; /* blue-50 */
        }
        input[type="checkbox"]:checked + .class-label {
            border-color: #2563eb; /* blue-600 */
            background-color: #dbeafe; /* blue-100 */
            box-shadow: 0 0 0 2px #60a5fa; /* blue-400 ring */
        }
        .belt {
            padding: 0.25rem 0.75rem;
            border-radius: 0.5rem;
            font-weight: 700;
            color: white;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        .loader {
            width: 50px; height: 50px; border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db; border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

    <div id="check-in-app" class="w-full max-w-3xl mx-auto">

        <!-- Resting/Initial Screen -->
        <div id="resting-screen" class="card p-8 md:p-12 text-center">
            <img src="../logo.png" alt="Gym Logo" class="mx-auto h-20 w-auto mb-6">
            <h1 class="text-4xl font-black text-gray-800">Member Check-In</h1>
            <p class="text-gray-500 mt-2 mb-8">Please enter your Member ID below to begin.</p>
            <form id="check-in-form" class="max-w-sm mx-auto">
                <input id="member-id-input" type="tel" class="w-full p-4 text-center text-2xl font-bold tracking-widest bg-gray-100 border-2 border-gray-200 rounded-xl focus:ring-4 focus:ring-blue-300 focus:border-blue-500 transition" placeholder="MEMBER ID" required>
                <button type="submit" class="w-full mt-4 py-4 px-6 bg-blue-600 text-white font-bold text-lg rounded-xl hover:bg-blue-700 transition-transform hover:scale-105">Find Member</button>
            </form>
        </div>

        <!-- Loading Screen -->
        <div id="loading-screen" class="card p-12 text-center hidden">
            <div class="loader mx-auto"></div>
            <p class="mt-6 text-gray-600 font-semibold">Searching for member...</p>
        </div>

        <!-- Status Screen (Success or Error) -->
        <div id="status-screen" class="hidden"></div>
    
    </div>

    <!-- Audio elements -->
    <audio id="sound-success" src="active.wav"></audio>
    <audio id="sound-error" src="expired.mp3"></audio>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const app = document.getElementById('check-in-app');
        const restingScreen = document.getElementById('resting-screen');
        const loadingScreen = document.getElementById('loading-screen');
        const statusScreen = document.getElementById('status-screen');
        const checkInForm = document.getElementById('check-in-form');
        const memberIdInput = document.getElementById('member-id-input');

        const soundSuccess = document.getElementById('sound-success');
        const soundError = document.getElementById('sound-error');

        let resetTimeout;

        // --- API Communication ---
        async function apiCall(action, data) {
            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action, ...data })
                });
                
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    const textError = await response.text();
                    throw new Error(`Server returned a non-JSON response. Check the Network tab for details. Response: ${textError.substring(0, 200)}...`);
                }

                if (!response.ok) {
                    const errorResult = await response.json().catch(() => ({ message: 'An unknown server error occurred.' }));
                    throw new Error(errorResult.message);
                }
                return await response.json();
            } catch (error) {
                console.error('API Call Failed:', error);
                return { success: false, message: error.message };
            }
        }

        // --- Event Handlers ---
        checkInForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const memberId = memberIdInput.value.trim();
            if (!memberId) return;

            clearTimeout(resetTimeout);
            showScreen('loading-screen'); 
            
            const result = await apiCall('get_member_details', { member_id: memberId });

            if (result.success) {
                renderMemberStatus(result.data);
                soundSuccess.play().catch(err => console.error("Success sound failed", err));
            } else {
                renderError(result.message);
                soundError.play().catch(err => console.error("Error sound failed", err));
            }
        });

        app.addEventListener('click', async (e) => {
            const multiCheckInButton = e.target.closest('.multi-check-in-button');
            const resetButton = e.target.closest('.reset-button');

            if (multiCheckInButton) {
                const selectedCheckboxes = document.querySelectorAll('input[name="class_selection"]:checked');
                if (selectedCheckboxes.length === 0) {
                    alert('Please select at least one class to check in.');
                    return;
                }

                const bookingIds = Array.from(selectedCheckboxes).map(cb => cb.value);
                const userId = multiCheckInButton.dataset.userId;
                const userName = multiCheckInButton.dataset.userName;

                multiCheckInButton.disabled = true;
                multiCheckInButton.innerHTML = 'Checking in...';

                const result = await apiCall('check_in_multiple_bookings', { booking_ids: bookingIds, user_id: userId });
                if(result.success) {
                    renderSuccess('Checked In!', `Have a great time, ${userName}!`);
                } else {
                    renderError(result.message);
                }
            }
            if (resetButton) {
                resetApp();
            }
        });

        // --- UI Rendering ---
        function showScreen(screenName) {
            restingScreen.classList.add('hidden');
            loadingScreen.classList.add('hidden');
            statusScreen.classList.add('hidden');
            document.getElementById(screenName).classList.remove('hidden');
        }

        function resetApp() {
            clearTimeout(resetTimeout);
            memberIdInput.value = '';
            showScreen('resting-screen');
        }

        function renderError(message) {
            const lang = { en: 'Error', zh: '錯誤' };
            const html = `
                <div class="card p-8 md:p-12 text-center bg-red-50 border-2 border-red-200">
                    <h2 class="text-3xl font-bold text-red-800">${lang.en} / ${lang.zh}</h2>
                    <p class="text-red-600 mt-4 text-lg" style="word-wrap: break-word;">${message}</p>
                    <button class="reset-button w-full md:w-auto mt-8 py-3 px-8 bg-red-600 text-white font-bold rounded-xl hover:bg-red-700 transition">Try Again</button>
                </div>`;
            statusScreen.innerHTML = html;
            showScreen('status-screen');
            resetTimeout = setTimeout(resetApp, 15000);
        }

        function renderSuccess(title, message) {
            const html = `
                <div class="card p-8 md:p-12 text-center bg-green-50 border-2 border-green-200">
                    <h2 class="text-3xl font-bold text-green-800">${title}</h2>
                    <p class="text-green-600 mt-4 text-lg">${message}</p>
                </div>`;
            statusScreen.innerHTML = html;
            showScreen('status-screen');
            resetTimeout = setTimeout(resetApp, 5000);
        }

        function renderMemberStatus(data) {
            const language = data.default_language || 'en';
            const name = language === 'zh' && data.chinese_name ? data.chinese_name : data.first_name;
            const profilePic = data.profile_picture_url ? `../uploads/${data.profile_picture_url.replace('uploads/', '')}` : 'https://placehold.co/150x150/e2e8f0/475569?text=:)';
            
            let statusBadge, statusMessage;
            if (data.is_active && !data.already_checked_in) {
                statusBadge = `<span class="status-badge status-active">Active</span>`;
                statusMessage = language === 'zh' ? `歡迎, ${name}!` : `Welcome, ${name}!`;
            } else if (data.already_checked_in) {
                statusBadge = `<span class="status-badge status-inactive">Checked In</span>`;
                statusMessage = language === 'zh' ? '今日已簽到' : 'Already Checked In Today';
            } else {
                statusBadge = `<span class="status-badge status-inactive">Expired</span>`;
                statusMessage = language === 'zh' ? '會員資格已過期' : 'Membership Expired';
            }

            const beltColors = {
                'White': '#f8fafc', 'Blue': '#3b82f6', 'Purple': '#8b5cf6', 'Brown': '#78350f', 'Black': '#18181b'
            };
            const beltTextColor = data.belt_color === 'White' ? '#18181b' : '#ffffff';
            const beltHtml = data.belt_color ? `<span class="belt" style="background-color: ${beltColors[data.belt_color] || '#6b7280'}; color: ${beltTextColor};">${data.belt_color} Belt</span>` : '';

            const html = `
                <div class="card p-6 md:p-8">
                    <!-- Member Header -->
                    <div class="flex flex-col md:flex-row items-center gap-6">
                        <img src="${profilePic}" alt="Profile Picture" class="w-28 h-28 rounded-full object-cover border-4 border-white shadow-lg">
                        <div class="text-center md:text-left flex-grow">
                            <div class="flex items-center justify-center md:justify-start gap-4">
                                <h2 class="text-4xl font-black text-gray-800">${name}</h2>
                                ${beltHtml}
                            </div>
                            <p class="text-gray-500 text-lg mt-2">${statusMessage}</p>
                        </div>
                        ${statusBadge}
                    </div>

                    <!-- Membership & Stats -->
                    <div class="mt-8 border-t border-gray-100 pt-6 space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                             <div>
                                <p class="text-sm font-semibold text-gray-400 uppercase">Membership</p>
                                <p class="text-xl font-bold text-gray-700">${data.membership_type || 'N/A'}</p>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-400 uppercase">Expires</p>
                                <p class="text-xl font-bold text-gray-700">${data.end_date || 'N/A'}</p>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-400 uppercase">Credits</p>
                                <p class="text-xl font-bold text-gray-700">${data.class_credits === null ? 'Unlimited' : data.class_credits}</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-center bg-gray-50 p-4 rounded-xl">
                             <div>
                                <p class="text-sm font-semibold text-gray-400 uppercase">Classes This Month</p>
                                <p class="text-2xl font-bold text-blue-600">${data.month_classes}</p>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-400 uppercase">Classes This Year</p>
                                <p class="text-2xl font-bold text-blue-600">${data.year_classes}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Class Check-in Section -->
                    <div id="class-check-in-section" class="mt-6 border-t border-gray-100 pt-6">
                        ${generateClassSection(data, language)}
                    </div>
                </div>`;
            
            statusScreen.innerHTML = html;
            showScreen('status-screen');
            resetTimeout = setTimeout(resetApp, 30000);
        }

        function generateClassSection(data, language) {
            if (data.already_checked_in) {
                const attendedList = data.attended_classes_today.map(cls => {
                    const className = language === 'zh' && cls.name_zh ? cls.name_zh : cls.name;
                    const classTime = cls.start_time.substring(0, 5);
                    return `<li class="p-4 bg-green-50 border-2 border-green-200 rounded-xl flex items-center gap-4">
                                <span class="text-xl font-bold text-green-700 bg-green-100 rounded-lg px-3 py-1">${classTime}</span>
                                <div><p class="font-bold text-green-800">${className}</p></div>
                                <span class="ml-auto font-bold text-green-600">&#10003; Attended</span>
                            </li>`;
                }).join('');
                return `<h3 class="text-center text-xl font-bold text-gray-700 mb-4">Attended Classes Today</h3><ul class="space-y-3">${attendedList}</ul><button class="reset-button w-full mt-6 py-3 px-6 bg-gray-200 text-gray-700 font-bold rounded-xl">Done</button>`;
            }
            if (!data.is_active) {
                return `<p class="text-center text-gray-500">Please renew membership to check in.</p><button class="reset-button w-full mt-4 py-3 px-6 bg-gray-200 text-gray-700 font-bold rounded-xl">Done</button>`;
            }
            if (data.classes_booked.length === 0) {
                return `<p class="text-center text-gray-500">No classes booked for today.</p><button class="reset-button w-full mt-4 py-3 px-6 bg-gray-200 text-gray-700 font-bold rounded-xl">Done</button>`;
            }

            const classCheckboxes = data.classes_booked.map(cls => {
                const className = language === 'zh' && cls.name_zh ? cls.name_zh : cls.name;
                const classTime = cls.start_time.substring(0, 5);
                const checkboxId = `booking-${cls.id}`;
                return `
                    <div>
                        <input type="checkbox" name="class_selection" value="${cls.id}" id="${checkboxId}" class="hidden">
                        <label for="${checkboxId}" class="class-label w-full text-left p-4 bg-gray-50 border-2 border-gray-200 rounded-xl flex items-center gap-4">
                            <span class="text-xl font-bold text-blue-600 bg-blue-100 rounded-lg px-3 py-1">${classTime}</span>
                            <div>
                                <p class="font-bold text-gray-800">${className}</p>
                                <p class="text-sm text-gray-500">${cls.duration} mins</p>
                            </div>
                            <span class="ml-auto text-gray-400 font-bold">&#10003;</span>
                        </label>
                    </div>`;
            }).join('');

            return `
                <h3 class="text-center text-xl font-bold text-gray-700 mb-4">Select Classes to Check In</h3>
                <div class="space-y-3">${classCheckboxes}</div>
                <button class="multi-check-in-button w-full mt-6 py-4 px-6 bg-blue-600 text-white font-bold text-lg rounded-xl hover:bg-blue-700 transition"
                        data-user-id="${data.id}"
                        data-user-name="${data.first_name}">
                    Check In for Selected Classes
                </button>`;
        }
    });
    </script>
</body>
</html>
