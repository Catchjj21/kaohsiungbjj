<?php
/*
 * Dedicated Member Check-In Page
 * Version: 1.1
 * Description: A standalone page for logged-in members to check into their classes.
 * Now supports parents checking in for their children.
 */

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "db_config.php";
session_start();

// --- PARENT/CHILD LOGIC ---
// This logic determines who the check-in actions are for.
$user_id_for_action = $_SESSION['id'] ?? null; // Default to the logged-in user.
$is_parent_managing = false;

// Check if a parent is managing a child
if (isset($_SESSION['role']) && $_SESSION['role'] === 'parent' && isset($_GET['id'])) {
    $child_id_from_url = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if ($child_id_from_url) {
        // Security Check: Verify the logged-in parent is the actual parent of this child.
        $parent_id = $_SESSION['id'];
        $sql_verify = "SELECT id FROM users WHERE id = ? AND parent_id = ?";
        if ($stmt_verify = mysqli_prepare($link, $sql_verify)) {
            mysqli_stmt_bind_param($stmt_verify, "ii", $child_id_from_url, $parent_id);
            mysqli_stmt_execute($stmt_verify);
            mysqli_stmt_store_result($stmt_verify);
            if (mysqli_stmt_num_rows($stmt_verify) == 1) {
                $user_id_for_action = $child_id_from_url; // Override with the child's ID.
                $is_parent_managing = true;
            }
            mysqli_stmt_close($stmt_verify);
        }
    }
}

// If no user is identified, stop.
if (!$user_id_for_action) {
    header("location: login.html");
    exit;
}
// --- END PARENT/CHILD LOGIC ---


