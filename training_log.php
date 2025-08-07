<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set the timezone to ensure correct date operations
date_default_timezone_set('Asia/Taipei');

require_once "db_config.php";
session_start();

// --- Page Security ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.html");    
    exit;
}

// --- PARENT/CHILD LOGIC ---
$user_id_for_action = $_SESSION['id'];
$user_name_for_log = $_SESSION['full_name'];
$is_parent_managing = false;
$dashboard_link = "dashboard.php";

if (isset($_SESSION['role']) && $_SESSION['role'] === 'parent' && isset($_GET['id'])) {
    $child_id_from_url = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($child_id_from_url) {
        $parent_id = $_SESSION['id'];
        $sql_verify = "SELECT id, first_name, last_name FROM users WHERE id = ? AND parent_id = ?";
        if ($stmt_verify = mysqli_prepare($link, $sql_verify)) {
            mysqli_stmt_bind_param($stmt_verify, "ii", $child_id_from_url, $parent_id);
            mysqli_stmt_execute($stmt_verify);
            $child_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_verify));
            mysqli_stmt_close($stmt_verify);
            if ($child_data) {
                $user_id_for_action = $child_id_from_url;
                $user_name_for_log = $child_data['first_name'] . ' ' . $child_data['last_name'];
                $is_parent_managing = true;
                $dashboard_link = "manage_child.php?id=" . $child_id_from_url;
            }
        }
    }
} elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'parent') {
    $dashboard_link = "parents_dashboard.php";
}


