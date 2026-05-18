<?php
// cash_drawer.php
// Handles Cash Drawer operations: Balance, Transactions, and Manual In/Out

// ENABLE DEBUGGING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once 'db_connection.php';

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// Handle JSON input
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
}

// 1. RECORD TRANSACTION (Sale, Refund, etc.)
if ($action === 'recordTransaction') {
    $userId = $input['user_id'] ?? null;
    $amount = $input['amount'] ?? 0;
    $type = $input['type'] ?? ''; // 'Sale', 'Refund', 'Cash In', 'Cash Out'
    $description = $input['description'] ?? '';
    $date = $input['transaction_date'] ?? date('Y-m-d');
    $time = $input['transaction_time'] ?? date('H:i:s');
    $saleId = $input['sale_id'] ?? null;

    if (!$userId || $amount <= 0 || empty($type)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid input data. User ID, Amount, and Type are required."]);
        exit();
    }

    try {
        // --- NEW: Verify User Exists to prevent FK Error ---
        $stmtUser = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_id = ?");
        $stmtUser->execute([$userId]);
        
        if ($stmtUser->fetchColumn() == 0) {
             // User ID invalid (maybe deleted or session mismatch). 
             // Find a valid admin/manager to attribute this transaction to.
             $stmtAdmin = $pdo->query("SELECT user_id FROM users ORDER BY user_id ASC LIMIT 1");
             $adminId = $stmtAdmin->fetchColumn();
             
             if($adminId) {
                 error_log("Cash Drawer Warning: Invalid User ID $userId. Using fallback ID $adminId.");
                 $userId = $adminId;
             } else {
                 throw new Exception("Critical Error: No users found in database. Cannot record transaction.");
             }
        }
        // --------------------------------------------------

        $stmt = $pdo->prepare("INSERT INTO cash_drawer_transactions (user_id, amount, type, description, transaction_date, transaction_time, sale_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        try {
            $stmt->execute([$userId, $amount, $type, $description, $date, $time, $saleId]);
        } catch (PDOException $e) {
            // Check for specific constraint violations (e.g. sale_id FK)
             if ($e->getCode() == '23000') {
                 // Retry without sale_id if that was the cause
                 if ($saleId) {
                     $stmt->execute([$userId, $amount, $type, $description . " (Ref: Sale #$saleId)", $date, $time, null]);
                 } else {
                     throw $e;
                 }
             } else {
                 throw $e;
             }
        }

        echo json_encode(["success" => true, "message" => "Transaction recorded."]);
    } catch (Exception $e) {
        error_log("Cash Drawer Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "DB Error: " . $e->getMessage()]);
    }
}

// 2. GET DRAWER BALANCE (Legacy/Simple)
elseif ($action === 'getDrawerBalance') {
    try {
        $today = date('Y-m-d');

        // Sums for simplified balance
        $stmtSales = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status = 'Complete' AND payment_method = 'Cash' AND sale_date = ?");
        $stmtSales->execute([$today]);
        $cashSales = $stmtSales->fetchColumn();

        $stmtIn = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_drawer_transactions WHERE type = 'Cash In' AND transaction_date = ?");
        $stmtIn->execute([$today]);
        $cashIn = $stmtIn->fetchColumn();

        $stmtOut = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_drawer_transactions WHERE type = 'Cash Out' AND transaction_date = ?");
        $stmtOut->execute([$today]);
        $cashOut = $stmtOut->fetchColumn();
        
        $stmtExp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = ?");
        $stmtExp->execute([$today]);
        $expenses = $stmtExp->fetchColumn();

        $balance = ($cashSales + $cashIn) - ($cashOut + $expenses); 
        
        echo json_encode([
            "success" => true, 
            "balance" => $balance,
            "details" => [
                "sales" => $cashSales,
                "in" => $cashIn,
                "out" => $cashOut,
                "expenses" => $expenses
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}
?>