// --- AJAX ROUTER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }

    // IMPORTANT: Use the determined user ID for all actions.
    $user_id = $user_id_for_action;
    $action = $_POST['action'];
    header('Content-Type: application/json');

    if ($action === 'get_my_check_in_details') {
        $sql = "
            SELECT 
                u.id, u.first_name, u.last_name, u.profile_picture_url, u.chinese_name, u.default_language, u.belt_color,
                m.end_date, m.membership_type, m.class_credits,
                (SELECT COUNT(*) FROM bookings WHERE user_id = u.id AND booking_date >= DATE_FORMAT(CURDATE(), '%Y-01-01') AND attended = 1) as year_classes,
                (SELECT COUNT(*) FROM bookings WHERE user_id = u.id AND booking_date >= CURDATE() - INTERVAL 30 DAY AND attended = 1) as month_classes
            FROM users u
            LEFT JOIN memberships m ON u.id = m.user_id AND m.status = 'active' AND m.end_date >= CURDATE()
            WHERE u.id = ? LIMIT 1";
        
        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $member_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($member_data) {
            $checkin_today_sql = "SELECT COUNT(*) AS count FROM bookings WHERE user_id = ? AND booking_date = CURDATE() AND attended = 1";
            $stmt_checkin = mysqli_prepare($link, $checkin_today_sql);
            mysqli_stmt_bind_param($stmt_checkin, "i", $user_id);
            mysqli_stmt_execute($stmt_checkin);
            $checkin_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_checkin))['count'];
            mysqli_stmt_close($stmt_checkin);
            
            $member_data['already_checked_in'] = $checkin_count > 0;
            $member_data['is_active'] = ($member_data['end_date'] !== null);
            
            $member_data['attended_classes_today'] = [];
            if ($member_data['already_checked_in']) {
                $attended_sql = "
                    SELECT c.start_time, c.name, c.name_zh, TIMESTAMPDIFF(MINUTE, c.start_time, c.end_time) AS duration 
                    FROM bookings b JOIN classes c ON b.class_id = c.id 
                    WHERE b.user_id = ? AND b.booking_date = CURDATE() AND b.attended = 1";
                $stmt_attended = mysqli_prepare($link, $attended_sql);
                mysqli_stmt_bind_param($stmt_attended, "i", $user_id);
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
                    FROM bookings b JOIN classes c ON b.class_id = c.id 
                    WHERE b.user_id = ? AND b.booking_date = CURDATE() AND b.attended = 0";
                $stmt_classes = mysqli_prepare($link, $booked_classes_sql);
                mysqli_stmt_bind_param($stmt_classes, "i", $user_id);
                mysqli_stmt_execute($stmt_classes);
                $classes_result = mysqli_stmt_get_result($stmt_classes);
                while ($class_row = mysqli_fetch_assoc($classes_result)) {
                    $member_data['classes_booked'][] = $class_row;
                }
                mysqli_stmt_close($stmt_classes);
            }
            echo json_encode(['success' => true, 'data' => $member_data]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Member details could not be found.']);
        }
    }

    if ($action === 'check_in_classes_member') {
        $booking_ids = $_POST['booking_ids'] ?? [];
        if (empty($booking_ids) || !is_array($booking_ids)) {
            echo json_encode(['success' => false, 'message' => 'No classes were selected.']);
            exit;
        }
        $update_sql = "UPDATE bookings SET attended = 1 WHERE id = ? AND user_id = ? AND booking_date = CURDATE()";
        $stmt = mysqli_prepare($link, $update_sql);
        $success_count = 0;
        foreach ($booking_ids as $booking_id) {
            mysqli_stmt_bind_param($stmt, "ii", $booking_id, $user_id);
            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
                $success_count++;
            }
        }
        mysqli_stmt_close($stmt);
        if ($success_count > 0) {
            echo json_encode(['success' => true, 'message' => "Successfully checked in for {$success_count} class(es)!"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No bookings were updated. They may have already been checked in.']);
        }
    }

    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---

// Determine the correct dashboard link
$dashboard_link = "dashboard.php";
if ($is_parent_managing) {
    $dashboard_link = "manage_child.php?id=" . $user_id_for_action;
} elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'parent') {
    $dashboard_link = "parents_dashboard.php";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Today's Check-In - Catch Jiu Jitsu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .card {
            background-color: white; border-radius: 1.5rem;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        .status-badge {
            border-radius: 9999px; padding: 0.25rem 0.75rem; font-size: 0.875rem;
            font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .status-active { background-color: #dcfce7; color: #166534; }
        .status-inactive { background-color: #fee2e2; color: #991b1b; }
        .class-label { transition: all 0.2s ease-in-out; cursor: pointer; }
        .class-label:hover { border-color: #3b82f6; background-color: #eff6ff; }
        input[type="checkbox"]:checked + .class-label {
            border-color: #2563eb; background-color: #dbeafe; box-shadow: 0 0 0 2px #60a5fa;
        }
        .belt {
            padding: 0.25rem 0.75rem; border-radius: 0.5rem; font-weight: 700;
            color: white; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        .loader {
            width: 50px; height: 50px; border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db; border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 sm:px-6 py-12 max-w-3xl">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Today's Check-In</h1>
            <a href="<?php echo $dashboard_link; ?>" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                &larr; Back to Dashboard
            </a>
        </div>
        <div id="check-in-content" class="card">
            <div class="text-center p-12">
                <div class="loader mx-auto"></div>
                <p class="mt-6 text-gray-600">Loading check-in details...</p>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const checkInContent = document.getElementById('check-in-content');
        let currentLang = localStorage.getItem('preferredLang') || 'en';

        async function loadCheckInDetails() {
            const formData = new FormData();
            formData.append('action', 'get_my_check_in_details');
            try {
                // Pass the child ID in the URL for the fetch request if it exists
                const url = new URL(window.location.href);
                const fetchUrl = url.search ? `${window.location.pathname}${url.search}` : window.location.pathname;

                const response = await fetch(fetchUrl, { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    renderCheckInStatus(result.data);
                } else {
                    renderError(result.message);
                }
            } catch (error) {
                console.error('Failed to load check-in details:', error);
                renderError('A network error occurred while loading your details.');
            }
        }

        function renderError(message) {
            checkInContent.innerHTML = `<div class="p-8 text-center text-red-600">${message}</div>`;
        }

        function renderCheckInStatus(data) {
            const language = currentLang;
            const name = language === 'zh' && data.chinese_name ? data.chinese_name : data.first_name;
            const profilePic = data.profile_picture_url ? data.profile_picture_url : 'https://placehold.co/150x150/e2e8f0/475569?text=:)';
            
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

            const beltColors = { 'White': '#f8fafc', 'Blue': '#3b82f6', 'Purple': '#8b5cf6', 'Brown': '#78350f', 'Black': '#18181b' };
            const beltTextColor = data.belt_color === 'White' ? '#18181b' : '#ffffff';
            const beltHtml = data.belt_color ? `<span class="belt" style="background-color: ${beltColors[data.belt_color] || '#6b7280'}; color: ${beltTextColor};">${data.belt_color} Belt</span>` : '';

            const html = `
                <div class="p-6 md:p-8">
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
                    <div class="mt-8 border-t border-gray-100 pt-6 space-y-4">
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
                    <div class="mt-6 border-t border-gray-100 pt-6">
                        ${generateClassSection(data, language)}
                    </div>
                </div>`;
            
            checkInContent.innerHTML = html;
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
                return `<h3 class="text-center text-xl font-bold text-gray-700 mb-4">Attended Classes Today</h3><ul class="space-y-3">${attendedList}</ul>`;
            }
            if (!data.is_active) {
                return `<p class="text-center text-gray-500">Please renew membership to check in.</p>`;
            }
            if (data.classes_booked.length === 0) {
                return `<p class="text-center text-gray-500">No classes booked for today.</p>`;
            }

            const classCheckboxes = data.classes_booked.map(cls => {
                const className = language === 'zh' && cls.name_zh ? cls.name_zh : cls.name;
                const classTime = cls.start_time.substring(0, 5);
                const checkboxId = `booking-checkin-${cls.id}`;
                return `
                    <div>
                        <input type="checkbox" name="class_selection_checkin" value="${cls.id}" id="${checkboxId}" class="hidden">
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
                <button class="multi-check-in-button-member w-full mt-6 py-4 px-6 bg-blue-600 text-white font-bold text-lg rounded-xl hover:bg-blue-700 transition">
                    Check In for Selected Classes
                </button>`;
        }
        
        checkInContent.addEventListener('click', async function(e) {
            const checkInButton = e.target.closest('.multi-check-in-button-member');
            if (!checkInButton) return;

            const selectedCheckboxes = checkInContent.querySelectorAll('input[name="class_selection_checkin"]:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Please select at least one class.');
                return;
            }

            const bookingIds = Array.from(selectedCheckboxes).map(cb => cb.value);
            
            checkInButton.disabled = true;
            checkInButton.textContent = 'Processing...';

            const formData = new FormData();
            formData.append('action', 'check_in_classes_member');
            bookingIds.forEach(id => formData.append('booking_ids[]', id));
            
            try {
                const url = new URL(window.location.href);
                const fetchUrl = url.search ? `${window.location.pathname}${url.search}` : window.location.pathname;

                const response = await fetch(fetchUrl, { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    checkInContent.innerHTML = `<div class="p-8 text-center text-green-600">${result.message}</div>`;
                    setTimeout(loadCheckInDetails, 2000);
                } else {
                    alert(result.message);
                    checkInButton.disabled = false;
                    checkInButton.textContent = 'Check In for Selected Classes';
                }
            } catch (error) {
                alert('A network error occurred.');
                checkInButton.disabled = false;
                checkInButton.textContent = 'Check In for Selected Classes';
            }
        });

        // Initial load
        loadCheckInDetails();
    });
    </script>
</body>
</html>
