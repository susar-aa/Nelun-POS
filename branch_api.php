<?php
// branch_api.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once 'db_connection.php';

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];
$input = [];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!$action) $action = $input['action'] ?? null;
}

// 1. Initialize Branch Schema
if ($action === 'init_schema') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS branches (
            branch_id INT AUTO_INCREMENT PRIMARY KEY,
            branch_name VARCHAR(100) NOT NULL,
            location VARCHAR(200) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Ensure users table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            user_id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('Admin', 'Cashier') DEFAULT 'Cashier',
            branch_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Try adding role and branch_id to existing users table if it was created differently
        try { $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('Admin', 'Cashier') DEFAULT 'Cashier';"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('Admin', 'Cashier') DEFAULT 'Cashier';"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN branch_id INT NULL;"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL;"); } catch(Exception $e){}

        // Ensure sales table has branch_id
        try { $pdo->exec("ALTER TABLE sales ADD COLUMN branch_id INT NULL;"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE sales ADD CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL;"); } catch(Exception $e){}
        
        // Insert Default Branches
        $bCount = $pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn();
        if ($bCount == 0) {
            $pdo->exec("INSERT INTO branches (branch_name, location) VALUES ('Nelun Main Branch', 'Kurunegala'), ('City Branch', 'Colombo')");
        }
        
        // Ensure default users
        $uCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($uCount == 0) {
            $hash = password_hash('1234', PASSWORD_DEFAULT);
            $pdo->exec("INSERT INTO users (username, password_hash, role, branch_id) VALUES ('admin', '$hash', 'Admin', 1), ('user1', '$hash', 'Cashier', 2)");
        } else {
            // Force the primary user (ID 1) to be an Admin to prevent lockout
            $pdo->exec("UPDATE users SET role = 'Admin', branch_id = 1 WHERE user_id = 1");
            // Migrate old 'Branch_User' roles to 'Cashier' to prevent data loss
            $pdo->exec("UPDATE users SET role = 'Cashier' WHERE role = 'Branch_User' OR role = 'Staff'");
        }
        
        // Update existing sales to Branch 1 if not set
        $pdo->exec("UPDATE sales SET branch_id = 1 WHERE branch_id IS NULL");

        echo json_encode(['success' => true, 'message' => 'Branch schema initialized successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 2. Login Endpoint
if ($action === 'login') {
    $user = trim($input['username'] ?? '');
    $pass = trim($input['password'] ?? '');
    
    if (!$user || !$pass) {
        echo json_encode(['success' => false, 'message' => 'Username and password required.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.username, u.password_hash, u.role, u.status, u.branch_id, b.branch_name 
            FROM users u 
            LEFT JOIN branches b ON u.branch_id = b.branch_id 
            WHERE u.username = ?
        ");
        $stmt->execute([$user]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($u) {
            // Check if account is active
            if (isset($u['status']) && strtolower($u['status']) === 'inactive') {
                echo json_encode(['success' => false, 'message' => 'Your account has been disabled. Contact your administrator.']);
                exit;
            }

            // Verify password
            $db_pass = $u['password_hash'] ?? '';
            $valid = password_verify($pass, $db_pass);
            
            // Fallback: allow plain-text match ONLY for old non-hashed passwords
            if (!$valid && !str_starts_with($db_pass, '$2')) {
                $valid = ($pass === $db_pass);
            }
            
            if ($valid) {
                // --- STRICT MODIFICATION: Register Secure Session Constraints ---
                $_SESSION['user_id'] = $u['user_id'];
                $_SESSION['username'] = $u['username'];
                $_SESSION['role'] = $u['role'];
                $_SESSION['branch_id'] = $u['branch_id'];
                
                echo json_encode([
                    'success' => true, 
                    'user' => [
                        'user_id'     => $u['user_id'],
                        'username'    => $u['username'],
                        'role'        => $u['role'],
                        'branch_id'   => $u['branch_id'],
                        'branch_name' => $u['branch_name'] ?? 'All Branches'
                    ]
                ]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// 3. Get All Branches
if ($action === 'get_branches') {
    try {
        $stmt = $pdo->query("SELECT * FROM branches ORDER BY branch_id ASC");
        echo json_encode(['success' => true, 'branches' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>