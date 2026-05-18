<?php
// pos_api.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once 'db_connection.php';

function respondWithError($message, $statusCode = 500) {
    http_response_code($statusCode);
    echo json_encode(["success" => false, "message" => $message]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$input_data = [];

if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
    $input_data = json_decode($raw_input, true);
    $action = $input_data['action'] ?? null;
}

// Helper: Validate or Fallback User ID
function getValidUserId($pdo, $requestedUserId) {
    // 1. Check if requested user exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->execute([$requestedUserId]);
    if ($stmt->fetchColumn()) {
        return $requestedUserId;
    }

    // 2. Fallback: Get first available user (Admin usually ID 1)
    $stmtAdmin = $pdo->query("SELECT user_id FROM users ORDER BY user_id ASC LIMIT 1");
    $fallbackId = $stmtAdmin->fetchColumn();
    
    if ($fallbackId) {
        error_log("POS API Warning: Invalid User ID $requestedUserId. Using fallback ID $fallbackId.");
        return $fallbackId;
    }
    
    // 3. Fail if DB is empty
    respondWithError("Critical: No users found in database. Cannot record transaction.", 500);
}


// --- 1. GET CASH DRAWER BALANCE ---
if ($method === 'GET' && $action === 'get_cash_drawer_balance') {
    try {
        $stmtDrawerCalc = $pdo->query("
            SELECT 
                (SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status='Complete' AND payment_method='Cash') +
                (SELECT COALESCE(SUM(amount), 0) FROM cash_drawer_transactions WHERE type='Cash In') -
                (SELECT COALESCE(SUM(amount), 0) FROM cash_drawer_transactions WHERE type IN ('Cash Out', 'Refund')) -
                (SELECT COALESCE(SUM(amount), 0) FROM expenses) 
            as balance
        ");
        $balance = $stmtDrawerCalc->fetchColumn();
        echo json_encode(["success" => true, "balance" => $balance]);
    } catch (PDOException $e) {
        respondWithError("Error: " . $e->getMessage());
    }
}

// --- 2. ENHANCED DASHBOARD SUMMARY ---
elseif ($method === 'GET' && $action === 'get_enhanced_dashboard_summary') {
    try {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $stmtToday = $pdo->query("
            SELECT 
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(SUM(CASE WHEN payment_method = 'Cash' THEN total_amount ELSE 0 END), 0) as cash_sales,
                COALESCE(SUM(CASE WHEN payment_method = 'Card' THEN total_amount ELSE 0 END), 0) as card_sales,
                COUNT(sale_id) as bill_count,
                COUNT(CASE WHEN payment_method = 'Cash' THEN 1 END) as cash_bills,
                COUNT(CASE WHEN payment_method = 'Card' THEN 1 END) as card_bills
            FROM sales 
            WHERE status = 'Complete' AND sale_date = '$today'
        ");
        $todayData = $stmtToday->fetch();

        $stmtExp = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date = '$today'");
        $todayExpense = $stmtExp->fetchColumn();

        $stmtCashIn = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cash_drawer_transactions WHERE type = 'Cash In' AND transaction_date = '$today'");
        $todayCashIn = $stmtCashIn->fetchColumn();

        $stmtProfit = $pdo->query("
            SELECT COALESCE(SUM(si.item_total - (si.quantity * COALESCE(p.cost, 0))), 0) 
            FROM sale_items si 
            JOIN sales s ON si.sale_id = s.sale_id
            LEFT JOIN Products p ON si.product_id = p.product_id
            WHERE s.status = 'Complete' AND s.sale_date = '$today'
        ");
        $todayProfit = $stmtProfit->fetchColumn();

        $stmtYest = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status = 'Complete' AND sale_date = '$yesterday'");
        $yesterdaySales = $stmtYest->fetchColumn();

        $stmtDrawerCalc = $pdo->query("
            SELECT 
                (SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status='Complete' AND payment_method='Cash') +
                (SELECT COALESCE(SUM(amount), 0) FROM cash_drawer_transactions WHERE type='Cash In') -
                (SELECT COALESCE(SUM(amount), 0) FROM cash_drawer_transactions WHERE type IN ('Cash Out', 'Refund')) -
                (SELECT COALESCE(SUM(amount), 0) FROM expenses) 
            as balance
        ");
        $drawerBalance = $stmtDrawerCalc->fetchColumn();

        $stmtAllTime = $pdo->query("
            SELECT 
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(SUM(CASE WHEN payment_method = 'Cash' THEN total_amount ELSE 0 END), 0) as cash_total,
                COALESCE(SUM(CASE WHEN payment_method = 'Card' THEN total_amount ELSE 0 END), 0) as card_total,
                COUNT(sale_id) as total_bills,
                COUNT(CASE WHEN payment_method = 'Cash' THEN 1 END) as total_cash_bills,
                COUNT(CASE WHEN payment_method = 'Card' THEN 1 END) as total_card_bills
            FROM sales 
            WHERE status = 'Complete'
        ");
        $allTimeData = $stmtAllTime->fetch();

        $stmtAllExpenses = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses");
        $allTimeExpenses = $stmtAllExpenses->fetchColumn();

        $stmtInv = $pdo->query("
            SELECT 
                COUNT(*) as product_count,
                COALESCE(SUM(quantity * price), 0) as stock_valuation,
                SUM(CASE WHEN quantity <= 5 THEN 1 ELSE 0 END) as low_stock_count
            FROM Products 
            WHERE status = 'Active'
        ");
        $invData = $stmtInv->fetch();

        $stmtTopProducts = $pdo->query("
            SELECT 
                COALESCE(si.product_name, p.name) as name,
                SUM(si.quantity) as total_qty
            FROM sale_items si
            LEFT JOIN Products p ON si.product_id = p.product_id
            GROUP BY si.product_id
            ORDER BY total_qty DESC
            LIMIT 5
        ");
        $topProducts = $stmtTopProducts->fetchAll();

        echo json_encode([
            "success" => true,
            "today" => [
                "sales" => $todayData['total_sales'],
                "cash_sales" => $todayData['cash_sales'],
                "card_sales" => $todayData['card_sales'],
                "expenses" => $todayExpense,
                "profit" => $todayProfit,
                "cash_in" => $todayCashIn,
                "bills" => $todayData['bill_count'],
                "bills_cash" => $todayData['cash_bills'],
                "bills_card" => $todayData['card_bills']
            ],
            "yesterday" => [
                "sales" => $yesterdaySales
            ],
            "drawer" => [
                "balance" => $drawerBalance
            ],
            "all_time" => [
                "sales" => $allTimeData['total_sales'],
                "cash" => $allTimeData['cash_total'],
                "card" => $allTimeData['card_total'],
                "expenses" => $allTimeExpenses,
                "bills" => $allTimeData['total_bills'],
                "bills_cash" => $allTimeData['total_cash_bills'],
                "bills_card" => $allTimeData['total_card_bills']
            ],
            "inventory" => [
                "valuation" => $invData['stock_valuation'],
                "count" => $invData['product_count'],
                "low_stock" => $invData['low_stock_count']
            ],
            "top_products" => $topProducts
        ]);

    } catch (PDOException $e) {
        respondWithError("Database Error: " . $e->getMessage());
    }
}

// --- 3. GET DRAWER TRANSACTIONS ---
elseif ($method === 'GET' && $action === 'get_drawer_transactions') {
    try {
        $stmt = $pdo->query("
            SELECT t.*, u.username 
            FROM cash_drawer_transactions t 
            LEFT JOIN users u ON t.user_id = u.user_id 
            WHERE t.transaction_date = CURDATE() 
            ORDER BY t.transaction_time DESC
        ");
        echo json_encode(["success" => true, "transactions" => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        respondWithError("Error: " . $e->getMessage());
    }
}

// --- 4. ADD CASH IN ---
elseif ($method === 'POST' && $action === 'add_cash_in') {
    $amount = $input_data['amount'] ?? null;
    $description = $input_data['description'] ?? null;
    $user_id = $input_data['user_id'] ?? null;

    if (!is_numeric($amount) || $amount <= 0 || empty($user_id)) {
        respondWithError("Invalid amount or user ID.", 400);
    }
    
    $user_id = getValidUserId($pdo, $user_id); // FIX: Validate User ID

    try {
        $stmt = $pdo->prepare("INSERT INTO cash_drawer_transactions (user_id, amount, type, description, transaction_date, transaction_time) VALUES (?, ?, 'Cash In', ?, CURDATE(), CURTIME())");
        $stmt->execute([$user_id, $amount, $description]);
        echo json_encode(["success" => true, "message" => "Cash In recorded."]);
    } catch (PDOException $e) {
        respondWithError("Error: " . $e->getMessage());
    }
}

// --- 5. ADD CASH OUT (NEW: Fixes Double Count Issue) ---
elseif ($method === 'POST' && $action === 'add_cash_out') {
    $amount = $input_data['amount'] ?? null;
    $description = $input_data['description'] ?? null;
    $user_id = $input_data['user_id'] ?? null;

    if (!is_numeric($amount) || $amount <= 0 || empty($user_id)) {
        respondWithError("Invalid amount or user ID.", 400);
    }

    $user_id = getValidUserId($pdo, $user_id); // FIX: Validate User ID

    try {
        $stmt = $pdo->prepare("INSERT INTO cash_drawer_transactions (user_id, amount, type, description, transaction_date, transaction_time) VALUES (?, ?, 'Cash Out', ?, CURDATE(), CURTIME())");
        $stmt->execute([$user_id, $amount, $description]);
        echo json_encode(["success" => true, "message" => "Cash Out recorded."]);
    } catch (PDOException $e) {
        respondWithError("Error: " . $e->getMessage());
    }
}

// --- 6. ADD EXPENSE ---
elseif ($method === 'POST' && $action === 'add_expense') {
    $amount = $input_data['amount'] ?? null;
    $description = $input_data['description'] ?? null;
    $user_id = $input_data['user_id'] ?? null;
    
    if (!is_numeric($amount) || $amount <= 0 || empty($user_id)) {
        respondWithError("Invalid expense data.", 400);
    }

    $user_id = getValidUserId($pdo, $user_id); // FIX: Validate User ID

    try {
        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, amount, description, expense_date, expense_time) VALUES (?, ?, ?, CURDATE(), CURTIME())");
        $stmt->execute([$user_id, $amount, $description]);
        echo json_encode(["success" => true, "message" => "Expense recorded successfully."]);
    } catch (PDOException $e) {
        respondWithError("Error: " . $e->getMessage());
    }
}

// --- 7. SAVE END DAY REPORT ---
elseif ($method === 'POST' && $action === 'save_end_day_report') {
    $report = $input_data['report'] ?? [];
    if (empty($report)) respondWithError("No report data.", 400);

    try {
        $dbDate = date('Y-m-d');
        $dbTime = date('H:i:s');

        $stmt = $pdo->prepare("INSERT INTO daily_reports (report_date, report_time, cashier_name, total_sales, cash_in_drawer, difference, report_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $dbDate,
            $dbTime,
            $report['cashier'],
            $report['todaySales'],
            $report['countedCash'],
            $report['cashDifference'],
            json_encode($report)
        ]);
        echo json_encode(["success" => true, "message" => "Report saved to history."]);
    } catch (PDOException $e) {
        respondWithError("Database Error: " . $e->getMessage());
    }
}

// --- 8. GET REPORT HISTORY ---
elseif ($method === 'GET' && $action === 'get_report_history') {
    try {
        $stmt = $pdo->query("SELECT id, report_date, report_time, cashier_name, total_sales, cash_in_drawer, difference, report_json FROM daily_reports ORDER BY id DESC LIMIT 50");
        echo json_encode(["success" => true, "reports" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        respondWithError("Error: " . $e->getMessage());
    }
}

else {
    respondWithError("Invalid Action: " . htmlspecialchars($action), 400);
}
?>