<?php
// ENABLE DEBUGGING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); 
header('Access-Control-Allow-Headers: Content-Type, Authorization'); 
header('Content-Type: application/json');

// sales.php
require_once 'db_connection.php';

// Prevent PHP execution timeout during transaction
set_time_limit(60); 

$action = $_GET['action'] ?? null;

// --- 1. SAVE NEW SALE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveSale') {
    $input_json = file_get_contents('php://input');
    $input = json_decode($input_json, true);
    
    // Log raw input for debugging
    error_log("Raw Input: " . $input_json);

    $saleData = $input['sale'] ?? [];
    $saleItemsData = $input['items'] ?? [];

    if (empty($saleData) || empty($saleItemsData)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid sale data. Sale or Items array is empty."]);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // 1. Verify User Exists
        // This prevents the generic "Integrity constraint violation" error
        $stmtUserCheck = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
        $stmtUserCheck->execute([$saleData['user_id']]);
        $validUser = $stmtUserCheck->fetchColumn();

        if (!$validUser) {
            // User ID from frontend is invalid. 
            // Try to fallback to ID 1 (Admin) if it exists, otherwise throw error.
            $stmtAdmin = $pdo->query("SELECT user_id FROM users ORDER BY user_id ASC LIMIT 1");
            $fallbackId = $stmtAdmin->fetchColumn();
            
            if ($fallbackId) {
                error_log("Warning: Invalid User ID " . $saleData['user_id'] . ". Falling back to ID $fallbackId.");
                $saleData['user_id'] = $fallbackId; // Auto-correct the ID
            } else {
                throw new Exception("Invalid User ID: " . $saleData['user_id'] . ". No valid users found in DB.");
            }
        }

        // 2. Prepare Sales Insert
        $stmt = $pdo->prepare("INSERT INTO sales (user_id, total_amount, sale_date, sale_time, payment_method, discount_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        try {
            $stmt->execute([
                $saleData['user_id'],
                $saleData['total_amount'],
                $saleData['sale_date'],
                $saleData['sale_time'],
                $saleData['payment_method'],
                $saleData['discount_amount'],
                $saleData['status']
            ]);
        } catch (PDOException $e) {
            throw new Exception("Sales Insert Failed: " . $e->getMessage());
        }
        
        $sale_id = $pdo->lastInsertId();

        // 3. Prepare Item Insert
        $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_at_sale, item_total) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtUpdateStock = $pdo->prepare("UPDATE Products SET quantity = quantity - ? WHERE product_id = ?");

        foreach ($saleItemsData as $item) {
            $pid = $item['product_id'];
            $pName = $item['product_name'] ?? 'Unknown Item';
            $qty = $item['quantity'];
            $price = $item['price_at_sale'];
            $total = $item['item_total'];

            try {
                // Try inserting with original Product ID
                $stmtItem->execute([$sale_id, $pid, $pName, $qty, $price, $total]);

            } catch (PDOException $e) {
                // Check for Integrity Constraint Violation (23000) specifically Foreign Key (1452)
                if ($e->getCode() == '23000' || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1452)) {
                    // RETRY: Insert with '0' as product_id
                    try {
                        $stmtItem->execute([$sale_id, 0, $pName, $qty, $price, $total]);
                    } catch (PDOException $ex) {
                        throw new Exception("Failed to save item '$pName' (ID: $pid). DB Error: " . $ex->getMessage());
                    }
                } else {
                    throw $e; 
                }
            }

            // Update Stock
            if ($saleData['status'] === 'Complete') {
                if (!empty($pid) && strpos($pid, 'GEN-') === false && is_numeric($pid)) { 
                     $stmtUpdateStock->execute([$qty, $pid]);
                }
            }
        }

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Sale saved successfully.", "sale_id" => $sale_id]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log("Sale Error: " . $e->getMessage());
        
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "DB Error: " . $e->getMessage()]);
    }
    
