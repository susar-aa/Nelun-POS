<?php
// db_connect.php - Centralized database connection for Nelun POS System API

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
} catch (Exception $e) {
    // If connection fails, send a 500 status and JSON error response
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit(); // Stop script execution
}

// Set headers for JSON response and CORS for all API files that include this
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // IMPORTANT: Restrict this to your frontend domain(s) in production!
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS'); // Allow all necessary methods
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
?>
