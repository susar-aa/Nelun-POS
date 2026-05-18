<?php
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); 
header('Access-Control-Allow-Headers: Content-Type, Authorization'); 

// stock.php
header('Content-Type: application/json');
require_once 'db_connection.php';

$action = $_GET['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateStock') {
    // Legacy single update (keep for POS modal)
    $input = json_decode(file_get_contents('php://input'), true);
    $product_id = $input['product_id'] ?? null;
    $quantity_to_add = $input['quantity_to_add'] ?? null;

    if (!is_numeric($product_id) || !is_numeric($quantity_to_add)) {
        echo json_encode(["success" => false, "message" => "Invalid product ID or quantity."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE Products SET quantity = quantity + ? WHERE product_id = ?");
        $stmt->execute([$quantity_to_add, $product_id]);
        echo json_encode(["success" => true, "message" => "Stock updated successfully!"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulkAddStock') {
    // --- NEW: Bulk Stock Addition ---
    $input = json_decode(file_get_contents('php://input'), true);
    $items = $input['items'] ?? [];

    if (empty($items)) {
        echo json_encode(["success" => false, "message" => "No items to add."]);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmtQty = $pdo->prepare("UPDATE Products SET quantity = quantity + ? WHERE product_id = ?");
        $stmtQtyCost = $pdo->prepare("UPDATE Products SET quantity = quantity + ?, cost = ? WHERE product_id = ?");

        $updatedCount = 0;

        foreach ($items as $item) {
            $pid = $item['product_id'];
            $qty = intval($item['add_qty']);
            $cost = isset($item['new_cost']) && $item['new_cost'] !== '' ? floatval($item['new_cost']) : null;

            if ($qty > 0) {
                if ($cost !== null && $cost > 0) {
                    // Update quantity AND cost
                    $stmtQtyCost->execute([$qty, $cost, $pid]);
                } else {
                    // Update quantity ONLY
                    $stmtQty->execute([$qty, $pid]);
                }
                $updatedCount++;
            }
        }

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Stock added successfully for $updatedCount products."]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Transaction failed: " . $e->getMessage()]);
    }
}
?>