// --- 2. UPDATE EXISTING SALE ---
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateSale') {
    
    $input = json_decode(file_get_contents('php://input'), true);
    $saleData = $input['sale'] ?? [];
    $saleItemsData = $input['items'] ?? [];
    $saleId = $saleData['sale_id'] ?? null;

    if (!$saleId || empty($saleData) || empty($saleItemsData)) {
        echo json_encode(["success" => false, "message" => "Invalid update data."]);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmtOld = $pdo->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
        $stmtOld->execute([$saleId]);
        $oldItems = $stmtOld->fetchAll(PDO::FETCH_ASSOC);

        $stmtRestoreStock = $pdo->prepare("UPDATE Products SET quantity = quantity + ? WHERE product_id = ?");
        foreach ($oldItems as $oldItem) {
            if (strpos($oldItem['product_id'], 'GEN-') === false && is_numeric($oldItem['product_id'])) {
                $stmtRestoreStock->execute([$oldItem['quantity'], $oldItem['product_id']]);
            }
        }

        $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$saleId]);

        $stmtUpdateSale = $pdo->prepare("UPDATE sales SET total_amount = ?, payment_method = ?, discount_amount = ?, status = ?, user_id = ? WHERE sale_id = ?");
        $stmtUpdateSale->execute([
            $saleData['total_amount'], $saleData['payment_method'], $saleData['discount_amount'],
            $saleData['status'], $saleData['user_id'], $saleId
        ]);

        $stmtInsertItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_at_sale, item_total) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtDeductStock = $pdo->prepare("UPDATE Products SET quantity = quantity - ? WHERE product_id = ?");

        foreach ($saleItemsData as $item) {
            $pid = $item['product_id'];
            $stmtInsertItem->execute([
                $saleId, $pid, $item['product_name'] ?? 'Unknown Item',
                $item['quantity'], $item['price_at_sale'], $item['item_total']
            ]);

            if ($saleData['status'] === 'Complete') {
                if (strpos($pid, 'GEN-') === false && is_numeric($pid)) {
                    $stmtDeductStock->execute([$item['quantity'], $pid]);
                }
            }
        }

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Sale updated successfully.", "sale_id" => $saleId]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Update failed: " . $e->getMessage()]);
    }

// --- 3. DELETE SALE ---
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deleteSale') {
    $input = json_decode(file_get_contents('php://input'), true);
    $saleId = $input['sale_id'] ?? null;

    if (!$saleId) {
        echo json_encode(["success" => false, "message" => "Missing Sale ID."]);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
        $stmtItems->execute([$saleId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $stmtStock = $pdo->prepare("UPDATE Products SET quantity = quantity + ? WHERE product_id = ?");
        foreach ($items as $item) {
             if (!empty($item['product_id']) && strpos($item['product_id'], 'GEN-') === false && is_numeric($item['product_id'])) {
                 $stmtStock->execute([$item['quantity'], $item['product_id']]);
             }
        }

        $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$saleId]);
        $pdo->prepare("DELETE FROM cash_drawer_transactions WHERE sale_id = ?")->execute([$saleId]);
        $pdo->prepare("DELETE FROM sales WHERE sale_id = ?")->execute([$saleId]);

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Sale #$saleId deleted and stock restored."]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error deleting sale: " . $e->getMessage()]);
    }