// --- AJAX ROUTER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    
    // Use the user ID determined by the parent/child logic
    $user_id = $user_id_for_action;
    $action = $_POST['action'];
    header('Content-Type: application/json');

    switch ($action) {
        case 'get_unlogged_sessions':
            $sql = "SELECT b.id as booking_id, b.booking_date, c.name, c.name_zh 
                    FROM bookings b
                    JOIN classes c ON b.class_id = c.id
                    LEFT JOIN training_logs tl ON b.id = tl.booking_id
                    WHERE b.user_id = ? AND b.attended = 1 AND tl.id IS NULL
                    ORDER BY b.booking_date DESC";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $sessions = mysqli_fetch_all($result, MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'sessions' => $sessions]);
            break;

        case 'get_log_history':
            $sql = "SELECT tl.id, tl.booking_id, tl.session_date, tl.type, tl.topic_covered, tl.partners, tl.rating, tl.notes, c.name, c.name_zh
                    FROM training_logs tl
                    LEFT JOIN bookings b ON tl.booking_id = b.id
                    LEFT JOIN classes c ON b.class_id = c.id
                    WHERE tl.user_id = ?
                    ORDER BY tl.session_date DESC, tl.id DESC";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $history = mysqli_fetch_all($result, MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        case 'save_log_entry':
            $log_id = !empty($_POST['log_id']) ? $_POST['log_id'] : null;
            $booking_id = !empty($_POST['booking_id']) ? $_POST['booking_id'] : null;
            $type = $_POST['type'] ?? '';
            $topic = $_POST['topic_covered'] ?? '';
            $partners = $_POST['partners'] ?? '';
            $rating = $_POST['rating'] ?? 0;
            $notes = $_POST['notes'] ?? '';
            $session_date = $_POST['session_date'] ?? date('Y-m-d');

            if (empty($type) || empty($rating)) {
                echo json_encode(['success' => false, 'message' => 'Type and Rating are required fields.']);
                exit;
            }

            if ($log_id) { // This is an UPDATE
                $sql = "UPDATE training_logs SET session_date = ?, type = ?, topic_covered = ?, partners = ?, rating = ?, notes = ? WHERE id = ? AND user_id = ?";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "ssssisii", $session_date, $type, $topic, $partners, $rating, $notes, $log_id, $user_id);
            } else { // This is an INSERT
                $sql = "INSERT INTO training_logs (user_id, booking_id, session_date, type, topic_covered, partners, rating, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "iissssis", $user_id, $booking_id, $session_date, $type, $topic, $partners, $rating, $notes);
            }
            
            if (mysqli_stmt_execute($stmt)) {
                $message = $log_id ? 'Log entry updated successfully!' : 'Log entry saved successfully!';
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save log entry.']);
            }
            break;

        case 'delete_log_entry':
            $log_id = $_POST['log_id'] ?? 0;
            if (empty($log_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid Log ID.']);
                exit;
            }
            $sql = "DELETE FROM training_logs WHERE id = ? AND user_id = ?";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $log_id, $user_id);
            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
                echo json_encode(['success' => true, 'message' => 'Log entry deleted.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete entry or permission denied.']);
            }
            break;
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Training Log - Catch Jiu Jitsu</title>
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
                <h1 class="text-3xl font-bold text-gray-800 lang" data-lang-en="Training Log" data-lang-zh="訓練日誌">
                    <?php 
                        if ($is_parent_managing) {
                            echo "Training Log for " . htmlspecialchars($user_name_for_log);
                        } else {
                            echo "Personal Training Log";
                        }
                    ?>
                </h1>
                <p class="text-gray-500 lang" data-lang-en="Track your progress and make every session count." data-lang-zh="追踪您的進度，讓每次訓練都有價值。">Track your progress and make every session count.</p>
            </div>
            <div class="flex items-center gap-4">
                <div id="lang-switcher-desktop" class="flex items-center space-x-2 text-sm">
                    <button id="lang-en-desktop" class="font-bold text-blue-600">EN</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh-desktop" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
                <a href="<?php echo $dashboard_link; ?>" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                    <span class="lang" data-lang-en="&larr; Back to Dashboard" data-lang-zh="&larr; 返回儀表板">&larr; Back to Dashboard</span>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1 space-y-6">
                <div class="card p-6">
                    <div class="flex justify-between items-center">
                        <h2 class="text-xl font-bold text-gray-800 lang" data-lang-en="New Sessions to Log" data-lang-zh="待記錄的新課程">New Sessions to Log</h2>
                        <button id="add-manual-session-btn" class="bg-green-600 text-white text-sm font-bold py-1 px-3 rounded hover:bg-green-700">
                            <span class="lang" data-lang-en="+ Add Manual" data-lang-zh="+ 手動新增"> + Add Manual</span>
                        </button>
                    </div>
                    <div id="unlogged-sessions-list" class="mt-4 space-y-3">
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
                <p id="log-modal-title" class="text-2xl font-bold lang" data-lang-en="Log Your Session" data-lang-zh="記錄您的課程">Log Your Session</p>
                <button id="close-log-modal" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
            </div>
            <div class="mt-4">
                <form id="log-form">
                    <input type="hidden" name="log_id" id="log_id_input">
                    <input type="hidden" name="booking_id" id="booking_id_input">
                    <input type="hidden" name="session_date" id="session_date_input">
                    <h3 id="log-modal-class-name" class="text-lg font-semibold text-blue-600"></h3>
                    <p id="log-modal-class-date" class="text-sm text-gray-500 mb-4"></p>

                    <div>
                        <label for="type" class="font-semibold text-gray-700 lang" data-lang-en="Type" data-lang-zh="類型">Type</label>
                        <select name="type" id="type" class="w-full p-2 border rounded mt-1 bg-gray-50" required>
                            <option value="BJJ">BJJ</option>
                            <option value="No-Gi">No-Gi</option>
                            <option value="Wrestling">Wrestling (摔跤)</option>
                            <option value="Judo">Judo (柔道)</option>
                            <option value="S&C">S&C</option>
                        </select>
                    </div>
                    <div class="mt-4">
                        <label for="topic_covered" class="font-semibold text-gray-700 lang" data-lang-en="Topic Covered" data-lang-zh="課程主題">Topic Covered</label>
                        <input type="text" name="topic_covered" id="topic_covered" class="w-full p-2 border rounded mt-1 bg-gray-50" placeholder="e.g., Armbar from Guard">
                    </div>
                    <div class="mt-4">
                        <label for="partners" class="font-semibold text-gray-700 lang" data-lang-en="Training Partners" data-lang-zh="訓練夥伴">Training Partners</label>
                        <input type="text" name="partners" id="partners" class="w-full p-2 border rounded mt-1 bg-gray-50" placeholder="e.g., John, Jane, Mike">
                    </div>
                    <div class="mt-4">
                        <label for="rating" class="font-semibold text-gray-700 lang" data-lang-en="Session Rating (1-10)" data-lang-zh="課程評分 (1-10)">Session Rating (1-10)</label>
                        <select name="rating" id="rating" class="w-full p-2 border rounded mt-1 bg-gray-50" required>
                            <option value="" disabled selected>Select a rating</option>
                            <option value="1">1</option> <option value="2">2</option> <option value="3">3</option>
                            <option value="4">4</option> <option value="5">5</option> <option value="6">6</option>
                            <option value="7">7</option> <option value="8">8</option> <option value="9">9</option>
                            <option value="10">10</option>
                        </select>
                    </div>
                    <div class="mt-4">
                        <label for="notes" class="font-semibold text-gray-700 lang" data-lang-en="Personal Notes" data-lang-zh="個人筆記">Personal Notes</label>
                        <textarea name="notes" id="notes" rows="4" class="w-full p-2 border rounded mt-1 bg-gray-50" placeholder="e.g., Need to work on posture..."></textarea>
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
        const unloggedList = document.getElementById('unlogged-sessions-list');
        const historyList = document.getElementById('log-history-list');
        const logModal = document.getElementById('log-modal');
        const closeLogModalBtn = document.getElementById('close-log-modal');
        const logForm = document.getElementById('log-form');
        const addManualSessionBtn = document.getElementById('add-manual-session-btn');
        
        let currentLang = localStorage.getItem('preferredLang') || 'en';
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
            localStorage.setItem('preferredLang', lang);
            updateLanguage();
            loadData();
        }

        document.querySelectorAll('#lang-en-desktop').forEach(btn => btn.addEventListener('click', () => setLanguage('en')));
        document.querySelectorAll('#lang-zh-desktop').forEach(btn => btn.addEventListener('click', () => setLanguage('zh')));

        async function apiCall(action, formData) {
            formData.append('action', action);
            try {
                const url = new URL(window.location.href);
                const fetchUrl = url.search ? `${window.location.pathname}${url.search}` : window.location.pathname;
                const response = await fetch(fetchUrl, { method: 'POST', body: formData });
                return await response.json();
            } catch (error) {
                console.error('API Call Error:', error);
                return { success: false, message: 'A network error occurred.' };
            }
        }

        async function loadData() {
            loadUnloggedSessions();
            loadLogHistory();
        }

        async function loadUnloggedSessions() {
            unloggedList.innerHTML = '<div class="loader mx-auto"></div>';
            const result = await apiCall('get_unlogged_sessions', new FormData());
            unloggedList.innerHTML = '';
            if (result.success && result.sessions.length > 0) {
                result.sessions.forEach(session => {
                    const sessionName = currentLang === 'zh' && session.name_zh ? session.name_zh : session.name;
                    const el = document.createElement('div');
                    el.className = 'bg-blue-50 p-4 rounded-lg border border-blue-200 flex justify-between items-center';
                    el.innerHTML = `
                        <div>
                            <p class="font-bold text-blue-800">${sessionName}</p>
                            <p class="text-sm text-blue-600">${session.booking_date}</p>
                        </div>
                        <button class="log-session-btn bg-blue-500 text-white text-sm font-bold py-1 px-3 rounded hover:bg-blue-600" data-booking-id="${session.booking_id}" data-session-date="${session.booking_date}" data-class-name="${sessionName}">
                            <span class="lang" data-lang-en="Log" data-lang-zh="記錄">Log</span>
                        </button>
                    `;
                    unloggedList.appendChild(el);
                });
            } else {
                unloggedList.innerHTML = `<p class="text-center text-gray-500 text-sm p-4 lang" data-lang-en="All sessions are logged!" data-lang-zh="所有課程都已記錄！">All sessions are logged!</p>`;
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
                    const sessionName = entry.name ? (currentLang === 'zh' && entry.name_zh ? entry.name_zh : entry.name) : `<span class="lang" data-lang-en="Manual Entry" data-lang-zh="手動輸入">Manual Entry</span>`;
                    const el = document.createElement('div');
                    el.className = 'bg-gray-50 p-4 rounded-lg border';
                    el.innerHTML = `
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="font-bold text-gray-800">${sessionName} <span class="text-xs font-normal bg-gray-200 text-gray-600 px-2 py-0.5 rounded-full">${entry.type}</span></p>
                                <p class="text-sm text-gray-500">${entry.session_date}</p>
                            </div>
                            <div class="text-lg font-bold text-amber-500">${entry.rating}/10</div>
                        </div>
                        <div class="mt-3 text-sm space-y-2">
                            ${entry.topic_covered ? `<p><strong class="font-semibold lang" data-lang-en="Topic:" data-lang-zh="主題：">Topic:</strong> ${entry.topic_covered}</p>` : ''}
                            ${entry.partners ? `<p><strong class="font-semibold lang" data-lang-en="Partners:" data-lang-zh="夥伴：">Partners:</strong> ${entry.partners}</p>` : ''}
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
                historyList.innerHTML = `<p class="text-center text-gray-500 text-sm p-8 lang" data-lang-en="No log history yet." data-lang-zh="尚無日誌歷史。">No log history yet.</p>`;
            }
            updateLanguage();
        }

        function openLogModal(data = {}) {
            logForm.reset();
            document.getElementById('log_id_input').value = data.id || '';
            document.getElementById('booking_id_input').value = data.booking_id || '';
            document.getElementById('session_date_input').value = data.session_date || new Date().toISOString().slice(0,10);
            document.getElementById('log-modal-class-name').textContent = data.className || '';
            document.getElementById('log-modal-class-date').textContent = data.session_date || new Date().toISOString().slice(0,10);
            
            // Populate form for editing
            document.getElementById('type').value = data.type || 'BJJ';
            document.getElementById('topic_covered').value = data.topic_covered || '';
            document.getElementById('partners').value = data.partners || '';
            document.getElementById('rating').value = data.rating || '';
            document.getElementById('notes').value = data.notes || '';

            const titleEl = document.getElementById('log-modal-title');
            if (data.id) {
                titleEl.setAttribute('data-lang-en', 'Edit Log Entry');
                titleEl.setAttribute('data-lang-zh', '編輯日誌記錄');
            } else {
                titleEl.setAttribute('data-lang-en', 'Log Your Session');
                titleEl.setAttribute('data-lang-zh', '記錄您的課程');
            }

            if (!data.className && !data.id) {
                document.getElementById('log-modal-class-name').innerHTML = `<span class="lang" data-lang-en="Manual Log Entry" data-lang-zh="手動日誌記錄">Manual Log Entry</span>`;
            }

            logModal.classList.remove('hidden');
            updateLanguage();
        }

        unloggedList.addEventListener('click', (e) => {
            const btn = e.target.closest('.log-session-btn');
            if (btn) {
                openLogModal({
                    booking_id: btn.dataset.bookingId,
                    session_date: btn.dataset.sessionDate,
                    className: btn.dataset.className
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
                const confirmMsg = currentLang === 'zh' ? '您確定要刪除此日誌記錄嗎？此操作無法撤銷。' : 'Are you sure you want to delete this log entry? This cannot be undone.';
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

        addManualSessionBtn.addEventListener('click', () => {
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
