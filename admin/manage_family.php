<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "../db_config.php"; 
session_start();

// --- Page Security: ADMIN ONLY ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: ../login.html");
    exit;
}

// --- AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'search_parent':
            $search_term = $_POST['search_term'] ?? '';
            $param = "%" . $search_term . "%";
            $sql = "SELECT id, first_name, last_name, email FROM users WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?) AND role IN ('member', 'coach', 'admin') LIMIT 10";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "sss", $param, $param, $param);
            mysqli_stmt_execute($stmt);
            $parents = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'parents' => $parents]);
            break;

        case 'get_family_members':
            $parent_id = $_POST['parent_id'] ?? 0;
            $sql = "SELECT * FROM family_members WHERE parent_user_id = ?";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $parent_id);
            mysqli_stmt_execute($stmt);
            $family = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'family' => $family]);
            break;
        
        case 'save_family_member':
            $parent_id = $_POST['parent_user_id'] ?? 0;
            $member_id = $_POST['member_id'] ?? null;
            $first_name = $_POST['first_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            $chinese_name = $_POST['chinese_name'] ?? '';
            $dob = $_POST['dob'] ?? '';
            $old_card = $_POST['old_card'] ?? '';

            if (empty($parent_id) || empty($first_name) || empty($last_name) || empty($dob) || empty($old_card)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required.']);
                exit;
            }

            if ($member_id) { // Update
                $sql = "UPDATE family_members SET first_name=?, last_name=?, chinese_name=?, dob=?, old_card=? WHERE id=? AND parent_user_id=?";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "sssssii", $first_name, $last_name, $chinese_name, $dob, $old_card, $member_id, $parent_id);
            } else { // Insert
                $sql = "INSERT INTO family_members (parent_user_id, first_name, last_name, chinese_name, dob, old_card) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "isssss", $parent_id, $first_name, $last_name, $chinese_name, $dob, $old_card);
            }

            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(['success' => true, 'message' => 'Family member saved successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_stmt_error($stmt)]);
            }
            break;

        case 'delete_family_member':
            $member_id = $_POST['member_id'] ?? 0;
            $sql = "DELETE FROM family_members WHERE id = ?";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $member_id);
            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(['success' => true, 'message' => 'Family member deleted.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete member.']);
            }
            break;
    }
    mysqli_close($link);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Family Accounts - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .form-label { font-weight: 600; color: #374151; }
        .form-input {
            width: 100%; padding: 0.75rem; border-radius: 0.5rem;
            border: 1px solid #d1d5db; background-color: #f9fafb;
        }
    </style>
</head>
<body class="text-gray-800">
    <div class="container mx-auto px-4 sm:px-6 py-12">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Manage Family Accounts</h1>
                <p class="text-gray-500 mt-1">Link children to a parent's account.</p>
            </div>
            <a href="admin_dashboard.php" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">&larr; Back to Dashboard</a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Search & Add Column -->
            <div>
                <div class="bg-white p-6 rounded-xl shadow-md">
                    <h2 class="text-2xl font-bold mb-4">1. Find Parent Account</h2>
                    <input type="text" id="parent-search" placeholder="Search by name or email..." class="form-input">
                    <div id="parent-results" class="mt-2 space-y-2"></div>
                </div>

                <div id="add-member-form-container" class="bg-white p-6 rounded-xl shadow-md mt-8 hidden">
                    <h2 id="form-title" class="text-2xl font-bold mb-4">Add New Family Member</h2>
                    <form id="family-member-form">
                        <input type="hidden" name="action" value="save_family_member">
                        <input type="hidden" id="parent_user_id_input" name="parent_user_id">
                        <input type="hidden" id="member_id_input" name="member_id">
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div><label for="first_name" class="form-label">First Name</label><input type="text" name="first_name" id="first_name" class="form-input" required></div>
                                <div><label for="last_name" class="form-label">Last Name</label><input type="text" name="last_name" id="last_name" class="form-input" required></div>
                            </div>
                            <div><label for="chinese_name" class="form-label">Chinese Name</label><input type="text" name="chinese_name" id="chinese_name" class="form-input"></div>
                            <div class="grid grid-cols-2 gap-4">
                                <div><label for="dob" class="form-label">Date of Birth</label><input type="date" name="dob" id="dob" class="form-input" required></div>
                                <div><label for="old_card" class="form-label">Card/Check-in ID</label><input type="text" name="old_card" id="old_card" class="form-input" required></div>
                            </div>
                        </div>
                        <div class="mt-6 flex gap-4">
                            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-700 transition">Save Member</button>
                            <button type="button" id="clear-form-btn" class="w-full bg-gray-200 text-gray-700 font-bold py-3 px-4 rounded-lg hover:bg-gray-300 transition">Clear</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Family Members List -->
            <div>
                <div class="bg-white p-6 rounded-xl shadow-md">
                    <h2 class="text-2xl font-bold mb-4">2. Linked Family Members</h2>
                    <p id="no-parent-selected" class="text-gray-500">Please select a parent account to view their family members.</p>
                    <div id="family-members-list" class="mt-4 space-y-3"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const parentSearchInput = document.getElementById('parent-search');
        const parentResultsContainer = document.getElementById('parent-results');
        const addMemberFormContainer = document.getElementById('add-member-form-container');
        const familyMemberForm = document.getElementById('family-member-form');
        const parentIdInput = document.getElementById('parent_user_id_input');
        const memberIdInput = document.getElementById('member_id_input');
        const familyMembersList = document.getElementById('family-members-list');
        const noParentSelectedMsg = document.getElementById('no-parent-selected');
        const formTitle = document.getElementById('form-title');
        const clearFormBtn = document.getElementById('clear-form-btn');
        let debounceTimer;

        async function postFormData(formData) {
            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                return await response.json();
            } catch (error) {
                console.error('API Error:', error);
                return { success: false, message: 'A network error occurred.' };
            }
        }

        parentSearchInput.addEventListener('keyup', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(async () => {
                const searchTerm = parentSearchInput.value;
                if (searchTerm.length < 2) {
                    parentResultsContainer.innerHTML = '';
                    return;
                }
                const formData = new FormData();
                formData.append('action', 'search_parent');
                formData.append('search_term', searchTerm);
                const result = await postFormData(formData);
                parentResultsContainer.innerHTML = '';
                if (result.success && result.parents.length > 0) {
                    result.parents.forEach(parent => {
                        const btn = document.createElement('button');
                        btn.className = 'w-full text-left p-3 bg-gray-100 hover:bg-blue-100 rounded-lg';
                        btn.innerHTML = `<p class="font-semibold">${parent.first_name} ${parent.last_name}</p><p class="text-sm text-gray-500">${parent.email}</p>`;
                        btn.dataset.parentId = parent.id;
                        parentResultsContainer.appendChild(btn);
                    });
                } else {
                    parentResultsContainer.innerHTML = '<p class="p-3 text-gray-500">No parents found.</p>';
                }
            }, 300);
        });

        parentResultsContainer.addEventListener('click', (e) => {
            const parentBtn = e.target.closest('button');
            if (parentBtn) {
                const parentId = parentBtn.dataset.parentId;
                selectParent(parentId);
            }
        });

        async function selectParent(parentId) {
            noParentSelectedMsg.classList.add('hidden');
            addMemberFormContainer.classList.remove('hidden');
            parentIdInput.value = parentId;
            resetForm();
            
            const formData = new FormData();
            formData.append('action', 'get_family_members');
            formData.append('parent_id', parentId);
            const result = await postFormData(formData);

            familyMembersList.innerHTML = '';
            if (result.success && result.family.length > 0) {
                result.family.forEach(member => {
                    const item = document.createElement('div');
                    item.className = 'flex items-center justify-between p-3 bg-gray-50 rounded-lg';
                    item.innerHTML = `
                        <div>
                            <p class="font-semibold">${member.first_name} ${member.last_name}</p>
                            <p class="text-sm text-gray-500">Card ID: ${member.old_card}</p>
                        </div>
                        <div class="flex gap-2">
                            <button class="edit-member-btn text-blue-600 hover:underline" data-member='${JSON.stringify(member)}'>Edit</button>
                            <button class="delete-member-btn text-red-600 hover:underline" data-member-id="${member.id}">Delete</button>
                        </div>
                    `;
                    familyMembersList.appendChild(item);
                });
            } else {
                familyMembersList.innerHTML = '<p class="text-gray-500">No family members linked to this account yet.</p>';
            }
        }

        function resetForm() {
            familyMemberForm.reset();
            memberIdInput.value = '';
            formTitle.textContent = 'Add New Family Member';
        }

        clearFormBtn.addEventListener('click', resetForm);

        familyMemberForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(familyMemberForm);
            const result = await postFormData(formData);
            alert(result.message);
            if (result.success) {
                selectParent(parentIdInput.value); // Refresh the list
            }
        });

        familyMembersList.addEventListener('click', async (e) => {
            const editBtn = e.target.closest('.edit-member-btn');
            const deleteBtn = e.target.closest('.delete-member-btn');

            if (editBtn) {
                const memberData = JSON.parse(editBtn.dataset.member);
                formTitle.textContent = `Edit ${memberData.first_name}`;
                memberIdInput.value = memberData.id;
                document.getElementById('first_name').value = memberData.first_name;
                document.getElementById('last_name').value = memberData.last_name;
                document.getElementById('chinese_name').value = memberData.chinese_name;
                document.getElementById('dob').value = memberData.dob;
                document.getElementById('old_card').value = memberData.old_card;
            }

            if (deleteBtn) {
                if (confirm('Are you sure you want to delete this family member?')) {
                    const memberId = deleteBtn.dataset.memberId;
                    const formData = new FormData();
                    formData.append('action', 'delete_family_member');
                    formData.append('member_id', memberId);
                    const result = await postFormData(formData);
                    alert(result.message);
                    if (result.success) {
                        selectParent(parentIdInput.value); // Refresh list
                    }
                }
            }
        });
    });
    </script>
</body>
</html>
