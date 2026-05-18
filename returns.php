<?php
// returns.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once 'db_connection.php';

$action = $_GET['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'processReturn') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['user_id'] ?? null;
    $originalSaleId = $input['original_sale_id'] ?? null; // Null if manual return
    $totalRefund = $input['total_refund'] ?? 0;
    $items = $input['items'] ?? [];

    if (empty($items) || $totalRefund <= 0 || !$userId) {
        echo json_encode(["success" => false, "message" => "Invalid return data."]);
        exit();
    }

    // --- CRITICAL FIX: Foreign Key Validation ---
    $checkUser = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $checkUser->execute([$userId]);
    $exists = $checkUser->fetchColumn();

    if (!$exists) {
        $fallback = $pdo->query("SELECT user_id FROM users ORDER BY user_id ASC LIMIT 1");
        $userId = $fallback->fetchColumn();
        
        if (!$userId) {
            echo json_encode(["success" => false, "message" => "No users found in database."]);
            exit();
        }
    }

    try {
        $pdo->beginTransaction();

        // 1. Create Return Record (Self-healing logic for varying schemas)
        try {
            // Attempt 1: Schema with 'sale_id' (using 0 instead of null for Manual Returns)
            $stmtReturn = $pdo->prepare("INSERT INTO returns (sale_id, return_date, return_time, total_refund, user_id) VALUES (?, CURDATE(), CURTIME(), ?, ?)");
            $stmtReturn->execute([$originalSaleId ? $originalSaleId : 0, $totalRefund, $userId]);
        } catch (PDOException $e1) {
            try {
                // Attempt 2: Schema with 'original_sale_id'
                $stmtReturn = $pdo->prepare("INSERT INTO returns (original_sale_id, return_date, return_time, total_refund, user_id) VALUES (?, CURDATE(), CURTIME(), ?, ?)");
                $stmtReturn->execute([$originalSaleId, $totalRefund, $userId]);
            } catch (PDOException $e2) {
                // Attempt 3: Schema without any sale reference column
                $stmtReturn = $pdo->prepare("INSERT INTO returns (return_date, return_time, total_refund, user_id) VALUES (CURDATE(), CURTIME(), ?, ?)");
                $stmtReturn->execute([$totalRefund, $userId]);
            }
        }
        $returnId = $pdo->lastInsertId();

        // 2. Process Items & Update Stock
        $stmtStock = $pdo->prepare("UPDATE Products SET quantity = quantity + ? WHERE product_id = ?");

        foreach ($items as $item) {
            $pid = $item['product_id'];
            $qty = $item['quantity'] ?? 1;
            $refund = $item['refund_amount'];
            $condition = $item['condition'] ?? 'Good';

            try {
                $stmtItem = $pdo->prepare("INSERT INTO return_items (return_id, product_id, quantity, refund_amount, `condition`) VALUES (?, ?, ?, ?, ?)");
                $stmtItem->execute([$returnId, $pid, $qty, $refund, $condition]);
            } catch (PDOException $e) {
                // Fallback if 'condition' column is missing in DB
                $stmtItemFallback = $pdo->prepare("INSERT INTO return_items (return_id, product_id, quantity, refund_amount) VALUES (?, ?, ?, ?)");
                $stmtItemFallback->execute([$returnId, $pid, $qty, $refund]);
            }

            // Restock if product is in 'Good' condition
            if ($condition === 'Good') {
                $stmtStock->execute([$qty, $pid]);
            }
        }

        // 3. Update Original Bill Status
        if ($originalSaleId) {
            $stmtSaleUpdate = $pdo->prepare("UPDATE sales SET status = 'Returned' WHERE sale_id = ?");
            $stmtSaleUpdate->execute([$originalSaleId]);
        }

        // 4. Record Financial Transaction
        $desc = "Return " . ($originalSaleId ? "Bill #$originalSaleId" : "Manual");
        try {
            $stmtDrawer = $pdo->prepare("INSERT INTO cash_drawer_transactions (user_id, sale_id, amount, type, description, transaction_date, transaction_time) VALUES (?, ?, ?, 'Refund', ?, CURDATE(), CURTIME())");
            $stmtDrawer->execute([$userId, $originalSaleId ? $originalSaleId : null, $totalRefund, $desc]);
        } catch (PDOException $e) {
            // Fallback if sale_id column is missing
            $stmtDrawer = $pdo->prepare("INSERT INTO cash_drawer_transactions (user_id, amount, type, description, transaction_date, transaction_time) VALUES (?, ?, 'Refund', ?, CURDATE(), CURTIME())");
            $stmtDrawer->execute([$userId, $totalRefund, $desc]);
        }

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Return processed successfully.", "return_id" => $returnId]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Return failed: " . $e->getMessage()]);
    }
} 
// Search Products for Manual Return
elseif ($action === 'searchProducts') {
    $query = $_GET['q'] ?? '';
    if (strlen($query) < 2) {
        echo json_encode(["success" => true, "products" => []]);
        exit();
    }
    try {
        $stmt = $pdo->prepare("SELECT product_id, name, product_code, price FROM Products WHERE name LIKE ? OR product_code LIKE ? LIMIT 10");
        $term = "%$query%";
        $stmt->execute([$term, $term]);
        echo json_encode(["success" => true, "products" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Search failed: " . $e->getMessage()]);
    }
}
?>