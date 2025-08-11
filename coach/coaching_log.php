<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set the timezone to ensure correct date operations
date_default_timezone_set('Asia/Taipei');

require_once "../db_config.php";
session_start();

// --- Page Security ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    header("location: ../login.html");    
    exit;
}

$user_id = $_SESSION['id'];

// --- AJAX ROUTER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    
    $action = $_POST['action'];
    header('Content-Type: application/json');

    switch ($action) {
        case 'get_unlogged_classes':
            $sql = "SELECT c.id as class_id, c.name, c.name_zh, c.day_of_week, c.start_time, c.end_time, 
                           DATE(b.booking_date) as class_date, COUNT(b.id) as attendance_count
                    FROM classes c
                    LEFT JOIN bookings b ON c.id = b.class_id AND b.attended = 1
                    LEFT JOIN coaching_logs cl ON c.id = cl.class_id AND DATE(b.booking_date) = cl.class_date
                    WHERE c.coach_id = ? AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND cl.id IS NULL
                    GROUP BY c.id, DATE(b.booking_date)
                    ORDER BY b.booking_date DESC";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $classes = mysqli_fetch_all($result, MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'classes' => $classes]);
            break;

        case 'get_log_history':
            $sql = "SELECT cl.id, cl.class_id, cl.class_date, cl.techniques_taught, cl.attendance_count, 
                           cl.what_went_well, cl.improvements_needed, cl.notes, c.name, c.name_zh
                    FROM coaching_logs cl
                    LEFT JOIN classes c ON cl.class_id = c.id
                    WHERE cl.coach_id = ?
                    ORDER BY cl.class_date DESC, cl.id DESC";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $history = mysqli_fetch_all($result, MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        case 'save_log_entry':
            $log_id = !empty($_POST['log_id']) ? $_POST['log_id'] : null;
            $class_id = $_POST['class_id'] ?? '';
            $class_date = $_POST['class_date'] ?? '';
            $techniques_taught = $_POST['techniques_taught'] ?? '';
            $attendance_count = $_POST['attendance_count'] ?? 0;
            $what_went_well = $_POST['what_went_well'] ?? '';
            $improvements_needed = $_POST['improvements_needed'] ?? '';
            $notes = $_POST['notes'] ?? '';

            if (empty($class_id) || empty($class_date)) {
                echo json_encode(['success' => false, 'message' => 'Class and Date are required fields.']);
                exit;
            }

            if ($log_id) { // This is an UPDATE
                $sql = "UPDATE coaching_logs SET class_id = ?, class_date = ?, techniques_taught = ?, attendance_count = ?, what_went_well = ?, improvements_needed = ?, notes = ? WHERE id = ? AND coach_id = ?";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "ississsii", $class_id, $class_date, $techniques_taught, $attendance_count, $what_went_well, $improvements_needed, $notes, $log_id, $user_id);
            } else { // This is an INSERT
                $sql = "INSERT INTO coaching_logs (coach_id, class_id, class_date, techniques_taught, attendance_count, what_went_well, improvements_needed, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "iississs", $user_id, $class_id, $class_date, $techniques_taught, $attendance_count, $what_went_well, $improvements_needed, $notes);
            }
            
            if (mysqli_stmt_execute($stmt)) {
                $message = $log_id ? 'Coaching log updated successfully!' : 'Coaching log saved successfully!';
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save coaching log.']);
            }
            break;

        case 'delete_log_entry':
            $log_id = $_POST['log_id'] ?? 0;
            if (empty($log_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid Log ID.']);
                exit;
            }
            $sql = "DELETE FROM coaching_logs WHERE id = ? AND coach_id = ?";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $log_id, $user_id);
            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
                echo json_encode(['success' => true, 'message' => 'Coaching log deleted.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete entry or permission denied.']);
            }
            break;

        case 'get_class_attendance':
            $class_id = $_POST['class_id'] ?? 0;
            $class_date = $_POST['class_date'] ?? '';
            
            if (empty($class_id) || empty($class_date)) {
                echo json_encode(['success' => false, 'message' => 'Class ID and Date are required.']);
                exit;
            }
            
            $sql = "SELECT COUNT(*) as attendance_count FROM bookings WHERE class_id = ? AND booking_date = ? AND attended = 1";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "is", $class_id, $class_date);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $attendance = mysqli_fetch_assoc($result);
            echo json_encode(['success' => true, 'attendance_count' => $attendance['attendance_count']]);
            break;
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---

