<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "../db_config.php";
session_start();

// Security check
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    header("location: admin_login.html");
    exit;
}

// Fetch all content posts from the database
$content_posts = [];
$sql = "SELECT * FROM site_content ORDER BY publish_date DESC";
if ($result = mysqli_query($link, $sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $content_posts[] = $row;
    }
    mysqli_free_result($result);
} else {
    // Log error in production, show friendly message
    echo "Error fetching content: " . mysqli_error($link);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Site Content - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <!-- Quill.js styles -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal { transition: opacity 0.25s ease; }
        .modal-active { overflow: hidden; }
        .modal-content { transition: transform 0.25s ease; }
        .ql-editor {
            min-height: 200px;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800">Admin Portal</span>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12 flex-grow">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-4xl font-black text-gray-800">Manage Site Content</h1>
            <div class="flex items-center">
                <a href="admin_dashboard.php" class="text-white bg-gray-600 hover:bg-gray-700 font-bold py-2.5 px-6 rounded-lg transition mr-2">Back to Dashboard</a>
                <button id="addContentBtn" class="text-white bg-green-600 hover:bg-green-700 font-bold py-2.5 px-6 rounded-lg transition">Add New Post</button>
            </div>
        </div>

        <!-- Content Table -->
        <div class="bg-white p-8 rounded-2xl shadow-lg overflow-x-auto mt-8">
            <table class="min-w-full text-left divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider">Published Date</th>
                        <th class="px-6 py-3 text-sm font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($content_posts)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 whitespace-nowrap text-center text-gray-500">No content posts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($content_posts as $post): ?>
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($post['title']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($post['title_zh']); ?></div>
                                </td>
                                <td class="px-6 py-4 capitalize text-sm text-gray-500"><?php echo htmlspecialchars($post['type']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($post['publish_date'])); ?></td>
                                <td class="px-6 py-4 text-sm font-medium space-x-2">
                                    <button class="editContentBtn text-blue-600 hover:text-blue-900"
                                        data-id="<?php echo $post['id']; ?>"
                                        data-type="<?php echo htmlspecialchars($post['type']); ?>"
                                        data-title="<?php echo htmlspecialchars($post['title']); ?>"
                                        data-title_zh="<?php echo htmlspecialchars($post['title_zh']); ?>"
                                        data-content_html="<?php echo htmlspecialchars($post['content']); ?>"
                                        data-content_zh_html="<?php echo htmlspecialchars($post['content_zh']); ?>"
                                        data-publish_date="<?php echo date('Y-m-d', strtotime($post['publish_date'])); ?>">Edit</button>
                                    <button class="deleteContentBtn text-red-600 hover:text-red-900"
                                        data-id="<?php echo $post['id']; ?>">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal for Add/Edit Content -->
    <div id="contentModal" class="modal fixed w-full h-full top-0 left-0 flex items-center justify-center z-50 hidden">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        <div class="modal-content bg-white w-11/12 md:max-w-4xl mx-auto rounded-lg shadow-lg z-50 max-h-[90vh] flex flex-col">
            <div class="py-4 text-left px-6 flex-shrink-0">
                <div class="flex justify-between items-center pb-3">
                    <p id="modalTitle" class="text-2xl font-bold text-gray-800">Add New Post</p>
                    <button class="closeModalBtn cursor-pointer z-50"><svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path></svg></button>
                </div>
            </div>
            <div class="overflow-y-auto px-6 flex-grow">
                <form id="contentForm" action="content_handler.php" method="POST" class="space-y-4">
                    <input type="hidden" id="action" name="action" value="create">
                    <input type="hidden" id="post_id" name="post_id" value="">
                    
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">Content Type</label>
                        <!-- ---!!! UPDATED: Removed 'Event' option !!!--- -->
                        <select name="type" id="type" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required>
                            <option value="news">News</option>
                            <option value="announcement">Announcement</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">Title (English)</label>
                            <input type="text" name="title" id="title" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required>
                        </div>
                        <div>
                            <label for="title_zh" class="block text-sm font-medium text-gray-700">Title (Chinese)</label>
                            <input type="text" name="title_zh" id="title_zh" class="mt-1 block w-full p-2 border border-gray-300 rounded-md">
                        </div>
                    </div>

                    <!-- Rich Text Editor for English Content -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Content (English)</label>
                        <div id="editor-en" class="mt-1 bg-white border border-gray-300 rounded-md"></div>
                        <input type="hidden" name="content" id="content">
                    </div>
                    
                    <!-- Rich Text Editor for Chinese Content -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Content (Chinese)</label>
                        <div id="editor-zh" class="mt-1 bg-white border border-gray-300 rounded-md"></div>
                        <input type="hidden" name="content_zh" id="content_zh">
                    </div>

                    <div>
                        <label for="publish_date" class="block text-sm font-medium text-gray-700">Publish Date</label>
                        <input type="date" name="publish_date" id="publish_date" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="button" class="closeModalBtn px-4 bg-gray-200 p-3 rounded-lg text-black hover:bg-gray-300 mr-2">Cancel</button>
                        <button type="submit" class="px-4 bg-blue-600 p-3 rounded-lg text-white hover:bg-blue-700">Save Post</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div id="deleteModal" class="modal fixed w-full h-full top-0 left-0 flex items-center justify-center z-50 hidden">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        <div class="modal-content bg-white w-11/12 md:max-w-lg mx-auto rounded-lg shadow-lg z-50 overflow-y-auto">
            <div class="py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold text-red-600">Confirm Deletion</p>
                    <button class="closeModalBtn cursor-pointer z-50"><svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path></svg></button>
                </div>
                <div class="my-5">
                    <p class="text-gray-700">Are you sure you want to delete this post? This action cannot be undone.</p>
                </div>
                <form id="deleteForm" action="content_handler.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" id="delete_post_id" name="post_id">
                    <div class="flex justify-end pt-4">
                        <button type="button" class="closeModalBtn px-4 bg-gray-200 p-3 rounded-lg text-black hover:bg-gray-300 mr-2">Cancel</button>
                        <button type="submit" class="px-4 bg-red-600 p-3 rounded-lg text-white hover:bg-red-700">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Quill.js scripts -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const contentModal = document.getElementById('contentModal');
            const addContentBtn = document.getElementById('addContentBtn');
            const editContentBtns = document.querySelectorAll('.editContentBtn');
            const contentForm = document.getElementById('contentForm');
            const deleteModal = document.getElementById('deleteModal');
            const deleteContentBtns = document.querySelectorAll('.deleteContentBtn');
            const deletePostIdInput = document.getElementById('delete_post_id');
            const closeModalBtns = document.querySelectorAll('.closeModalBtn');
            const modalOverlays = document.querySelectorAll('.modal-overlay');

            // Initialize Quill editors
            const quillEnglish = new Quill('#editor-en', { 
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'align': [] }],
                        ['link', 'image'],
                        [{ 'color': [] }, { 'background': [] }],
                        ['clean']
                    ]
                }
            });
            const quillChinese = new Quill('#editor-zh', { 
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'align': [] }],
                        ['link', 'image'],
                        [{ 'color': [] }, { 'background': [] }],
                        ['clean']
                    ]
                }
            });

            // Custom image handler to upload and insert image URL
            function imageHandler(quill) {
                const input = document.createElement('input');
                input.setAttribute('type', 'file');
                input.setAttribute('accept', 'image/*');
                input.click();
                input.onchange = async () => {
                    const file = input.files[0];
                    if (!file) return;

                    console.log('File selected:', file.name);

                    const formData = new FormData();
                    formData.append('image', file);
                    formData.append('action', 'upload_image');

                    try {
                        console.log('Starting image upload...');
                        const response = await fetch('content_handler.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        
                        if (result.success && result.url) {
                            console.log('Image uploaded successfully. URL:', result.url);
                            const range = quill.getSelection();
                            if (range) {
                                quill.insertEmbed(range.index, 'image', '../' + result.url);
                            } else {
                                quill.insertEmbed(0, 'image', '../' + result.url);
                            }
                        } else {
                            console.error('Image upload failed:', result.message);
                            alert('Image upload failed: ' + result.message);
                        }
                    } catch (error) {
                        console.error('Network error during image upload:', error);
                        alert('Network error during image upload.');
                    }
                };
            }
            // Add image handler to Quill toolbar
            quillEnglish.getModule('toolbar').addHandler('image', () => imageHandler(quillEnglish));
            quillChinese.getModule('toolbar').addHandler('image', () => imageHandler(quillChinese));
            

            function openModal(modal) {
                modal.classList.remove('hidden');
                document.body.classList.add('modal-active');
            }

            function closeModal(modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('modal-active');
                contentForm.reset();
                quillEnglish.setContents([]);
                quillChinese.setContents([]);
            }

            // Open Add Post Modal
            addContentBtn.addEventListener('click', () => {
                document.getElementById('modalTitle').textContent = 'Add New Post';
                document.getElementById('action').value = 'create';
                document.getElementById('post_id').value = '';
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('publish_date').value = today;
                contentForm.reset();
                quillEnglish.setContents([]);
                quillChinese.setContents([]);
                openModal(contentModal);
            });

            // Open Edit Post Modal
            editContentBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('modalTitle').textContent = 'Edit Post';
                    document.getElementById('action').value = 'update';
                    document.getElementById('post_id').value = btn.dataset.id;
                    document.getElementById('type').value = btn.dataset.type;
                    document.getElementById('title').value = btn.dataset.title;
                    document.getElementById('title_zh').value = btn.dataset.title_zh;
                    document.getElementById('publish_date').value = btn.dataset.publish_date;
                    
                    // Populate Quill editors with HTML content
                    quillEnglish.root.innerHTML = btn.dataset.content_html;
                    quillChinese.root.innerHTML = btn.dataset.content_zh_html;

                    openModal(contentModal);
                });
            });

            // Handle form submission to populate hidden inputs with HTML
            contentForm.addEventListener('submit', function(e) {
                document.getElementById('content').value = quillEnglish.root.innerHTML;
                document.getElementById('content_zh').value = quillChinese.root.innerHTML;
            });

            // Open Delete Modal
            deleteContentBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    deletePostIdInput.value = btn.dataset.id;
                    openModal(deleteModal);
                });
            });

            // Close Modals
            closeModalBtns.forEach(btn => btn.addEventListener('click', () => {
                closeModal(contentModal);
                closeModal(deleteModal);
            }));

            modalOverlays.forEach(overlay => overlay.addEventListener('click', (e) => {
                if (e.target.id === 'contentModal') {
                    closeModal(contentModal);
                }
                if (e.target.id === 'deleteModal') {
                    closeModal(deleteModal);
                }
            }));
        });
    </script>
</body>
</html>
