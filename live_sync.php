<?php
// live_sync.php
// Handles syncing cart data between POS Terminal and Live Monitor
// Optimized for separate user sessions

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once 'db_connection.php';

$action = $_GET['action'] ?? null;

// 1. UPDATE CART (Called by POS Panel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_cart') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $userId = $input['user_id'] ?? 0; // CRITICAL: This ID separates the sessions
    $username = $input['username'] ?? 'Unknown';
    $cartData = json_encode($input['cart'] ?? []);
    
    $subtotal = $input['subtotal'] ?? 0;
    $discount = $input['discount'] ?? 0;
    $grandTotal = $input['grand_total'] ?? 0;

    if ($userId) {
        // Use UPSERT logic (Insert or Update if exists) to keep table clean
        // Note: Using REPLACE INTO or ON DUPLICATE KEY UPDATE
        
        $sql = "INSERT INTO active_carts (user_id, username, cart_data, subtotal, discount, grand_total, last_updated) 
                VALUES (?, ?, ?, ?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                username = VALUES(username), 
                cart_data = VALUES(cart_data), 
                subtotal = VALUES(subtotal), 
                discount = VALUES(discount), 
                grand_total = VALUES(grand_total), 
                last_updated = NOW()";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $username, $cartData, $subtotal, $discount, $grandTotal]);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No User ID provided']);
    }
    exit();
}

// 2. GET ACTIVE USERS (Called by Monitor to populate dropdown)
if ($action === 'get_active_users') {
    // Only fetch users active in the last 2 hours
    $stmt = $pdo->query("SELECT user_id, username FROM active_carts WHERE last_updated > (NOW() - INTERVAL 2 HOUR) ORDER BY username ASC");
    echo json_encode(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit();
}

// 3. GET LIVE CART (Called by Monitor to sync specific view)
if ($action === 'get_live_cart') {
    $userId = $_GET['user_id'] ?? 0;
    
    if($userId) {
        $stmt = $pdo->prepare("SELECT cart_data, subtotal, discount, grand_total, last_updated FROM active_carts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            echo json_encode([
                'success' => true, 
                'cart' => json_decode($result['cart_data']), 
                'subtotal' => $result['subtotal'],
                'discount' => $result['discount'],
                'grand_total' => $result['grand_total'],
                'last_updated' => $result['last_updated']
            ]);
        } else {
            // Return empty if no active cart found for this user
            echo json_encode([
                'success' => true, 
                'cart' => [], 
                'subtotal' => 0, 
                'discount' => 0, 
                'grand_total' => 0
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
    }
    exit();
}
?>