// --- 4. GET SALES HISTORY ---
} elseif ($action === 'getSalesHistory') {
    $startDate = $_GET['start'] ?? date('Y-m-d');
    $endDate = $_GET['end'] ?? date('Y-m-d');
    $searchId = $_GET['id'] ?? '';
    $paymentMethod = $_GET['payment_method'] ?? 'All';
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20; 
    $offset = ($page - 1) * $limit;

    try {
        $baseSql = "FROM sales s LEFT JOIN users u ON s.user_id = u.user_id WHERE s.status = 'Complete'";
        $params = [];
        
        if (!empty($searchId)) {
            $baseSql .= " AND s.sale_id = ?";
            $params[] = $searchId;
        } else {
            $baseSql .= " AND s.sale_date BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
            
            if ($paymentMethod !== 'All') {
                $baseSql .= " AND s.payment_method = ?";
                $params[] = $paymentMethod;
            }
        }

        $countSql = "SELECT COUNT(*) as total " . $baseSql;
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalRecords = $stmtCount->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $sql = "SELECT s.sale_id, s.sale_date, s.sale_time, s.total_amount, s.payment_method, s.status, u.username as cashier_name " . $baseSql . " ORDER BY s.sale_id DESC LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true, 
            "sales" => $sales,
            "pagination" => [
                "current_page" => $page,
                "total_pages" => $totalPages,
                "total_records" => $totalRecords,
                "limit" => $limit
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }

// --- 5. GET SALE DETAILS ---
} elseif ($action === 'getSaleDetails') {
    $saleId = $_GET['sale_id'] ?? 0;
    try {
        $stmt = $pdo->prepare("SELECT s.*, u.username as cashier_name FROM sales s LEFT JOIN users u ON s.user_id = u.user_id WHERE sale_id = ?");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            echo json_encode(['success' => false, 'message' => "Sale not found"]);
            exit();
        }

        $stmtItems = $pdo->prepare("
            SELECT si.*, COALESCE(si.product_name, p.name) as product_name, p.product_code 
            FROM sale_items si 
            LEFT JOIN Products p ON si.product_id = p.product_id 
            WHERE si.sale_id = ?
        ");
        $stmtItems->execute([$saleId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $mappedItems = array_map(function($item) {
             return [
                 'product_id' => $item['product_id'],
                 'name' => $item['product_name'] ?? 'Unknown Item',
                 'qty' => $item['quantity'],
                 'price' => $item['price_at_sale'],
                 'total' => $item['item_total'],
                 'discountTotal' => ($item['quantity'] * $item['price_at_sale']) - $item['item_total']
             ];
        }, $items);

        echo json_encode(['success' => true, 'sale' => $sale, 'items' => $mappedItems]);
    } catch (PDOException $e) {
         echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

// --- HELD BILL ACTIONS ---
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'completeHeldBill') {
    $input = json_decode(file_get_contents('php://input'), true);
    $saleData = $input['sale'] ?? [];
    $saleItemsData = $input['items'] ?? [];

    if (empty($saleData) || empty($saleData['sale_id'])) {
        echo json_encode(["success" => false, "message" => "Invalid held bill data."]);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE sales SET status = 'Complete', payment_method = ?, total_amount = ?, discount_amount = ?, sale_date = ?, sale_time = ? WHERE sale_id = ?");
        $stmt->execute([
            $saleData['payment_method'],
            $saleData['total_amount'],
            $saleData['discount_amount'],
            $saleData['sale_date'],
            $saleData['sale_time'],
            $saleData['sale_id']
        ]);

        $stmtUpdateStock = $pdo->prepare("UPDATE Products SET quantity = quantity - ? WHERE product_id = ?");
        foreach ($saleItemsData as $item) {
            if (!empty($item['product_id']) && strpos($item['product_id'], 'GEN-') === false && is_numeric($item['product_id'])) {
                 $stmtUpdateStock->execute([$item['quantity'], $item['product_id']]);
            }
        }

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Held bill completed.", "sale_id" => $saleData['sale_id']]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }

} elseif ($action === 'getHeldBills') {
    $stmt = $pdo->query("SELECT * FROM sales WHERE status = 'Hold' ORDER BY sale_id DESC");
    echo json_encode(["success" => true, "held_bills" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($action === 'deleteHeldBill') {
    $input = json_decode(file_get_contents('php://input'), true);
    $saleId = $input['sale_id'] ?? null;
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$saleId]);
        $pdo->prepare("DELETE FROM sales WHERE sale_id = ?")->execute([$saleId]);
        $pdo->commit();
        echo json_encode(["success" => true]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(["success" => false]); }

} elseif ($action === 'getHeldBillDetails') {
    $saleId = $_GET['sale_id'];
    $sale = $pdo->prepare("SELECT * FROM sales WHERE sale_id = ?");
    $sale->execute([$saleId]);
    
    $stmt_items = $pdo->prepare("
            SELECT si.*, COALESCE(si.product_name, p.name) as name, p.product_code
            FROM sale_items si
            LEFT JOIN Products p ON si.product_id = p.product_id
            WHERE si.sale_id = ?
        ");
    $stmt_items->execute([$saleId]);
    
    echo json_encode(["success" => true, "sale" => $sale->fetch(PDO::FETCH_ASSOC), "items" => $stmt_items->fetchAll(PDO::FETCH_ASSOC)]);
}
?>