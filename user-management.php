<?php
// user-management.php
// This file serves the HTML/CSS/JS for the User Management UI
// AND handles all its backend API requests (GET, ADD, UPDATE, DELETE)

// Set headers for JSON response and CORS, if this is an API request
$is_api_request = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

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
// UPDATED CREDENTIALS FOR PRODUCTION
$host = 'localhost';
$port = '3306';
$dbname = 'Falcon-POS-System';
$username_db = 'suzxlabs'; // Updated username
$password_db = 'Susara@200611003614'; // Updated password

$conn = null;

try {
    $conn = new mysqli($host, $username_db, $password_db, $dbname, $port);
    if ($conn->connect_error) {
        if ($is_api_request) {
            echo json_encode(['success' => false, 'message' => 'Database connection error: ' . $conn->connect_error]);
            exit();
        } else {
            die("<h1>Database Connection Error</h1><p>Please try again later. (Error: " . $conn->connect_error . ")</p>");
        }
    }
} catch (Exception $e) {
    if ($is_api_request) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    } else {
        die("<h1>Server Error</h1><p>An unexpected error occurred.</p>");
    }
}

// --- PHP API Logic ---
if ($is_api_request) {
    $response = ['success' => false, 'message' => 'Invalid API request.'];
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $action = $_SERVER['REQUEST_METHOD'];
    if ($action === 'POST' && isset($data['action'])) {
        $action = $data['action'];
    }

    switch ($action) {
        case 'GET':
            try {
                $sql = "SELECT user_id, username, role, status, created_at FROM users ORDER BY user_id ASC";
                $result = $conn->query($sql);
                if ($result) {
                    $users = [];
                    while ($row = $result->fetch_assoc()) {
                        $users[] = $row;
                    }
                    $response = ['success' => true, 'users' => $users];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to retrieve users.'];
                }
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Server error during user fetch.'];
            }
            break;

        case 'add_user':
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            $role = $data['role'] ?? 'cashier';
            $status = $data['status'] ?? 'Active';

            if (empty($username) || empty($password)) {
                $response = ['success' => false, 'message' => 'Username and password are required.'];
                break;
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            try {
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $response = ['success' => false, 'message' => 'Username already exists.'];
                    $stmt->close();
                    break;
                }
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $username, $password_hash, $role, $status);

                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'User added successfully!'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to add user: ' . $stmt->error];
                }
                $stmt->close();

            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Server error during user add: ' . $e->getMessage()];
            }
            break;

        case 'update_user':
            $user_id = $data['user_id'] ?? null;
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? null;
            $role = $data['role'] ?? 'cashier';
            $status = $data['status'] ?? 'Active';

            if (empty($user_id) || empty($username)) {
                $response = ['success' => false, 'message' => 'User ID and username required.'];
                break;
            }

            try {
                $sql = "UPDATE users SET username = ?, role = ?, status = ?";
                $params = [$username, $role, $status];
                $types = "sss";

                if ($password !== null && $password !== '') {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $sql .= ", password_hash = ?";
                    $params[] = $password_hash;
                    $types .= "s";
                }

                $sql .= " WHERE user_id = ?";
                $params[] = $user_id;
                $types .= "i";

                $stmt = $conn->prepare($sql);
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $params));

                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'User updated successfully!'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to update user.'];
                }
                $stmt->close();
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Server error during update.'];
            }
            break;

        case 'delete_user':
            $user_id = $data['user_id'] ?? null;
            if (empty($user_id)) {
                $response = ['success' => false, 'message' => 'User ID required.'];
                break;
            }
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'User deleted successfully!'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to delete user.'];
                }
                $stmt->close();
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Server error during delete.'];
            }
            break;

        default:
            $response = ['success' => false, 'message' => 'Unknown action.'];
            break;
    }

    echo json_encode($response);
    $conn->close();
    exit();
}
?>
<!-- HTML UI starts here -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            padding: 20px;
        }
        .section-header {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 15px;
            margin-bottom: 25px;
            font-size: 1.8rem;
            color: #343a40;
            font-weight: 600;
        }
        .card {
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: none;
        }
        .table-action-btn {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="text-secondary"><i class="bi bi-people me-2"></i>User Management</h3>
            <button class="btn btn-primary" onclick="openAddUserModal()">
                <i class="bi bi-person-plus me-1"></i> Add New User
            </button>
        </div>

        <div class="card p-3">
            <div id="userMessage" class="alert d-none mb-3" role="alert"></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="usersTable">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr><td colspan="6" class="text-center text-muted">Loading users...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- User Modal (Add/Edit) -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="userModalLabel">Add New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="userForm">
                        <input type="hidden" id="userId">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" placeholder="Leave blank to keep current password (Edit mode)">
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role">
                                <option value="cashier">Cashier</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div id="formMessage" class="alert d-none" role="alert"></div>
                        <button type="submit" class="btn btn-primary w-100" id="saveUserBtn">Save User</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const API_URL = 'user-management.php'; // Relative path
            const userModalElement = document.getElementById('userModal');
            const userModal = new bootstrap.Modal(userModalElement);
            const userForm = document.getElementById('userForm');
            const userMessageDiv = document.getElementById('userMessage');
            const formMessageDiv = document.getElementById('formMessage');
            const usersTableBody = document.getElementById('usersTableBody');

            // --- Helper Functions ---
            function showMessage(element, message, type = 'success') {
                element.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
                element.classList.add(`alert-${type}`);
                element.textContent = message;
                element.classList.remove('d-none');
                setTimeout(() => {
                    element.classList.add('d-none');
                }, 3000);
            }

            // --- Fetch Users ---
            async function fetchUsers() {
                try {
                    const response = await fetch(API_URL, {
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await response.json();

                    if (data.success) {
                        renderUsers(data.users);
                    } else {
                        usersTableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Error: ${data.message}</td></tr>`;
                    }
                } catch (error) {
                    console.error('Error fetching users:', error);
                    usersTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Failed to load users.</td></tr>';
                }
            }

            function renderUsers(users) {
                if (users.length === 0) {
                    usersTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No users found.</td></tr>';
                    return;
                }

                usersTableBody.innerHTML = users.map(user => `
                    <tr>
                        <td>${user.user_id}</td>
                        <td><strong>${user.username}</strong></td>
                        <td><span class="badge ${user.role === 'admin' ? 'bg-danger' : 'bg-primary'}">${user.role}</span></td>
                        <td><span class="badge ${user.status === 'Active' ? 'bg-success' : 'bg-secondary'}">${user.status}</span></td>
                        <td><small class="text-muted">${user.created_at}</small></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary table-action-btn" onclick='editUser(${JSON.stringify(user)})' title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${user.user_id})" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            }

            // --- Open Modal (Add Mode) ---
            window.openAddUserModal = () => {
                document.getElementById('userModalLabel').textContent = 'Add New User';
                document.getElementById('userId').value = '';
                document.getElementById('username').value = '';
                document.getElementById('password').value = '';
                document.getElementById('password').required = true; // Password required for new user
                document.getElementById('role').value = 'cashier';
                document.getElementById('status').value = 'Active';
                formMessageDiv.classList.add('d-none');
                userModal.show();
            };

            // --- Open Modal (Edit Mode) ---
            window.editUser = (user) => {
                document.getElementById('userModalLabel').textContent = 'Edit User';
                document.getElementById('userId').value = user.user_id;
                document.getElementById('username').value = user.username;
                document.getElementById('password').value = ''; 
                document.getElementById('password').required = false; // Optional for edit
                document.getElementById('role').value = user.role;
                document.getElementById('status').value = user.status;
                formMessageDiv.classList.add('d-none');
                userModal.show();
            };

            // --- Handle Form Submit (Add/Edit) ---
            userForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const userId = document.getElementById('userId').value;
                const username = document.getElementById('username').value;
                const password = document.getElementById('password').value;
                const role = document.getElementById('role').value;
                const status = document.getElementById('status').value;
                
                const action = userId ? 'update_user' : 'add_user';
                
                const payload = {
                    action: action,
                    user_id: userId,
                    username: username,
                    password: password,
                    role: role,
                    status: status
                };

                try {
                    const response = await fetch(API_URL, {
                        method: 'POST', // Always POST for add/update logic here
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await response.json();

                    if (data.success) {
                        showMessage(userMessageDiv, data.message, 'success');
                        userModal.hide();
                        fetchUsers(); // Refresh the user list
                    } else {
                        showMessage(formMessageDiv, data.message, 'danger');
                    }
                } catch (error) {
                    console.error('Error saving user:', error);
                    showMessage(formMessageDiv, 'An unexpected error occurred.', 'danger');
                }
            });

            // --- Delete User ---
            window.deleteUser = async (userId) => {
                if (!confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                    return;
                }

                try {
                    const response = await fetch(API_URL, {
                        method: 'POST', // Always POST for add/update/delete with custom action
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ user_id: userId, action: 'delete_user' }) // Specify action
                    });

                    const data = await response.json();

                    if (data.success) {
                        showMessage(userMessageDiv, data.message, 'success');
                        fetchUsers(); // Refresh the user list
                    } else {
                        showMessage(userMessageDiv, data.message, 'danger');
                    }
                } catch (error) {
                    console.error('Error deleting user:', error);
                    showMessage(userMessageDiv, 'An unexpected error occurred while deleting the user.', 'danger');
                }
            }

            // --- Initial Load ---
            fetchUsers();
        });
    </script>
</body>
</html>
<?php
// Close database connection if it was opened and not closed earlier due to an API request exit
if ($conn && $is_api_request === false) {
    $conn->close();
}
?>