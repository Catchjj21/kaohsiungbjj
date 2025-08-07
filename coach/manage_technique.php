<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "../db_config.php";
session_start();

// Security check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    header("location: ../login.html");
    exit;
}

// --- LANGUAGE HANDLING ---
$translations = [
    'en' => [
        'title' => 'Manage Technique of the Week',
        'back_to_dashboard' => 'Back to Dashboard',
        'add_new' => 'Add New Technique',
        'table_title' => 'Title',
        'table_coach' => 'Coach',
        'table_date' => 'Date Posted',
        'table_tags' => 'Tags',
        'table_actions' => 'Actions',
        'no_techniques' => 'No techniques posted yet.',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'modal_add_title' => 'Add New Technique',
        'modal_edit_title' => 'Edit Technique',
        'form_title' => 'Title',
        'form_youtube' => 'YouTube Video URL',
        'form_description' => 'Description',
        'form_tags' => 'Tags',
        'cancel' => 'Cancel',
        'save' => 'Save Technique',
        'confirm_delete' => 'Are you sure you want to delete this technique?',
    ],
    'zh' => [
        'title' => '管理本週技巧',
        'back_to_dashboard' => '返回儀表板',
        'add_new' => '新增技巧',
        'table_title' => '標題',
        'table_coach' => '教練',
        'table_date' => '發布日期',
        'table_tags' => '標籤',
        'table_actions' => '操作',
        'no_techniques' => '尚未發布任何技巧。',
        'edit' => '編輯',
        'delete' => '刪除',
        'modal_add_title' => '新增技巧',
        'modal_edit_title' => '編輯技巧',
        'form_title' => '標題',
        'form_youtube' => 'YouTube 影片網址',
        'form_description' => '描述',
        'form_tags' => '標籤',
        'cancel' => '取消',
        'save' => '儲存技巧',
        'confirm_delete' => '您確定要刪除此技巧嗎？',
    ]
];
$lang = $_SESSION['lang'] ?? 'en';

// Fetch all technique posts
$technique_posts = [];
$sql = "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) as coach_name 
        FROM technique_of_the_week t
        LEFT JOIN users u ON t.coach_id = u.id";

if ($_SESSION['role'] === 'coach') {
    $sql .= " WHERE t.coach_id = ?";
}
$sql .= " ORDER BY t.created_at DESC";

if ($stmt = mysqli_prepare($link, $sql)) {
    if ($_SESSION['role'] === 'coach') {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['id']);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $technique_posts[] = $row;
    }
    mysqli_stmt_close($stmt);
}
mysqli_close($link);

