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

// Helper function to process sale item using FIFO batch allocation
function processFIFOSaleItem($pdo, $sale_id, $pid, $pName, $qty, $price, $total) {
    if (empty($pid) || strpos($pid, 'GEN-') !== false || !is_numeric($pid)) {
        // Non-inventory general item
        $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_at_sale, item_total, cost_price, batch_id) VALUES (?, ?, ?, ?, ?, ?, 0.00, NULL)");
        $stmtItem->execute([$sale_id, $pid, $pName, $qty, $price, $total]);
        return;
    }

    $required_qty = $qty;

    // Get active batches for product sorted by oldest first
    $stmtBatches = $pdo->prepare("SELECT batch_id, quantity_remaining, cost_price FROM product_batches WHERE product_id = ? AND status = 'Active' AND quantity_remaining > 0 ORDER BY created_at ASC");
    $stmtBatches->execute([$pid]);
    $batches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

    $stmtInsertItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_at_sale, item_total, cost_price, batch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtUpdateBatch = $pdo->prepare("UPDATE product_batches SET quantity_remaining = ?, status = ? WHERE batch_id = ?");
    $stmtUpdateStock = $pdo->prepare("UPDATE Products SET quantity = quantity - ? WHERE product_id = ?");

    if (empty($batches)) {
        // Fallback: No batches exist. Fetch general cost from Products
        $stmtCost = $pdo->prepare("SELECT cost FROM Products WHERE product_id = ?");
        $stmtCost->execute([$pid]);
        $prodCost = (float)$stmtCost->fetchColumn();

        $stmtInsertItem->execute([$sale_id, $pid, $pName, $qty, $price, $total, $prodCost, null]);
        $stmtUpdateStock->execute([$qty, $pid]);
        return;
    }

    foreach ($batches as $batch) {
        if ($required_qty <= 0) break;

        $batch_id = $batch['batch_id'];
        $avail = (int)$batch['quantity_remaining'];
        $cost = (float)$batch['cost_price'];

        if ($avail >= $required_qty) {
            $new_avail = $avail - $required_qty;
            $status = ($new_avail == 0) ? 'Depleted' : 'Active';
            $stmtUpdateBatch->execute([$new_avail, $status, $batch_id]);

            // Proportional item total fraction
            $fraction_total = $required_qty * $price;

            $stmtInsertItem->execute([$sale_id, $pid, $pName, $required_qty, $price, $fraction_total, $cost, $batch_id]);
            $stmtUpdateStock->execute([$required_qty, $pid]);

            $required_qty = 0;
        } else {
            $stmtUpdateBatch->execute([0, 'Depleted', $batch_id]);

            $fraction_total = $avail * $price;

            $stmtInsertItem->execute([$sale_id, $pid, $pName, $avail, $price, $fraction_total, $cost, $batch_id]);
            $stmtUpdateStock->execute([$avail, $pid]);

            $required_qty -= $avail;
        }
    }

    // In case stock is negative or exceeds available batches
    if ($required_qty > 0) {
        $stmtCost = $pdo->prepare("SELECT cost FROM Products WHERE product_id = ?");
        $stmtCost->execute([$pid]);
        $prodCost = (float)$stmtCost->fetchColumn();

        $fraction_total = $required_qty * $price;
        $stmtInsertItem->execute([$sale_id, $pid, $pName, $required_qty, $price, $fraction_total, $prodCost, null]);
        $stmtUpdateStock->execute([$required_qty, $pid]);
    }
}

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
        $branch_id = isset($saleData['branch_id']) ? $saleData['branch_id'] : null;
        $stmt = $pdo->prepare("INSERT INTO sales (user_id, branch_id, total_amount, sale_date, sale_time, payment_method, discount_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        try {
            $stmt->execute([
                $saleData['user_id'],
                $branch_id,
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

        // 3. Save sale items using FIFO costing
        foreach ($saleItemsData as $item) {
            $pid = $item['product_id'];
            $pName = $item['product_name'] ?? 'Unknown Item';
            $qty = $item['quantity'];
            $price = $item['price_at_sale'];
            $total = $item['item_total'];

            if ($saleData['status'] === 'Complete') {
                processFIFOSaleItem($pdo, $sale_id, $pid, $pName, $qty, $price, $total);
            } else {
                // If Hold, just insert into sale_items without stock deduction
                $stmtCost = $pdo->prepare("SELECT cost FROM Products WHERE product_id = ?");
                $stmtCost->execute([$pid]);
                $prodCost = (float)$stmtCost->fetchColumn();

                $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_at_sale, item_total, cost_price, batch_id) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)");
                $stmtItem->execute([$sale_id, $pid, $pName, $qty, $price, $total, $prodCost]);
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

        $stmtOld = $pdo->prepare("SELECT product_id, quantity, batch_id FROM sale_items WHERE sale_id = ?");
        $stmtOld->execute([$saleId]);
        $oldItems = $stmtOld->fetchAll(PDO::FETCH_ASSOC);

        $stmtRestoreStock = $pdo->prepare("UPDATE Products SET quantity = quantity + ? WHERE product_id = ?");
        $stmtRestoreBatch = $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining + ?, status = 'Active' WHERE batch_id = ?");
        
        foreach ($oldItems as $oldItem) {
            if (strpos($oldItem['product_id'], 'GEN-') === false && is_numeric($oldItem['product_id'])) {
                $stmtRestoreStock->execute([$oldItem['quantity'], $oldItem['product_id']]);
                if (!empty($oldItem['batch_id'])) {
                    $stmtRestoreBatch->execute([$oldItem['quantity'], $oldItem['batch_id']]);
                }
            }
        }

        $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$saleId]);

        $stmtUpdateSale = $pdo->prepare("UPDATE sales SET total_amount = ?, payment_method = ?, discount_amount = ?, status = ?, user_id = ? WHERE sale_id = ?");
        $stmtUpdateSale->execute([
            $saleData['total_amount'], $saleData['payment_method'], $saleData['discount_amount'],
            $saleData['status'], $saleData['user_id'], $saleId
        ]);

        foreach ($saleItemsData as $item) {
            $pid = $item['product_id'];
            $pName = $item['product_name'] ?? 'Unknown Item';
            $qty = $item['quantity'];
            $price = $item['price_at_sale'];
            $total = $item['item_total'];

            if ($saleData['status'] === 'Complete') {
                processFIFOSaleItem($pdo, $saleId, $pid, $pName, $qty, $price, $total);
            } else {
                $stmtCost = $pdo->prepare("SELECT cost FROM Products WHERE product_id = ?");
                $stmtCost->execute([$pid]);
                $prodCost = (float)$stmtCost->fetchColumn();

                $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_at_sale, item_total, cost_price, batch_id) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)");
                $stmtItem->execute([$saleId, $pid, $pName, $qty, $price, $total, $prodCost]);
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

        $stmtItems = $pdo->prepare("SELECT product_id, quantity, batch_id FROM sale_items WHERE sale_id = ?");
        $stmtItems->execute([$saleId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $stmtStock = $pdo->prepare("UPDATE Products SET quantity = quantity + ? WHERE product_id = ?");
        $stmtRestoreBatch = $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining + ?, status = 'Active' WHERE batch_id = ?");
        
        foreach ($items as $item) {
             if (!empty($item['product_id']) && strpos($item['product_id'], 'GEN-') === false && is_numeric($item['product_id'])) {
                 $stmtStock->execute([$item['quantity'], $item['product_id']]);
                 if (!empty($item['batch_id'])) {
                     $stmtRestoreBatch->execute([$item['quantity'], $item['batch_id']]);
                 }
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

        // Fetch original hold items
        $stmtItems = $pdo->prepare("SELECT product_id, product_name, quantity, price_at_sale, item_total FROM sale_items WHERE sale_id = ?");
        $stmtItems->execute([$saleData['sale_id']]);
        $heldItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Delete hold dummy items and process through FIFO allocation
        $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$saleData['sale_id']]);
        
        foreach ($heldItems as $item) {
            processFIFOSaleItem($pdo, $saleData['sale_id'], $item['product_id'], $item['product_name'], $item['quantity'], $item['price_at_sale'], $item['item_total']);
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