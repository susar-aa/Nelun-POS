<?php 
// login.php - Backend API for user authentication 
// Path: https://nelun.suzxlabs.com/Api/login.php 

// Set headers for JSON response and CORS 
header('Content-Type: application/json'); 
header('Access-Control-Allow-Origin: *'); // IMPORTANT: Restrict this to your frontend domain(s) in production! 
header('Access-Control-Allow-Methods: POST, OPTIONS'); 
header('Access-Control-Allow-Headers: Content-Type, Authorization'); 

// Handle preflight OPTIONS request for CORS 
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(200); 
    exit(); 
} 

// Ensure it's a POST request for login 
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); // Method Not Allowed 
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed for this endpoint.']); 
    exit();
} 

// Database connection details 
$host = 'localhost'; 
$port = '3306'; 
$dbname = 'Nelun_db'; 
$username_db = 'suzxlabs';
$password_db = 'Susara@200611003614';

$conn = null; // Initialize connection variable 

try { 
    // Establish database connection using MySQLi 
    $conn = new mysqli($host, $username_db, $password_db, $dbname, $port); 

    // Check connection 
    if ($conn->connect_error) { 
        error_log("Database connection failed: " . $conn->connect_error); 
        throw new Exception("Database connection error. Please try again later."); 
    } 

    // Get raw POST data for JSON input 
    $input = file_get_contents('php://input'); 
    $data = json_decode($input, true); 

    $username = $data['username'] ?? ''; 
    $password = $data['password'] ?? ''; 

    // Basic input validation 
    if (empty($username) || empty($password)) { 
        echo json_encode(['success' => false, 'message' => 'Username and password are required.']); 
        exit(); 
    } 

    // Prepare a SQL statement to prevent SQL injection 
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, status FROM users WHERE username = ?"); 
    if (!$stmt) { 
        error_log("Prepare statement failed: " . $conn->error); 
        throw new Exception("Internal server error. Please try again later."); 
    } 

    $stmt->bind_param("s", $username); 
    $stmt->execute(); 
    $result = $stmt->get_result(); 

    if ($result->num_rows === 1) { 
        $user = $result->fetch_assoc(); 

        // Verify the password 
        if (password_verify($password, $user['password_hash'])) { 
            // Check user status 
            if ($user['status'] === 'Active') { 
                // Login successful 
                // In a real application, you would typically generate a session token or JWT here 
                echo json_encode([ 
                    'success' => true, 
                    'message' => 'Login successful!', 
                    'user_id' => $user['user_id'], 
                    'username' => $user['username'], 
                    'role' => $user['role'] 
                ]); 
            } else { 
                echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact support.']); 
            } 
        } else { 
            echo json_encode(['success' => false, 'message' => 'Invalid username or password.']); 
        } 
    } else { 
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']); 
    } 

    $stmt->close(); 

} catch (Exception $e) { 
    http_response_code(500); // Internal Server Error 
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]); 
} finally { 
    // Close the database connection if it was opened 
    if ($conn) { 
        $conn->close(); 
    } 
} 
?>