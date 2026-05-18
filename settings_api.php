<?php
// settings_api.php
// Handles sensitive system settings and bulk operations

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once 'db_connection.php';

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = $_GET['action'] ?? null;

// --- RESET ALL STOCK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reset_stock') {
    
    // ADMIN ACCESS CHECK REMOVED: Anyone can perform this action now.

    try {
        $pdo->beginTransaction();

        // Set all product quantities to 0
        $stmt = $pdo->prepare("UPDATE Products SET quantity = 0");
        $stmt->execute();
        $count = $stmt->rowCount();

        $pdo->commit();
        
        echo json_encode([
            "success" => true, 
            "message" => "Success! Stock count for all products has been reset to 0."
        ]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            "success" => false, 
            "message" => "Database Error: " . $e->getMessage()
        ]);
    }
    exit();
}

// Default response
echo json_encode(["success" => false, "message" => "Invalid Action"]);
?>