<?php
// notifications_api.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
require_once 'db_connection.php';

try {
    $notifications = [];
    $total_count = 0;

    // 1. Stock Alerts (Quantity <= Reorder Level)
    $stmtStock = $pdo->query("SELECT product_id, name, quantity, reorder_level FROM Products WHERE status = 'Active' AND quantity <= reorder_level ORDER BY quantity ASC LIMIT 20");
    $stockAlerts = $stmtStock->fetchAll(PDO::FETCH_ASSOC);
    $stockCount = count($stockAlerts);
    if ($stockCount > 0) {
        $total_count += $stockCount;
        foreach ($stockAlerts as $item) {
            $msg = ($item['quantity'] == 0) ? "Out of Stock" : "Low Stock (" . $item['quantity'] . " left)";
            $notifications[] = [
                'type' => 'stock',
                'title' => 'Inventory Alert',
                'message' => "{$item['name']} is $msg (Reorder Level: {$item['reorder_level']})",
                'color' => ($item['quantity'] == 0) ? 'danger' : 'warning',
                'link' => 'product-management.php',
                'icon' => 'bi-box-seam'
            ];
        }
    }

    // 2. Cheque Reminders (Pending and banking_date <= 7 days from today)
    $stmtCheques = $pdo->query("SELECT cheque_number, payee_payer_name, banking_date, amount, DATEDIFF(banking_date, CURDATE()) as days_left 
                                FROM cheques 
                                WHERE status = 'Pending' AND banking_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
                                ORDER BY banking_date ASC");
    $cheques = $stmtCheques->fetchAll(PDO::FETCH_ASSOC);
    $chequeCount = count($cheques);
    if ($chequeCount > 0) {
        $total_count += $chequeCount;
        foreach ($cheques as $c) {
            $days = (int)$c['days_left'];
            $timeMsg = ($days < 0) ? "OVERDUE by " . abs($days) . " days!" : (($days == 0) ? "Bank TODAY!" : "Bank in $days days");
            $color = ($days <= 1) ? 'danger' : 'primary';
            $notifications[] = [
                'type' => 'cheque',
                'title' => 'Cheque Reminder',
                'message' => "Chq #{$c['cheque_number']} for {$c['payee_payer_name']} (Rs. " . number_format($c['amount'], 2) . ") - $timeMsg",
                'color' => $color,
                'link' => 'cheques-management.php',
                'icon' => 'bi-bank'
            ];
        }
    }

    // 3. Supplier Payments (Outstanding balances)
    $stmtSuppliers = $pdo->query("
        SELECT s.name, (SUM(l.credit) - SUM(l.debit)) as outstanding
        FROM suppliers s
        JOIN supplier_ledger l ON s.supplier_id = l.supplier_id
        GROUP BY s.supplier_id
        HAVING outstanding > 0
        ORDER BY outstanding DESC LIMIT 10
    ");
    $payables = $stmtSuppliers->fetchAll(PDO::FETCH_ASSOC);
    $payableCount = count($payables);
    if ($payableCount > 0) {
        $total_count += $payableCount;
        foreach ($payables as $p) {
            $notifications[] = [
                'type' => 'payment',
                'title' => 'Pending Payable',
                'message' => "Owe Rs. " . number_format($p['outstanding'], 2) . " to {$p['name']}",
                'color' => 'secondary',
                'link' => 'suppliers.php',
                'icon' => 'bi-wallet2'
            ];
        }
    }

    echo json_encode([
        "success" => true,
        "total_count" => $total_count,
        "notifications" => $notifications
    ]);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