// --- LANGUAGE HANDLING ---
$translations = [
    'en' => [
        'coaching_log' => 'Coaching Log',
        'personal_coaching_log' => 'Personal Coaching Log',
        'track_progress' => 'Track your coaching progress and improve your teaching.',
        'back_to_dashboard' => '← Back to Dashboard',
        'new_classes_to_log' => 'New Classes to Log',
        'add_manual' => '+ Add Manual',
        'log_history' => 'Log History',
        'log_your_class' => 'Log Your Class',
        'edit_log_entry' => 'Edit Log Entry',
        'manual_log_entry' => 'Manual Log Entry',
        'class' => 'Class',
        'date' => 'Date',
        'techniques_taught' => 'Techniques Taught',
        'attendance_count' => 'Attendance Count',
        'what_went_well' => 'What Went Well',
        'what_went_well_placeholder' => 'e.g., Students were engaged, good energy...',
        'improvements_needed' => 'Areas for Improvement',
        'improvements_needed_placeholder' => 'e.g., Need to explain transitions better...',
        'notes' => 'Additional Notes',
        'notes_placeholder' => 'e.g., Several students struggled with...',
        'save_log' => 'Save Log',
        'view_edit' => 'View / Edit',
        'delete' => 'Delete',
        'all_classes_logged' => 'All classes are logged!',
        'no_log_history' => 'No coaching log history yet.',
        'confirm_delete' => 'Are you sure you want to delete this coaching log? This cannot be undone.',
        'network_error' => 'A network error occurred.',
        'class_and_date_required' => 'Class and Date are required fields.',
        'log_saved' => 'Coaching log saved successfully!',
        'log_updated' => 'Coaching log updated successfully!',
        'log_deleted' => 'Coaching log deleted.',
        'failed_save' => 'Failed to save coaching log.',
        'failed_delete' => 'Failed to delete entry or permission denied.'
    ],
    'zh' => [
        'coaching_log' => '教練日誌',
        'personal_coaching_log' => '個人教練日誌',
        'track_progress' => '追踪您的教練進度並改善您的教學。',
        'back_to_dashboard' => '← 返回儀表板',
        'new_classes_to_log' => '待記錄的新課程',
        'add_manual' => '+ 手動新增',
        'log_history' => '日誌歷史',
        'log_your_class' => '記錄您的課程',
        'edit_log_entry' => '編輯日誌記錄',
        'manual_log_entry' => '手動日誌記錄',
        'class' => '課程',
        'date' => '日期',
        'techniques_taught' => '教授的技巧',
        'attendance_count' => '出席人數',
        'what_went_well' => '做得好的地方',
        'what_went_well_placeholder' => '例如：學生參與度高，氣氛良好...',
        'improvements_needed' => '需要改進的地方',
        'improvements_needed_placeholder' => '例如：需要更好地解釋轉換動作...',
        'notes' => '額外筆記',
        'notes_placeholder' => '例如：幾個學生在...方面有困難',
        'save_log' => '儲存日誌',
        'view_edit' => '查看/編輯',
        'delete' => '刪除',
        'all_classes_logged' => '所有課程都已記錄！',
        'no_log_history' => '尚無教練日誌歷史。',
        'confirm_delete' => '您確定要刪除此教練日誌嗎？此操作無法撤銷。',
        'network_error' => '發生網路錯誤。',
        'class_and_date_required' => '課程和日期是必填欄位。',
        'log_saved' => '教練日誌儲存成功！',
        'log_updated' => '教練日誌更新成功！',
        'log_deleted' => '教練日誌已刪除。',
        'failed_save' => '儲存教練日誌失敗。',
        'failed_delete' => '刪除記錄失敗或權限不足。'
    ]
];
$lang = $_SESSION['lang'] ?? 'en';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Coaching Log - Catch Jiu Jitsu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .card { background-color: white; border-radius: 1.5rem; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); }
        .modal { transition: opacity 0.25s ease; }
        .loader {
            width: 40px; height: 40px; border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db; border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 sm:px-6 py-12 max-w-7xl">
        <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 lang" data-lang-en="Coaching Log" data-lang-zh="教練日誌">Coaching Log</h1>
                <p class="text-gray-500 lang" data-lang-en="Track your coaching progress and improve your teaching." data-lang-zh="追踪您的教練進度並改善您的教學。">Track your coaching progress and improve your teaching.</p>
            </div>
            <div class="flex items-center gap-4">
                <div id="lang-switcher-desktop" class="flex items-center space-x-2 text-sm">
                    <button id="lang-en-desktop" class="font-bold text-blue-600">EN</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh-desktop" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
                <a href="coach_dashboard.php" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                    <span class="lang" data-lang-en="← Back to Dashboard" data-lang-zh="← 返回儀表板">← Back to Dashboard</span>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1 space-y-6">
                <div class="card p-6">
                    <div class="flex justify-between items-center">
                        <h2 class="text-xl font-bold text-gray-800 lang" data-lang-en="New Classes to Log" data-lang-zh="待記錄的新課程">New Classes to Log</h2>
                        <button id="add-manual-class-btn" class="bg-green-600 text-white text-sm font-bold py-1 px-3 rounded hover:bg-green-700">
                            <span class="lang" data-lang-en="+ Add Manual" data-lang-zh="+ 手動新增">+ Add Manual</span>
                        </button>
                    </div>
                    <div id="unlogged-classes-list" class="mt-4 space-y-3">
                        <div class="loader mx-auto"></div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="card p-6">
                    <h2 class="text-xl font-bold text-gray-800 lang" data-lang-en="Log History" data-lang-zh="日誌歷史">Log History</h2>
                    <div id="log-history-list" class="mt-4 space-y-4">
                        <div class="loader mx-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Entry Modal -->
    <div id="log-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden modal overflow-y-auto pt-10">
        <div class="relative mx-auto p-5 border w-full max-w-lg shadow-lg rounded-xl bg-white mb-10">
            <div class="flex justify-between items-center pb-3 border-b">
                <p id="log-modal-title" class="text-2xl font-bold lang" data-lang-en="Log Your Class" data-lang-zh="記錄您的課程">Log Your Class</p>
                <button id="close-log-modal" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
            </div>
            <div class="mt-4">
                <form id="log-form">
                    <input type="hidden" name="log_id" id="log_id_input">
                    <input type="hidden" name="class_id" id="class_id_input">
                    <input type="hidden" name="class_date" id="class_date_input">
                    <h3 id="log-modal-class-name" class="text-lg font-semibold text-blue-600"></h3>
                    <p id="log-modal-class-date" class="text-sm text-gray-500 mb-4"></p>

                    <div>
                        <label for="techniques_taught" class="font-semibold text-gray-700 lang" data-lang-en="Techniques Taught" data-lang-zh="教授的技巧">Techniques Taught</label>
                        <input type="text" name="techniques_taught" id="techniques_taught" class="w-full p-2 border rounded mt-1 bg-gray-50" placeholder="e.g., Armbar from Guard, Triangle Choke">
                    </div>
                    <div class="mt-4">
                        <label for="attendance_count" class="font-semibold text-gray-700 lang" data-lang-en="Attendance Count" data-lang-zh="出席人數">Attendance Count</label>
                        <input type="number" name="attendance_count" id="attendance_count" class="w-full p-2 border rounded mt-1 bg-gray-50" min="0" max="50">
                    </div>
                    <div class="mt-4">
                        <label for="what_went_well" class="font-semibold text-gray-700 lang" data-lang-en="What Went Well" data-lang-zh="做得好的地方">What Went Well</label>
                        <textarea name="what_went_well" id="what_went_well" rows="3" class="w-full p-2 border rounded mt-1 bg-gray-50" placeholder="e.g., Students were engaged, good energy..."></textarea>
                    </div>
                    <div class="mt-4">
                        <label for="improvements_needed" class="font-semibold text-gray-700 lang" data-lang-en="Areas for Improvement" data-lang-zh="需要改進的地方">Areas for Improvement</label>
                        <textarea name="improvements_needed" id="improvements_needed" rows="3" class="w-full p-2 border rounded mt-1 bg-gray-50" placeholder="e.g., Need to explain transitions better..."></textarea>
                    </div>
                    <div class="mt-4">
                        <label for="notes" class="font-semibold text-gray-700 lang" data-lang-en="Additional Notes" data-lang-zh="額外筆記">Additional Notes</label>
                        <textarea name="notes" id="notes" rows="3" class="w-full p-2 border rounded mt-1 bg-gray-50" placeholder="e.g., Several students struggled with..."></textarea>
                    </div>
                    <div class="mt-6 text-right">
                        <button type="submit" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-700 transition">
                            <span class="lang" data-lang-en="Save Log" data-lang-zh="儲存日誌">Save Log</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const unloggedList = document.getElementById('unlogged-classes-list');
        const historyList = document.getElementById('log-history-list');
        const logModal = document.getElementById('log-modal');
        const closeLogModalBtn = document.getElementById('close-log-modal');
        const logForm = document.getElementById('log-form');
        const addManualClassBtn = document.getElementById('add-manual-class-btn');
        
        let currentLang = localStorage.getItem('coachLang') || 'en';
        let logHistoryCache = []; // Cache to hold fetched log history

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
            localStorage.setItem('coachLang', lang);
            updateLanguage();
            loadData();
        }

        document.querySelectorAll('#lang-en-desktop').forEach(btn => btn.addEventListener('click', () => setLanguage('en')));
        document.querySelectorAll('#lang-zh-desktop').forEach(btn => btn.addEventListener('click', () => setLanguage('zh')));

        async function apiCall(action, formData) {
            formData.append('action', action);
            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                return await response.json();
            } catch (error) {
                console.error('API Call Error:', error);
                return { success: false, message: translations[currentLang]['network_error'] };
            }
        }

        async function loadData() {
            loadUnloggedClasses();
            loadLogHistory();
        }

        async function loadUnloggedClasses() {
            unloggedList.innerHTML = '<div class="loader mx-auto"></div>';
            const result = await apiCall('get_unlogged_classes', new FormData());
            unloggedList.innerHTML = '';
            if (result.success && result.classes.length > 0) {
                result.classes.forEach(classItem => {
                    const className = currentLang === 'zh' && classItem.name_zh ? classItem.name_zh : classItem.name;
                    const el = document.createElement('div');
                    el.className = 'bg-blue-50 p-4 rounded-lg border border-blue-200 flex justify-between items-center';
                    el.innerHTML = `
                        <div>
                            <p class="font-bold text-blue-800">${className}</p>
                            <p class="text-sm text-blue-600">${classItem.class_date} (${classItem.day_of_week})</p>
                            <p class="text-xs text-blue-600">${classItem.start_time} - ${classItem.end_time}</p>
                            <p class="text-xs text-blue-600">Attendance: ${classItem.attendance_count}</p>
                        </div>
                        <button class="log-class-btn bg-blue-500 text-white text-sm font-bold py-1 px-3 rounded hover:bg-blue-600" 
                                data-class-id="${classItem.class_id}" 
                                data-class-date="${classItem.class_date}" 
                                data-class-name="${className}"
                                data-attendance="${classItem.attendance_count}">
                            <span class="lang" data-lang-en="Log" data-lang-zh="記錄">Log</span>
                        </button>
                    `;
                    unloggedList.appendChild(el);
                });
            } else {
                unloggedList.innerHTML = `<p class="text-center text-gray-500 text-sm p-4 lang" data-lang-en="All classes are logged!" data-lang-zh="所有課程都已記錄！">All classes are logged!</p>`;
            }
            updateLanguage();
        }

        async function loadLogHistory() {
            historyList.innerHTML = '<div class="loader mx-auto"></div>';
            const result = await apiCall('get_log_history', new FormData());
            historyList.innerHTML = '';
            if (result.success && result.history.length > 0) {
                logHistoryCache = result.history; // Store the data in the cache
                result.history.forEach(entry => {
                    const className = entry.name ? (currentLang === 'zh' && entry.name_zh ? entry.name_zh : entry.name) : `<span class="lang" data-lang-en="Manual Entry" data-lang-zh="手動輸入">Manual Entry</span>`;
                    const el = document.createElement('div');
                    el.className = 'bg-gray-50 p-4 rounded-lg border';
                    el.innerHTML = `
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="font-bold text-gray-800">${className}</p>
                                <p class="text-sm text-gray-500">${entry.class_date}</p>
                            </div>
                            <div class="text-lg font-bold text-green-500">${entry.attendance_count} students</div>
                        </div>
                        <div class="mt-3 text-sm space-y-2">
                            ${entry.techniques_taught ? `<p><strong class="font-semibold lang" data-lang-en="Techniques:" data-lang-zh="技巧：">Techniques:</strong> ${entry.techniques_taught}</p>` : ''}
                            ${entry.what_went_well ? `<p><strong class="font-semibold lang" data-lang-en="Went Well:" data-lang-zh="做得好的：">Went Well:</strong> ${entry.what_went_well}</p>` : ''}
                            ${entry.improvements_needed ? `<p><strong class="font-semibold lang" data-lang-en="Improvements:" data-lang-zh="改進：">Improvements:</strong> ${entry.improvements_needed}</p>` : ''}
                            ${entry.notes ? `<p class="mt-2 pt-2 border-t text-gray-600 whitespace-pre-wrap">${entry.notes}</p>` : ''}
                        </div>
                        <div class="mt-4 pt-2 border-t flex justify-end gap-2">
                            <button class="edit-log-btn text-sm font-bold text-blue-600 hover:underline" data-log-id="${entry.id}">
                                <span class="lang" data-lang-en="View / Edit" data-lang-zh="查看/編輯">View / Edit</span>
                            </button>
                            <button class="delete-log-btn text-sm font-bold text-red-600 hover:underline" data-log-id="${entry.id}">
                                <span class="lang" data-lang-en="Delete" data-lang-zh="刪除">Delete</span>
                            </button>
                        </div>
                    `;
                    historyList.appendChild(el);
                });
            } else {
                logHistoryCache = [];
                historyList.innerHTML = `<p class="text-center text-gray-500 text-sm p-8 lang" data-lang-en="No coaching log history yet." data-lang-zh="尚無教練日誌歷史。">No coaching log history yet.</p>`;
            }
            updateLanguage();
        }

        function openLogModal(data = {}) {
            logForm.reset();
            document.getElementById('log_id_input').value = data.id || '';
            document.getElementById('class_id_input').value = data.class_id || '';
            document.getElementById('class_date_input').value = data.class_date || new Date().toISOString().slice(0,10);
            document.getElementById('log-modal-class-name').textContent = data.className || '';
            document.getElementById('log-modal-class-date').textContent = data.class_date || new Date().toISOString().slice(0,10);
            
            // Populate form for editing
            document.getElementById('techniques_taught').value = data.techniques_taught || '';
            document.getElementById('attendance_count').value = data.attendance_count || '';
            document.getElementById('what_went_well').value = data.what_went_well || '';
            document.getElementById('improvements_needed').value = data.improvements_needed || '';
            document.getElementById('notes').value = data.notes || '';

            const titleEl = document.getElementById('log-modal-title');
            if (data.id) {
                titleEl.setAttribute('data-lang-en', 'Edit Log Entry');
                titleEl.setAttribute('data-lang-zh', '編輯日誌記錄');
            } else {
                titleEl.setAttribute('data-lang-en', 'Log Your Class');
                titleEl.setAttribute('data-lang-zh', '記錄您的課程');
            }

            if (!data.className && !data.id) {
                document.getElementById('log-modal-class-name').innerHTML = `<span class="lang" data-lang-en="Manual Log Entry" data-lang-zh="手動日誌記錄">Manual Log Entry</span>`;
            }

            logModal.classList.remove('hidden');
            updateLanguage();
        }

        unloggedList.addEventListener('click', (e) => {
            const btn = e.target.closest('.log-class-btn');
            if (btn) {
                openLogModal({
                    class_id: btn.dataset.classId,
                    class_date: btn.dataset.classDate,
                    className: btn.dataset.className,
                    attendance_count: btn.dataset.attendance
                });
            }
        });

        historyList.addEventListener('click', async (e) => {
            const editBtn = e.target.closest('.edit-log-btn');
            const deleteBtn = e.target.closest('.delete-log-btn');

            if (editBtn) {
                const logId = parseInt(editBtn.dataset.logId, 10);
                const logData = logHistoryCache.find(log => log.id == logId);
                if (logData) {
                    const className = logData.name ? (currentLang === 'zh' && logData.name_zh ? logData.name_zh : logData.name) : '';
                    openLogModal({ ...logData, className });
                }
            }

            if (deleteBtn) {
                const logId = deleteBtn.dataset.logId;
                const confirmMsg = translations[currentLang]['confirm_delete'];
                if (confirm(confirmMsg)) {
                    const formData = new FormData();
                    formData.append('log_id', logId);
                    const result = await apiCall('delete_log_entry', formData);
                    if (result.success) {
                        loadData();
                    } else {
                        alert(result.message);
                    }
                }
            }
        });

        addManualClassBtn.addEventListener('click', () => {
            openLogModal();
        });

        closeLogModalBtn.addEventListener('click', () => logModal.classList.add('hidden'));

        logForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(logForm);
            const result = await apiCall('save_log_entry', formData);
            if(result.success) {
                logModal.classList.add('hidden');
                loadData();
            } else {
                alert(result.message);
            }
        });

        // Initial Load
        loadData();
        updateLanguage();
    });
    </script>
</body>
</html>
