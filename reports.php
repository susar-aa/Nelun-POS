<?php
// reports.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once 'db_connection.php';

$action = $_GET['action'] ?? null;

// Helper function to safely get date for SQL queries
function getDateFromRange($range) {
    switch ($range) {
        case 'daily':
            return date('Y-m-d', strtotime('-7 days'));
        case 'monthly':
            return date('Y-m-d', strtotime('-12 months'));
        case 'yearly':
            return date('Y-m-d', strtotime('-3 years'));
        default:
            return null;
    }
}

// --- Report Actions ---

if ($action === 'getAnalytics') {
    // --- NEW: Detailed Analytics Endpoint ---
    $startDate = $_GET['start'] ?? date('Y-m-01'); // Default to 1st of current month
    $endDate = $_GET['end'] ?? date('Y-m-d');

    try {
        // 1. Sales & Profit Over Time (Grouped by Date)
        $sqlTrend = "
            SELECT 
                DATE(s.sale_date) as date,
                COUNT(DISTINCT s.sale_id) as bill_count,
                SUM(si.item_total) as revenue,
                SUM(si.item_total - (si.quantity * COALESCE(p.cost, 0))) as profit
            FROM sales s
            JOIN sale_items si ON s.sale_id = si.sale_id
            LEFT JOIN Products p ON si.product_id = p.product_id
            WHERE s.status = 'Complete' AND s.sale_date BETWEEN ? AND ?
            GROUP BY date
            ORDER BY date ASC
        ";
        $stmtTrend = $pdo->prepare($sqlTrend);
        $stmtTrend->execute([$startDate, $endDate]);
        $trendData = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);

        // 2. Payment Method Breakdown
        $sqlPayment = "
            SELECT payment_method, SUM(total_amount) as total 
            FROM sales 
            WHERE status = 'Complete' AND sale_date BETWEEN ? AND ?
            GROUP BY payment_method
        ";
        $stmtPayment = $pdo->prepare($sqlPayment);
        $stmtPayment->execute([$startDate, $endDate]);
        $paymentData = $stmtPayment->fetchAll(PDO::FETCH_ASSOC);

        // 3. Totals
        $totalRevenue = 0;
        $totalProfit = 0;
        foreach ($trendData as $day) {
            $totalRevenue += floatval($day['revenue']);
            $totalProfit += floatval($day['profit']);
        }

        echo json_encode([
            "success" => true,
            "trend" => $trendData,
            "payments" => $paymentData,
            "totals" => [
                "revenue" => $totalRevenue,
                "profit" => $totalProfit,
                "margin" => $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0
            ]
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error fetching analytics: " . $e->getMessage()]);
    }

} elseif ($action === 'getSales') {
    // Legacy support for Overview widgets if needed, or redirect to Analytics logic
    $range = $_GET['range'] ?? 'daily';
    $startDate = getDateFromRange($range);

    if (!$startDate) {
        echo json_encode(["success" => false, "message" => "Invalid time range specified."]);
        exit();
    }

    try {
        $labels = [];
        $data = [];
        $totalSales = 0;

        if ($range === 'daily') {
            $stmt = $pdo->prepare("SELECT DATE(sale_date) as label, SUM(total_amount) as total FROM sales WHERE sale_date >= ? GROUP BY label ORDER BY label ASC");
            $stmt->execute([$startDate]);
        } elseif ($range === 'monthly') {
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(sale_date, '%Y-%m') as label, SUM(total_amount) as total FROM sales WHERE sale_date >= ? GROUP BY label ORDER BY label ASC");
            $stmt->execute([$startDate]);
        } else {
             $stmt = $pdo->prepare("SELECT DATE_FORMAT(sale_date, '%Y') as label, SUM(total_amount) as total FROM sales WHERE sale_date >= ? GROUP BY label ORDER BY label ASC");
             $stmt->execute([$startDate]);
        }

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as $row) {
            $labels[] = $row['label'];
            $data[] = floatval($row['total']);
            $totalSales += floatval($row['total']);
        }

        echo json_encode([
            "success" => true,
            "labels" => $labels,
            "data" => $data,
            "totalSales" => $totalSales
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }

} elseif ($action === 'getTopProducts') {
    try {
        $stmt = $pdo->query("SELECT product_name as name, SUM(quantity) as sold FROM sale_items GROUP BY product_name ORDER BY sold DESC LIMIT 5");
        $topProducts = $stmt->fetchAll();
        echo json_encode(["success" => true, "topProducts" => $topProducts]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
} elseif ($action === 'getLowStock') {
    try {
        $threshold = 50; 
        $stmt = $pdo->prepare("SELECT name, product_code, quantity FROM Products WHERE quantity <= ? ORDER BY quantity ASC");
        $stmt->execute([$threshold]);
        $lowStock = $stmt->fetchAll();
        echo json_encode(["success" => true, "lowStock" => $lowStock]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
} elseif ($action === 'getExpenses') {
    try {
        $stmt = $pdo->query("SELECT description, amount, transaction_date AS date, user_id FROM cash_drawer_transactions WHERE type = 'expense' ORDER BY transaction_date DESC");
        $expenses = $stmt->fetchAll();
        echo json_encode(["success" => true, "expenses" => $expenses]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
} elseif ($action === 'getProductSales') {
    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d');
    $search = $_GET['search'] ?? '';

    try {
        $sql = "
            SELECT 
                si.product_id,
                COALESCE(si.product_name, p.name, 'Unknown Item') as product_name,
                COALESCE(p.product_code, 'N/A') as product_code,
                COALESCE(p.cost, 0) as unit_cost,
                SUM(si.quantity) as total_qty,
                SUM(si.item_total) as total_revenue
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.sale_id
            LEFT JOIN Products p ON si.product_id = p.product_id
            WHERE s.status = 'Complete'
            AND s.sale_date BETWEEN ? AND ?
        ";

        $params = [$start, $end];

        if (!empty($search)) {
            $sql .= " AND (si.product_name LIKE ? OR p.product_code LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " GROUP BY si.product_id, product_name ORDER BY total_revenue DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "data" => $data]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}
?>