// Define tags
$tags_list = [
    'guard' => '防禦', 'sweep' => '掃技', 'half guard' => '半防禦', 'mount' => '騎乘', 
    'back' => '背部控制', 'guard passing' => '過防禦', 'half guard passing' => '過半防禦', 
    'submission' => '降伏', 'judo' => '柔道', 'wrestling' => '摔跤', 
    'side control' => '側壓', 'kesi gatame' => '袈裟固'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title class="lang" data-lang-en="Manage Technique of the Week" data-lang-zh="管理本週技巧">Manage Technique of the Week - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal { transition: opacity 0.25s ease; }
        .modal-active { overflow: hidden; }
        .modal-content { transition: transform 0.25s ease; }
        .ql-editor { min-height: 150px; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800 lang" data-lang-en="Coach Portal" data-lang-zh="教練門戶">Coach Portal</span>
            <div class="flex items-center space-x-4">
                <div id="lang-switcher-desktop" class="flex items-center space-x-2 text-sm">
                    <button id="lang-en" class="font-bold text-blue-600">EN</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
                <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition lang" data-lang-en="Logout" data-lang-zh="登出">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12 flex-grow">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-4xl font-black text-gray-800 lang" data-lang-en="Technique of the Week" data-lang-zh="本週技巧">Technique of the Week</h1>
            <div class="flex items-center">
                <a href="coach_dashboard.php" class="text-white bg-gray-600 hover:bg-gray-700 font-bold py-2.5 px-6 rounded-lg transition mr-2 lang" data-lang-en="Back to Dashboard" data-lang-zh="返回儀表板">Back to Dashboard</a>
                <button id="addTechniqueBtn" class="text-white bg-green-600 hover:bg-green-700 font-bold py-2.5 px-6 rounded-lg transition lang" data-lang-en="Add New Technique" data-lang-zh="新增技巧">Add New Technique</button>
            </div>
        </div>

        <!-- Content Table -->
        <div class="bg-white p-8 rounded-2xl shadow-lg overflow-x-auto mt-8">
            <table class="min-w-full text-left divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="Title" data-lang-zh="標題">Title</th>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="Coach" data-lang-zh="教練">Coach</th>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="Date Posted" data-lang-zh="發布日期">Date Posted</th>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="Tags" data-lang-zh="標籤">Tags</th>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="Actions" data-lang-zh="操作">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($technique_posts)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 whitespace-nowrap text-center text-gray-500 lang" data-lang-en="No techniques posted yet." data-lang-zh="尚未發布任何技巧。">No techniques posted yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($technique_posts as $post): ?>
                            <tr>
                                <td class="px-6 py-4 text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($post['title']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($post['coach_name']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-500"><?php echo date('F j, Y', strtotime($post['created_at'])); ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <?php 
                                        $post_tags = explode(',', $post['tags']);
                                        foreach ($post_tags as $tag): 
                                            if (!empty($tag)): ?>
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800"><?php echo htmlspecialchars($tag); ?></span>
                                            <?php endif; 
                                        endforeach; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm font-medium space-x-2">
                                    <button class="editTechniqueBtn text-blue-600 hover:text-blue-900 lang" data-lang-en="Edit" data-lang-zh="編輯"
                                        data-id="<?php echo $post['id']; ?>"
                                        data-title="<?php echo htmlspecialchars($post['title']); ?>"
                                        data-youtube_url="<?php echo htmlspecialchars($post['youtube_url']); ?>"
                                        data-description_html="<?php echo htmlspecialchars($post['description']); ?>"
                                        data-tags="<?php echo htmlspecialchars($post['tags']); ?>">Edit</button>
                                    <button class="deleteTechniqueBtn text-red-600 hover:text-red-900 lang" data-lang-en="Delete" data-lang-zh="刪除"
                                        data-id="<?php echo $post['id']; ?>">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal for Add/Edit Technique -->
    <div id="techniqueModal" class="modal fixed w-full h-full top-0 left-0 flex items-center justify-center z-50 hidden">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        <div class="modal-content bg-white w-11/12 md:max-w-2xl mx-auto rounded-lg shadow-lg z-50 max-h-[90vh] flex flex-col">
            <div class="py-4 text-left px-6 flex-shrink-0">
                <div class="flex justify-between items-center pb-3">
                    <p id="modalTitle" class="text-2xl font-bold text-gray-800 lang" data-lang-en="Add New Technique" data-lang-zh="新增技巧">Add New Technique</p>
                    <button class="closeModalBtn cursor-pointer z-50"><svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path></svg></button>
                </div>
            </div>
            <div class="overflow-y-auto px-6 flex-grow">
                <form id="techniqueForm" class="space-y-4">
                    <input type="hidden" id="action" name="action" value="create">
                    <input type="hidden" id="post_id" name="post_id" value="">
                    
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 lang" data-lang-en="Title" data-lang-zh="標題">Title</label>
                        <input type="text" name="title" id="title" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required>
                    </div>
                    <div>
                        <label for="youtube_url" class="block text-sm font-medium text-gray-700 lang" data-lang-en="YouTube Video URL" data-lang-zh="YouTube 影片網址">YouTube Video URL</label>
                        <input type="url" name="youtube_url" id="youtube_url" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" placeholder="e.g., https://www.youtube.com/watch?v=dQw4w9WgXcQ" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 lang" data-lang-en="Description" data-lang-zh="描述">Description</label>
                        <div id="editor" class="mt-1 bg-white border border-gray-300 rounded-md"></div>
                        <input type="hidden" name="description" id="description">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 lang" data-lang-en="Tags" data-lang-zh="標籤">Tags</label>
                        <div class="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-2">
                            <?php foreach ($tags_list as $en_tag => $zh_tag): ?>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="tags[]" value="<?php echo htmlspecialchars($en_tag); ?>" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                    <span class="ml-2 text-sm text-gray-600 lang" data-lang-en="<?php echo htmlspecialchars(ucwords($en_tag)); ?>" data-lang-zh="<?php echo htmlspecialchars($zh_tag); ?>"><?php echo htmlspecialchars(ucwords($en_tag)); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2 pb-4">
                        <button type="button" class="closeModalBtn px-4 bg-gray-200 p-3 rounded-lg text-black hover:bg-gray-300 mr-2 lang" data-lang-en="Cancel" data-lang-zh="取消">Cancel</button>
                        <button type="submit" class="px-4 bg-blue-600 p-3 rounded-lg text-white hover:bg-blue-700 lang" data-lang-en="Save Technique" data-lang-zh="儲存技巧">Save Technique</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Quill.js scripts -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const techniqueModal = document.getElementById('techniqueModal');
            const addTechniqueBtn = document.getElementById('addTechniqueBtn');
            const editTechniqueBtns = document.querySelectorAll('.editTechniqueBtn');
            const deleteTechniqueBtns = document.querySelectorAll('.deleteTechniqueBtn');
            const techniqueForm = document.getElementById('techniqueForm');
            const closeModalBtns = document.querySelectorAll('.closeModalBtn');
            const modalOverlays = document.querySelectorAll('.modal-overlay');

            const translations = <?php echo json_encode($translations); ?>;
            let currentLang = localStorage.getItem('coachLang') || 'en';

            const quill = new Quill('#editor', {
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, false] }],
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        ['link']
                    ]
                }
            });

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

            function openModal(modal) {
                modal.classList.remove('hidden');
                document.body.classList.add('modal-active');
            }

            function closeModal(modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('modal-active');
                techniqueForm.reset();
                quill.setContents([]);
            }

            addTechniqueBtn.addEventListener('click', () => {
                document.getElementById('modalTitle').dataset.langEn = translations.en.modal_add_title;
                document.getElementById('modalTitle').dataset.langZh = translations.zh.modal_add_title;
                document.getElementById('action').value = 'create';
                document.getElementById('post_id').value = '';
                techniqueForm.reset();
                quill.setContents([]);
                openModal(techniqueModal);
                updateLanguage();
            });

            editTechniqueBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('modalTitle').dataset.langEn = translations.en.modal_edit_title;
                    document.getElementById('modalTitle').dataset.langZh = translations.zh.modal_edit_title;
                    document.getElementById('action').value = 'update';
                    document.getElementById('post_id').value = btn.dataset.id;
                    document.getElementById('title').value = btn.dataset.title;
                    document.getElementById('youtube_url').value = btn.dataset.youtube_url;
                    quill.root.innerHTML = btn.dataset.description_html;

                    // Handle tags
                    const tags = btn.dataset.tags.split(',');
                    document.querySelectorAll('input[name="tags[]"]').forEach(checkbox => {
                        checkbox.checked = tags.includes(checkbox.value);
                    });

                    openModal(techniqueModal);
                    updateLanguage();
                });
            });

            deleteTechniqueBtns.forEach(btn => {
                btn.addEventListener('click', async () => {
                    const confirmMsg = translations[currentLang]['confirm_delete'];
                    if (confirm(confirmMsg)) {
                        const formData = new FormData();
                        formData.append('action', 'delete');
                        formData.append('post_id', btn.dataset.id);

                        const response = await fetch('technique_handler.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();

                        if (result.success) {
                            window.location.reload();
                        } else {
                            alert('Error: ' + result.message);
                        }
                    }
                });
            });

            techniqueForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                document.getElementById('description').value = quill.root.innerHTML;
                
                const formData = new FormData(techniqueForm);
                const response = await fetch('technique_handler.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            });

            closeModalBtns.forEach(btn => btn.addEventListener('click', () => closeModal(techniqueModal)));
            modalOverlays.forEach(overlay => overlay.addEventListener('click', () => closeModal(techniqueModal)));

            updateLanguage();
        });
    </script>
</body>
</html>
