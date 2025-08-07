<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "db_config.php";
session_start();

// --- AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to register.']);
        exit;
    }

    $user_id = $_SESSION['id'];
    $action = $_POST['action'];

    if ($action === 'register_event') {
        $event_id = $_POST['event_id'] ?? 0;

        if (empty($event_id)) {
            echo json_encode(['success' => false, 'message' => 'Invalid event.']);
            exit;
        }

        // 1. Check if event is full
        $capacity_sql = "SELECT e.capacity, COUNT(er.id) as current_registrations FROM events e LEFT JOIN event_registrations er ON e.id = er.event_id WHERE e.id = ? GROUP BY e.id";
        $stmt_cap = mysqli_prepare($link, $capacity_sql);
        mysqli_stmt_bind_param($stmt_cap, "i", $event_id);
        mysqli_stmt_execute($stmt_cap);
        $event_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cap));
        mysqli_stmt_close($stmt_cap);

        if ($event_info && $event_info['current_registrations'] >= $event_info['capacity']) {
            echo json_encode(['success' => false, 'message' => 'This event is already full.']);
            exit;
        }

        // 2. Perform registration
        $insert_sql = "INSERT INTO event_registrations (user_id, event_id) VALUES (?, ?)";
        $stmt_insert = mysqli_prepare($link, $insert_sql);
        mysqli_stmt_bind_param($stmt_insert, "ii", $user_id, $event_id);
        
        if (mysqli_stmt_execute($stmt_insert)) {
            echo json_encode(['success' => true, 'message' => 'Registration successful! Please complete payment at the front desk.']);
        } else {
            if (mysqli_errno($link) == 1062) {
                echo json_encode(['success' => false, 'message' => 'You are already registered for this event.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
            }
        }
        mysqli_stmt_close($stmt_insert);
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---

// --- Page Security & Data Fetching ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.html");    
    exit;
}
$user_id = $_SESSION['id'];

// ---!!! UPDATED SQL: Fetches payment_status with a LEFT JOIN !!!---
$sql = "
    SELECT 
        e.*,
        (SELECT COUNT(*) FROM event_registrations WHERE event_id = e.id) as current_registrations,
        er.id as user_registration_id,
        er.payment_status as user_payment_status
    FROM events e
    LEFT JOIN event_registrations er ON e.id = er.event_id AND er.user_id = ?
    WHERE e.is_active = 1 AND e.event_date >= CURDATE()
    ORDER BY e.event_date, e.event_time
";
$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$events = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Seminars & Events - Catch Jiu Jitsu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .event-card {
            background-color: white;
            border-radius: 1rem;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.07), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .event-card-content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .modal { transition: opacity 0.25s ease; }
    </style>
</head>
<body class="text-gray-800">
    <div class="container mx-auto px-4 sm:px-6 py-12 max-w-7xl">
        <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
            <div>
                <h1 class="text-4xl font-black text-gray-900 lang" data-lang-en="Seminars & Events" data-lang-zh="研討會與活動">Seminars & Events</h1>
                <p class="text-gray-500 mt-1 lang" data-lang-en="Register for upcoming workshops and competitions." data-lang-zh="報名即將舉行的工作坊和比賽。">Register for upcoming workshops and competitions.</p>
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

        <!-- Events Grid -->
        <div id="events-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php if (empty($events)): ?>
                <p class="col-span-full text-center text-gray-500 p-12 text-xl lang" data-lang-en="No upcoming events scheduled. Check back soon!" data-lang-zh="目前沒有即將舉行的活動。請稍後再回來查看！">No upcoming events scheduled. Check back soon!</p>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                    <div class="event-card" id="event-card-<?php echo $event['id']; ?>">
                        <img src="uploads/<?php echo htmlspecialchars($event['image_url'] ?? ''); ?>" alt="Event Image" class="w-full h-48 object-cover" onerror="this.src='https://placehold.co/600x400/3b82f6/ffffff?text=Event';">
                        <div class="p-6 event-card-content">
                            <h2 class="text-2xl font-bold text-gray-900 lang" data-lang-en="<?php echo htmlspecialchars($event['title']); ?>" data-lang-zh="<?php echo htmlspecialchars($event['title_zh'] ?? $event['title']); ?>"><?php echo htmlspecialchars($event['title']); ?></h2>
                            <div class="flex items-center text-gray-500 text-sm font-semibold mt-2">
                                <span><?php echo date("F j, Y", strtotime($event['event_date'])); ?></span>
                                <span class="mx-2">&bull;</span>
                                <span><?php echo date("g:i A", strtotime($event['event_time'])); ?></span>
                            </div>
                            <p class="text-gray-600 mt-4 flex-grow lang" data-lang-en="<?php echo htmlspecialchars($event['description']); ?>" data-lang-zh="<?php echo htmlspecialchars($event['description_zh'] ?? $event['description']); ?>"><?php echo htmlspecialchars($event['description']); ?></p>
                            
                            <div class="mt-6 pt-4 border-t border-gray-100">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <p class="text-sm text-gray-500 lang" data-lang-en="Price" data-lang-zh="價格">Price</p>
                                        <p class="text-2xl font-bold text-gray-800">$<?php echo number_format($event['price'], 0); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500 text-right lang" data-lang-en="Spots Left" data-lang-zh="剩餘名額">Spots Left</p>
                                        <p class="spots-left-text text-2xl font-bold text-gray-800"><?php echo $event['capacity'] - $event['current_registrations']; ?></p>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <!-- ---!!! UPDATED REGISTRATION STATUS LOGIC !!!--- -->
                                    <?php if ($event['user_registration_id']): ?>
                                        <?php if ($event['user_payment_status'] === 'paid'): ?>
                                            <button class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg cursor-not-allowed">
                                                <span class="lang" data-lang-en="Paid" data-lang-zh="已付款">Paid</span>
                                            </button>
                                        <?php else: ?>
                                            <button class="w-full bg-yellow-500 text-white font-bold py-3 px-4 rounded-lg cursor-not-allowed">
                                                <span class="lang" data-lang-en="Registered (Unpaid)" data-lang-zh="已報名 (未付款)">Registered (Unpaid)</span>
                                            </button>
                                        <?php endif; ?>
                                    <?php elseif (($event['capacity'] - $event['current_registrations']) <= 0): ?>
                                        <button class="w-full bg-gray-400 text-white font-bold py-3 px-4 rounded-lg cursor-not-allowed">
                                            <span class="lang" data-lang-en="Full" data-lang-zh="已額滿">Full</span>
                                        </button>
                                    <?php else: ?>
                                        <button class="register-btn w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-700 transition" data-event-id="<?php echo $event['id']; ?>">
                                            <span class="lang" data-lang-en="Register Now" data-lang-zh="立即報名">Register Now</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Message Modal -->
    <div id="status-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden modal flex items-center justify-center">
        <div class="relative p-8 border w-full max-w-md shadow-lg rounded-xl bg-white">
            <div id="status-modal-content" class="text-center py-4"></div>
            <div class="text-center mt-4">
                <button id="close-status-modal" class="bg-blue-500 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-600 transition lang" data-lang-en="OK" data-lang-zh="好的">OK</button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
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

        const eventsGrid = document.getElementById('events-grid');
        const statusModal = document.getElementById('status-modal');
        const closeStatusModalBtn = document.getElementById('close-status-modal');
        const statusModalContent = document.getElementById('status-modal-content');

        function showStatus(message, isSuccess) {
            statusModalContent.innerHTML = `<p class="text-lg font-medium ${isSuccess ? 'text-green-600' : 'text-red-600'}">${message}</p>`;
            statusModal.classList.remove('hidden');
        }

        closeStatusModalBtn.addEventListener('click', () => {
            statusModal.classList.add('hidden');
        });

        eventsGrid.addEventListener('click', async function(e) {
            const registerBtn = e.target.closest('.register-btn');
            if (!registerBtn) return;

            const eventId = registerBtn.dataset.eventId;
            registerBtn.disabled = true;
            registerBtn.innerHTML = `<span class="lang" data-lang-en="Registering..." data-lang-zh="報名中...">Registering...</span>`;
            updateLanguage();

            const formData = new FormData();
            formData.append('action', 'register_event');
            formData.append('event_id', eventId);
            
            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                const result = await response.json();
                showStatus(result.message, result.success);

                if (result.success) {
                    // ---!!! RELOAD PAGE ON SUCCESS TO SHOW NEW STATUS !!!---
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    registerBtn.disabled = false;
                    registerBtn.innerHTML = `<span class="lang" data-lang-en="Register Now" data-lang-zh="立即報名">Register Now</span>`;
                }
            } catch (error) {
                showStatus('A network error occurred. Please try again.', false);
                registerBtn.disabled = false;
                registerBtn.innerHTML = `<span class="lang" data-lang-en="Register Now" data-lang-zh="立即報名">Register Now</span>`;
            }
            updateLanguage();
        });

        // Initial Load
        updateLanguage();
    });
    </script>
</body>
</html>
