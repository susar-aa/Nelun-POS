<?php
// branch_inventory_api.php
// Handles per-branch stock levels separately from the global Products table.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db_connection.php';

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
if (!$action && isset($input['action'])) $action = $input['action'];

// -------------------------------------------------------------------
// 1. GET STOCK FOR A BRANCH
//    Returns all products with their branch-specific quantity.
//    If no branch_inventory row exists, it falls back to the global
//    Products.quantity (useful for existing data before migration).
// -------------------------------------------------------------------
if ($action === 'get_branch_stock') {
    $branch_id = intval($_GET['branch_id'] ?? 0);
    if (!$branch_id) { echo json_encode(['success'=>false,'message'=>'branch_id required']); exit; }

    try {
        $stmt = $pdo->prepare("
            SELECT
                p.product_id,
                p.product_code,
                p.name,
                p.price,
                p.cost,
                p.category_id,
                p.supplier_id,
                p.reorder_level,
                COALESCE(bi.quantity, p.quantity) AS quantity,
                COALESCE(bi.reorder_level, p.reorder_level, 10) AS branch_reorder_level,
                bi.inventory_id
            FROM Products p
            LEFT JOIN branch_inventory bi ON bi.product_id = p.product_id AND bi.branch_id = ?
            ORDER BY p.name ASC
        ");
        $stmt->execute([$branch_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'products' => $products]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------
// 2. UPDATE BRANCH STOCK (upsert)
//    Called when a GRN is received for a specific branch, or when
//    admin manually adjusts stock for a branch.
// -------------------------------------------------------------------
if ($action === 'update_branch_stock') {
    $branch_id  = intval($input['branch_id'] ?? 0);
    $product_id = intval($input['product_id'] ?? 0);
    $qty_change = intval($input['qty_change'] ?? 0); // positive = add, negative = remove
    $set_qty    = isset($input['set_qty']) ? intval($input['set_qty']) : null; // absolute set

    if (!$branch_id || !$product_id) {
        echo json_encode(['success'=>false,'message'=>'branch_id and product_id required']); exit;
    }

    try {
        if ($set_qty !== null) {
            // Absolute set (manual adjustment)
            $stmt = $pdo->prepare("
                INSERT INTO branch_inventory (branch_id, product_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ");
            $stmt->execute([$branch_id, $product_id, $set_qty]);
        } else {
            // Delta change (GRN receive / sale deduction)
            $stmt = $pdo->prepare("
                INSERT INTO branch_inventory (branch_id, product_id, quantity)
                VALUES (?, ?, GREATEST(0, ?))
                ON DUPLICATE KEY UPDATE quantity = GREATEST(0, quantity + ?)
            ");
            $stmt->execute([$branch_id, $product_id, $qty_change, $qty_change]);
        }
        echo json_encode(['success' => true, 'message' => 'Branch stock updated.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------
// 3. GET LOW STOCK FOR A BRANCH
//    Returns items where branch quantity <= branch reorder level.
// -------------------------------------------------------------------
if ($action === 'get_low_stock') {
    $branch_id = intval($_GET['branch_id'] ?? 0);

    try {
        $sql = "
            SELECT
                p.product_id, p.name, p.product_code,
                COALESCE(bi.quantity, p.quantity) AS quantity,
                COALESCE(bi.reorder_level, p.reorder_level, 10) AS reorder_level,
                b.branch_name
            FROM Products p
            LEFT JOIN branch_inventory bi ON bi.product_id = p.product_id
            LEFT JOIN branches b ON bi.branch_id = b.branch_id
            WHERE COALESCE(bi.quantity, p.quantity) <= COALESCE(bi.reorder_level, p.reorder_level, 10)
        ";
        $params = [];
        if ($branch_id) {
            $sql .= " AND (bi.branch_id = ? OR bi.branch_id IS NULL)";
            $params[] = $branch_id;
        }
        $sql .= " ORDER BY quantity ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------
// 4. SEED branch_inventory FROM EXISTING Products.quantity
//    Run once to pre-populate branch_inventory for existing stock.
//    Only assigns to branch_id=1 (Main Branch) as the primary source.
// -------------------------------------------------------------------
if ($action === 'seed_branch_inventory') {
    try {
        // Get all branches
        $branches = $pdo->query("SELECT branch_id FROM branches")->fetchAll(PDO::FETCH_COLUMN);
        $products = $pdo->query("SELECT product_id, quantity, reorder_level FROM Products WHERE quantity > 0")->fetchAll(PDO::FETCH_ASSOC);

        $inserted = 0;
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO branch_inventory (branch_id, product_id, quantity, reorder_level)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($branches as $bid) {
            foreach ($products as $p) {
                // Only main branch (id=1) gets the actual quantity; others start at 0
                $qty = ($bid == 1) ? $p['quantity'] : 0;
                $stmt->execute([$bid, $p['product_id'], $qty, $p['reorder_level'] ?? 10]);
                $inserted++;
            }
        }

        echo json_encode(['success' => true, 'message' => "Seeded $inserted branch inventory rows."]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
?>
