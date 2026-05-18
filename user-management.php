<?php
// user-management.php
// This file serves the HTML/CSS/JS for the User Management UI
// AND handles all its backend API requests (GET, ADD, UPDATE, DELETE)

// Set headers for JSON response and CORS, if this is an API request
$is_api_request = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) || isset($_GET['action']);

if ($is_api_request) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *'); 
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Handle preflight OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- DATABASE CONNECTION ---
$host = 'localhost';
$port = '3306';
$dbname = 'Nelun_db';
$username_db = 'suzxlabs'; 
$password_db = 'Susara@200611003614'; 

$conn = null;

try {
    $conn = new mysqli($host, $username_db, $password_db, $dbname, $port);
    if ($conn->connect_error) {
        if ($is_api_request) {
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
            exit();
        }
        die("Connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    if ($is_api_request) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
    die("Server error: " . $e->getMessage());
}

// --- API LOGIC (Runs only if requested via fetch) ---
if ($is_api_request) {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $_GET['action'] ?? $input['action'] ?? null;

    // 1. GET ALL USERS
    if ($method === 'GET' && $action === 'get_users') {
        $sql = "SELECT u.user_id, u.username, u.role, u.branch_id, b.branch_name, u.created_at 
                FROM users u 
                LEFT JOIN branches b ON u.branch_id = b.branch_id 
                ORDER BY u.user_id ASC";
        $result = $conn->query($sql);
        $users = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }
        echo json_encode(['success' => true, 'users' => $users]);
        exit();
    }

    // 2. GET BRANCHES (For Dropdown)
    if ($method === 'GET' && $action === 'get_branches') {
        $sql = "SELECT branch_id, branch_name FROM branches ORDER BY branch_id ASC";
        $result = $conn->query($sql);
        $branches = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $branches[] = $row;
            }
        }
        echo json_encode(['success' => true, 'branches' => $branches]);
        exit();
    }

    // 3. ADD NEW USER
    if ($method === 'POST' && $action === 'add_user') {
        $user = trim($input['username'] ?? '');
        $pass = trim($input['password'] ?? '');
        $role = $input['role'] ?? 'Cashier';
        $branch = !empty($input['branch_id']) ? intval($input['branch_id']) : null;

        if (empty($user) || empty($pass)) {
            echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
            exit();
        }

        // Branch Isolation Logic
        if ($role === 'Admin') {
            $branch = 1; // Admins default to main structural branch
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, branch_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $user, $hash, $role, $branch);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User created successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create user. Username may already exist.']);
        }
        $stmt->close();
        exit();
    }

    // 4. UPDATE EXISTING USER
    if ($method === 'POST' && $action === 'update_user') {
        $uid = intval($input['user_id'] ?? 0);
        $user = trim($input['username'] ?? '');
        $pass = trim($input['password'] ?? '');
        $role = $input['role'] ?? 'Cashier';
        $branch = !empty($input['branch_id']) ? intval($input['branch_id']) : null;

        if (empty($user) || empty($uid)) {
            echo json_encode(['success' => false, 'message' => 'Invalid user data provided.']);
            exit();
        }

        // Branch Isolation Logic
        if ($role === 'Admin') {
            $branch = 1; 
        }

        if (empty($pass)) {
            // Update without changing password
            $stmt = $conn->prepare("UPDATE users SET username=?, role=?, branch_id=? WHERE user_id=?");
            $stmt->bind_param("ssii", $user, $role, $branch, $uid);
        } else {
            // Update with new password hash
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET username=?, password_hash=?, role=?, branch_id=? WHERE user_id=?");
            $stmt->bind_param("sssii", $user, $hash, $role, $branch, $uid);
        }

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update user.']);
        }
        $stmt->close();
        exit();
    }

    // 5. DELETE USER
    if ($method === 'POST' && $action === 'delete_user') {
        $uid = intval($input['user_id'] ?? 0);
        
        if ($uid === 1) {
            echo json_encode(['success' => false, 'message' => 'Action Denied: Cannot delete the primary administrator.']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $stmt->bind_param("i", $uid);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete user.']);
        }
        $stmt->close();
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Nelun POS</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel=\"stylesheet\">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F2F2F7; padding: 20px; }
        .card { border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .table th { background-color: #f8f9fa; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; color: #6c757d; }
        .badge-admin { background-color: #007AFF; color: white; }
        .badge-cashier { background-color: #34C759; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid max-w-7xl mx-auto">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h4 mb-0 fw-bold text-dark"><i class="bi bi-people-fill me-2 text-primary"></i> User Management</h2>
            <button class="btn btn-primary rounded-pill px-4" onclick="openUserModal()">
                <i class="bi bi-person-plus me-1"></i> Add New User
            </button>
        </div>

        <div id="alertContainer"></div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Assigned Branch</th>
                                <th>Created</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <!-- Users will be populated here via JS -->
                            <tr><td colspan="6" class="text-center py-5 text-muted">Loading users...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- User Modal (Add/Edit) -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="userModalTitle">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="userForm">
                        <input type="hidden" id="userId">
                        
                        <div class="mb-3">
                            <label class="form-label fw-medium">Username</label>
                            <input type="text" class="form-control" id="username" required autocomplete="off">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium">Password</label>
                            <input type="password" class="form-control" id="password" autocomplete="new-password">
                            <small class="text-muted" id="passwordHelp">Leave blank to keep current password when editing.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium">System Role</label>
                            <select class="form-select" id="role" name="role" required onchange="toggleBranchVisibility()">
                                <option value="Cashier">Cashier (Assigned Branch Only)</option>
                                <option value="Admin">Admin (Full System Access)</option>
                            </select>
                        </div>

                        <div class="mb-3" id="branchSelectionDiv">
                            <label class="form-label fw-medium">Assign Branch</label>
                            <select class="form-select" id="branch_id">
                                <option value="">Select a branch...</option>
                                <!-- Populated dynamically -->
                            </select>
                        </div>

                        <div class="mt-4 pt-2">
                            <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 fw-medium">Save User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const API_URL = 'user-management.php';
        let userModal = null;
        let allBranches = [];

        document.addEventListener('DOMContentLoaded', () => {
            userModal = new bootstrap.Modal(document.getElementById('userModal'));
            fetchBranches();
            fetchUsers();
        });

        // Toggle Branch dropdown requirement based on Role
        function toggleBranchVisibility() {
            const role = document.getElementById('role').value;
            const branchDiv = document.getElementById('branchSelectionDiv');
            const branchSelect = document.getElementById('branch_id');
            
            if (role === 'Admin') {
                branchDiv.style.display = 'none';
                branchSelect.removeAttribute('required');
                branchSelect.value = ''; 
            } else {
                branchDiv.style.display = 'block';
                branchSelect.setAttribute('required', 'required');
            }
        }

        async function fetchBranches() {
            try {
                const res = await fetch(`${API_URL}?action=get_branches`);
                const data = await res.json();
                if (data.success) {
                    allBranches = data.branches;
                    const select = document.getElementById('branch_id');
                    select.innerHTML = '<option value="">Select a branch...</option>';
                    allBranches.forEach(b => {
                        select.innerHTML += `<option value="${b.branch_id}">${b.branch_name}</option>`;
                    });
                }
            } catch (e) {
                console.error("Error loading branches", e);
            }
        }

        async function fetchUsers() {
            try {
                const res = await fetch(`${API_URL}?action=get_users`);
                const data = await res.json();
                const tbody = document.getElementById('userTableBody');
                tbody.innerHTML = '';

                if (data.success && data.users.length > 0) {
                    data.users.forEach(u => {
                        const roleBadge = u.role === 'Admin' ? 'badge-admin' : 'badge-cashier';
                        const branchDisplay = u.role === 'Admin' ? '<span class="text-muted">All Branches</span>' : (u.branch_name || '<span class="text-danger">Unassigned</span>');
                        
                        tbody.innerHTML += `
                            <tr>
                                <td class="ps-4 text-muted fw-medium">#${u.user_id}</td>
                                <td class="fw-bold">${u.username}</td>
                                <td><span class="badge ${roleBadge} rounded-pill px-3">${u.role}</span></td>
                                <td>${branchDisplay}</td>
                                <td class="text-muted small">${u.created_at || '-'}</td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-light border me-1" onclick='editUser(${JSON.stringify(u)})'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-light border text-danger" onclick='deleteUser(${u.user_id})' ${u.user_id == 1 ? 'disabled' : ''}>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">No users found.</td></tr>';
                }
            } catch (e) {
                showAlert('Network error fetching users.', 'danger');
            }
        }

        function openUserModal() {
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('userModalTitle').textContent = 'Add New User';
            document.getElementById('password').setAttribute('required', 'required');
            document.getElementById('passwordHelp').style.display = 'none';
            toggleBranchVisibility(); // Set initial toggle state
            userModal.show();
        }

        window.editUser = function(user) {
            document.getElementById('userId').value = user.user_id;
            document.getElementById('username').value = user.username;
            document.getElementById('role').value = user.role;
            document.getElementById('branch_id').value = user.branch_id || '';
            document.getElementById('password').value = ''; 
            
            document.getElementById('userModalTitle').textContent = 'Edit User';
            document.getElementById('password').removeAttribute('required'); // Password optional on edit
            document.getElementById('passwordHelp').style.display = 'block';
            
            toggleBranchVisibility(); // Apply toggle rules
            userModal.show();
        }

        document.getElementById('userForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('userId').value;
            const payload = {
                action: id ? 'update_user' : 'add_user',
                user_id: id,
                username: document.getElementById('username').value,
                password: document.getElementById('password').value,
                role: document.getElementById('role').value,
                branch_id: document.getElementById('branch_id').value
            };

            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showAlert(data.message, 'success');
                    userModal.hide();
                    fetchUsers();
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch (err) {
                showAlert('Error saving user data.', 'danger');
            }
        });

        window.deleteUser = async function(id) {
            if (!confirm('Are you sure you want to completely delete this user?')) return;
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_user', user_id: id })
                });
                const data = await res.json();
                if (data.success) {
                    showAlert('User deleted successfully.', 'warning');
                    fetchUsers();
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch (err) {
                showAlert('Error deleting user.', 'danger');
            }
        }

        function showAlert(msg, type) {
            const div = document.createElement('div');
            div.className = `alert alert-${type} alert-dismissible fade show shadow fixed-top m-3`;
            div.style.zIndex = 2000;
            div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.getElementById('alertContainer').appendChild(div);
            setTimeout(() => div.remove(), 4000);
        }
    </script>
</body>
</html>
<?php
// Close database connection if it was opened
if ($conn && $is_api_request === false) {
    $conn->close();
}
?>