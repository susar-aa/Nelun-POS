<?php
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); 
header('Access-Control-Allow-Headers: Content-Type, Authorization'); 

// products.php
header('Content-Type: application/json');
require_once 'db_connection.php';

$action = $_GET['action'] ?? null;
$search_query = $_GET['search'] ?? '';

// --- 1. GET ALL PRODUCTS (Strictly Active Only - For POS Local Cache) ---
if ($action === 'getAllProducts') {
    try {
        $stmt = $pdo->query("SELECT product_id, name, item_code, product_code, quantity, price, cost FROM Products WHERE status = 'Active' ORDER BY name ASC");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["success" => true, "products" => $products]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error fetching products: " . $e->getMessage()]);
    }
} 
// --- 2. GET SINGLE PRODUCT (Strictly Active Only - For POS Validation) ---
elseif ($action === 'getProductDetails') {
    $product_id = $_GET['product_id'] ?? null;
    try {
        $stmt = $pdo->prepare("SELECT product_id, name, item_code, product_code, quantity, price, cost FROM Products WHERE product_id = ? AND status = 'Active'");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) echo json_encode(["success" => true, "product" => $product]);
        else echo json_encode(["success" => false, "message" => "Product not found or Inactive"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}
// --- 3. GET PAGINATED PRODUCTS (UPGRADED: Supports Filters for Admin Panel) ---
elseif ($action === 'getProductsPaginated') {
    try {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        
        // NEW FILTERS
        $statusFilter = $_GET['status'] ?? 'Active'; // Default to Active if not specified
        $stockFilter = $_GET['stock'] ?? 'All';      // 'Low', 'Out', 'All'

        $offset = ($page - 1) * $limit;

        // Base SQL
        $sql = "SELECT product_id, name, item_code, product_code, quantity, price, cost, status FROM Products WHERE 1=1";
        $countSql = "SELECT COUNT(*) as total FROM Products WHERE 1=1";
        $params = [];

        // A. Apply Search
        if (!empty($search)) {
            $term = "%$search%";
            $clause = " AND (name LIKE ? OR product_code LIKE ? OR item_code LIKE ?)";
            $sql .= $clause;
            $countSql .= $clause;
            $params[] = $term; $params[] = $term; $params[] = $term;
        }

        // B. Apply Status Filter
        if ($statusFilter !== 'All') {
            $sql .= " AND status = ?";
            $countSql .= " AND status = ?";
            $params[] = $statusFilter;
        }

        // C. Apply Stock Filter
        if ($stockFilter === 'Low') {
            $sql .= " AND quantity <= 5 AND quantity > 0";
            $countSql .= " AND quantity <= 5 AND quantity > 0";
        } elseif ($stockFilter === 'Out') {
            $sql .= " AND quantity = 0";
            $countSql .= " AND quantity = 0";
        }

        // D. Sorting & Limits
        $sql .= " ORDER BY product_id DESC LIMIT $limit OFFSET $offset";

        // Execute Count
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalRecords = $stmtCount->fetchColumn();

        // Execute Data Fetch
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true, 
            "products" => $products,
            "total_records" => $totalRecords,
            "total_pages" => ceil($totalRecords / $limit),
            "current_page" => $page
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}
// --- 4. SEARCH PRODUCTS (Strictly Active Only - For POS Typing) ---
elseif ($action === 'searchProducts') {
    if (strlen($search_query) < 2) {
        echo json_encode(["success" => true, "products" => []]);
        exit();
    }
    try {
        $stmt = $pdo->prepare("SELECT product_id, name, product_code, price, quantity, item_code FROM Products WHERE status = 'Active' AND (name LIKE ? OR product_code LIKE ? OR item_code LIKE ?) LIMIT 20");
        $searchTerm = '%' . $search_query . '%';
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["success" => true, "products" => $products]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error searching products: " . $e->getMessage()]);
    }
} 
// --- CRUD Operations (Save/Update/Delete) ---
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveGeneralProduct') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? null;
    $price = $input['price'] ?? null;
    $quantity = $input['quantity'] ?? 0;

    if (!$name || !is_numeric($price) || $price <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid product data."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO Products (name, product_code, quantity, price, status) VALUES (?, ?, ?, ?, 'Active')");
        $product_code = 'GEN-' . uniqid();
        $stmt->execute([$name, $product_code, $quantity, $price]);
        $product_id = $pdo->lastInsertId();
        echo json_encode(["success" => true, "message" => "General product saved.", "product_id" => $product_id]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'addProduct') {
    $input = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("INSERT INTO Products (name, item_code, product_code, price, cost, quantity, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $code = !empty($input['product_code']) ? $input['product_code'] : str_pad(mt_rand(1,99999999), 8, '0', STR_PAD_LEFT);
        $status = $input['status'] ?? 'Active';

        $stmt->execute([
            $input['name'],
            $input['item_code'],
            $code,
            $input['price'],
            $input['cost'],
            $input['quantity'],
            $status
        ]);
        echo json_encode(["success" => true, "message" => "Product added."]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateProduct') {
    $input = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("UPDATE Products SET name=?, item_code=?, product_code=?, price=?, cost=?, quantity=?, status=? WHERE product_id=?");
        $stmt->execute([
            $input['name'],
            $input['item_code'],
            $input['product_code'],
            $input['price'],
            $input['cost'],
            $input['quantity'],
            $input['status'],
            $input['product_id']
        ]);
        echo json_encode(["success" => true, "message" => "Product updated."]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deleteProduct') {
    $input = json_decode(file_get_contents('php://input'), true);
    try {
        // Soft delete
        $stmt = $pdo->prepare("UPDATE Products SET status = 'Inactive' WHERE product_id = ?");
        $stmt->execute([$input['product_id']]);
        echo json_encode(["success" => true]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}
?>