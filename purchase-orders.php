<?php
// purchase-orders.php
// Purchase Order System for Nelun POS
// Handles PO creation, searching, item updates, and transitions.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once 'db_connection.php';

$is_api = (isset($_GET['action']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false));

if ($is_api) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? null;
    $method = $_SERVER['REQUEST_METHOD'];

    $input = [];
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
    }

    // 1. GET ALL PURCHASE ORDERS (WITH HISTORY FILTERS)
    if ($action === 'getPurchaseOrders') {
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-d');
        $status = $_GET['status'] ?? 'All';
        $supplier = $_GET['supplier_id'] ?? 'All';
        $search = $_GET['search'] ?? '';

        try {
            $sql = "
                SELECT po.*, s.name as supplier_name, u.username as creator_name
                FROM purchase_orders po
                JOIN suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN users u ON po.created_by = u.user_id
                WHERE po.order_date BETWEEN ? AND ?
            ";
            $params = [$start, $end];

            if ($status !== 'All') {
                $sql .= " AND po.status = ?";
                $params[] = $status;
            }

            if ($supplier !== 'All') {
                $sql .= " AND po.supplier_id = ?";
                $params[] = $supplier;
            }

            if (!empty($search)) {
                $sql .= " AND (po.po_number LIKE ? OR s.name LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $sql .= " ORDER BY po.po_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(["success" => true, "purchase_orders" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 2. GET SINGLE PO DETAILS WITH ITEMS
    if ($action === 'getPODetails') {
        $po_id = $_GET['po_id'] ?? null;
        if (!$po_id) {
            echo json_encode(["success" => false, "message" => "PO ID is required."]);
            exit();
        }

        try {
            // Header
            $stmtHeader = $pdo->prepare("
                SELECT po.*, s.name as supplier_name, s.contact_person, s.phone, s.address, u.username as creator_name
                FROM purchase_orders po
                JOIN suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN users u ON po.created_by = u.user_id
                WHERE po.po_id = ?
            ");
            $stmtHeader->execute([$po_id]);
            $po = $stmtHeader->fetch(PDO::FETCH_ASSOC);

            if (!$po) {
                echo json_encode(["success" => false, "message" => "Purchase order not found."]);
                exit();
            }

            // Items
            $stmtItems = $pdo->prepare("
                SELECT pi.*, p.name as product_name, p.product_code, p.item_code
                FROM po_items pi
                JOIN Products p ON pi.product_id = p.product_id
                WHERE pi.po_id = ?
            ");
            $stmtItems->execute([$po_id]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["success" => true, "purchase_order" => $po, "items" => $items]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 3. SAVE NEW OR UPDATE EXISTING PURCHASE ORDER
    if ($action === 'savePurchaseOrder') {
        $po_id = $input['po_id'] ?? null;
        $supplier_id = $input['supplier_id'] ?? null;
        $order_date = $input['order_date'] ?? date('Y-m-d');
        $expected_date = $input['expected_date'] ?? null;
        $status = $input['status'] ?? 'Draft';
        $items = $input['items'] ?? [];
        $user_id = $input['user_id'] ?? 1;

        if (!$supplier_id || empty($items)) {
            echo json_encode(["success" => false, "message" => "Supplier and items are required."]);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Calculate total amount
            $total_amount = 0.00;
            foreach ($items as $item) {
                $total_amount += ((int)$item['quantity'] * (float)$item['cost_price']);
            }

            if ($po_id) {
                // Update PO Header
                $stmtHeader = $pdo->prepare("
                    UPDATE purchase_orders 
                    SET supplier_id = ?, order_date = ?, expected_date = ?, total_amount = ?, status = ?
                    WHERE po_id = ?
                ");
                $stmtHeader->execute([$supplier_id, $order_date, $expected_date, $total_amount, $status, $po_id]);

                // Delete old items
                $pdo->prepare("DELETE FROM po_items WHERE po_id = ?")->execute([$po_id]);
            } else {
                // Generate PO Number (PO-YYYYMMDD-XXXX)
                $date_slug = date('Ymd');
                $stmtCount = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE order_date = CURRENT_DATE()");
                $today_count = (int)$stmtCount->fetchColumn() + 1;
                $po_number = "PO-" . $date_slug . "-" . str_pad($today_count, 4, '0', STR_PAD_LEFT);

                // Insert PO Header
                $stmtHeader = $pdo->prepare("
                    INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_date, total_amount, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtHeader->execute([$po_number, $supplier_id, $order_date, $expected_date, $total_amount, $status, $user_id]);
                $po_id = $pdo->lastInsertId();
            }

            // Insert PO Items
            $stmtItem = $pdo->prepare("
                INSERT INTO po_items (po_id, product_id, quantity, cost_price)
                VALUES (?, ?, ?, ?)
            ");
            
            foreach ($items as $item) {
                $stmtItem->execute([$po_id, $item['product_id'], $item['quantity'], $item['cost_price']]);
            }

            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Purchase Order saved successfully.", "po_id" => $po_id]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 5. ANALYZE SALES FOR PO GENERATION
    if ($action === 'analyzeSalesForPO') {
        $supplier_id = $input['supplier_id'] ?? null;
        $start_date = $input['start_date'] ?? null;
        $end_date = $input['end_date'] ?? null;
        $buffer = (int)($input['buffer'] ?? 0);

        if (!$supplier_id || !$start_date || !$end_date) {
            echo json_encode(["success" => false, "message" => "Missing required parameters."]);
            exit();
        }

        try {
            $sql = "
                SELECT p.product_id, p.name as product_name, p.product_code, p.cost as cost_price, 
                       SUM(si.quantity) as sold_qty
                FROM sales_items si
                JOIN sales s ON si.sale_id = s.sale_id
                JOIN Products p ON si.product_id = p.product_id
                WHERE p.supplier_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
                GROUP BY p.product_id
                HAVING sold_qty > 0
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplier_id, $start_date, $end_date]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as &$item) {
                $item['quantity'] = (int)$item['sold_qty'] + $buffer;
            }

            echo json_encode(["success" => true, "items" => $results]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 4. CANCEL OR DELETE PO
    if ($action === 'updatePOStatus') {
        $po_id = $input['po_id'] ?? null;
        $status = $input['status'] ?? 'Cancelled';

        if (!$po_id) {
            echo json_encode(["success" => false, "message" => "PO ID is required."]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE po_id = ?");
            $stmt->execute([$status, $po_id]);
            echo json_encode(["success" => true, "message" => "Purchase order marked as $status."]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order Registry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; padding: 20px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .table th { font-weight: 600; color: #6c757d; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        
        .status-badge { font-size: 0.75rem; padding: 5px 10px; border-radius: 20px; font-weight: 600; }
        .badge-Draft { background-color: #e2e3e5; color: #383d41; }
        .badge-Sent { background-color: #cce5ff; color: #004085; }
        .badge-Completed { background-color: #d4edda; color: #155724; }
        .badge-Cancelled { background-color: #f8d7da; color: #721c24; }

        .search-results-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid rgba(0,0,0,0.15);
            border-top: none;
            border-radius: 0 0 12px 12px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1050;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        .search-result-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: background 0.15s;
        }
        .search-result-item:hover { background-color: #F2F2F7; }
    </style>
</head>
<body>

    <div id="alertContainer"></div>

    <div class="container-fluid">
        
        <!-- Toggle Screens -->
        <div id="historyView">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="fw-bold mb-0">Purchase Orders</h2>
                    <p class="text-muted small mb-0">Draft, issue, and manage procurement requests</p>
                </div>
                <button class="btn btn-primary shadow-sm px-4 rounded-pill" onclick="openPOOptionsModal()">
                    <i class="bi bi-file-earmark-plus"></i> New PO
                </button>
            </div>

            <!-- Filters -->
            <div class="card p-3 mb-4 bg-white">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small text-muted fw-bold">From</label>
                        <input type="date" id="startDate" class="form-control form-control-sm" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted fw-bold">To</label>
                        <input type="date" id="endDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted fw-bold">Supplier</label>
                        <select id="filterSupplier" class="form-select form-select-sm">
                            <option value="All">All Suppliers</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted fw-bold">Status</label>
                        <select id="filterStatus" class="form-select form-select-sm">
                            <option value="All">All Statuses</option>
                            <option value="Draft">Draft</option>
                            <option value="Sent">Sent</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted fw-bold">Search PO # / Supplier</label>
                        <input type="text" id="searchBox" class="form-control form-control-sm" placeholder="e.g. PO-2026...">
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-dark btn-sm w-100" onclick="fetchPOs()">Filter</button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="card border-0 overflow-hidden bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">PO Number</th>
                                <th>Order Date</th>
                                <th>Supplier</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="poTableBody">
                            <tr><td colspan="6" class="text-center py-5 text-muted">Loading purchase orders...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create/Edit PO Screen -->
        <div id="createView" class="d-none">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="fw-bold mb-0" id="createTitle">Create Purchase Order</h2>
                    <p class="text-muted small mb-0">Draft a new request for supplier replenishment</p>
                </div>
                <div>
                    <button class="btn btn-outline-dark border px-4 rounded-pill me-2 d-none" id="btnDownloadPDF" onclick="downloadPOPDF()">
                        <i class="bi bi-file-pdf-fill text-danger"></i> Download PDF
                    </button>
                    <button class="btn btn-light border px-4 rounded-pill" onclick="showHistoryScreen()">
                        <i class="bi bi-arrow-left"></i> Back to History
                    </button>
                </div>
            </div>

            <div class="row g-4">
                <!-- PO Details Form -->
                <div class="col-lg-4">
                    <div class="card p-4 bg-white mb-4">
                        <h5 class="fw-bold mb-3 border-bottom pb-2">PO Information</h5>
                        <form id="poForm">
                            <input type="hidden" id="inputId">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Select Supplier</label>
                                <select id="inputSupplier" class="form-select" required>
                                    <option value="">-- Choose Supplier --</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Order Date</label>
                                <input type="date" id="inputOrderDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Expected Date</label>
                                <input type="date" id="inputExpectedDate" class="form-control">
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold">Status</label>
                                <select id="inputStatus" class="form-select">
                                    <option value="Draft">Draft</option>
                                    <option value="Sent">Sent</option>
                                </select>
                            </div>
                            
                            <div class="border-top pt-3 d-flex justify-content-between align-items-center">
                                <h5 class="fw-bold text-dark mb-0">Total Owed:</h5>
                                <h3 class="fw-bold text-primary mb-0">Rs. <span id="lblPOTotal">0.00</span></h3>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mt-4 py-2.5 rounded-pill fw-bold shadow-sm">Save Purchase Order</button>
                        </form>
                    </div>
                </div>

                <!-- PO Items Builder -->
                <div class="col-lg-8">
                    <div class="card p-4 bg-white h-100">
                        <h5 class="fw-bold mb-3 border-bottom pb-2">Order Line Items</h5>
                        
                        <!-- Product Search Autocomplete -->
                        <div class="position-relative mb-4">
                            <label class="form-label small fw-bold">Search & Add Product</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-plus-circle-fill text-primary"></i></span>
                                <input type="text" id="prodSearchBox" class="form-control" placeholder="Type name, barcode, or code to search..." onkeyup="searchProducts()">
                            </div>
                            <div class="search-results-dropdown d-none" id="searchResults">
                                <!-- Suggested items -->
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th style="width: 150px;">Cost Price (Rs.)</th>
                                        <th style="width: 120px;">Qty Requested</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="poItemTableBody">
                                    <tr><td colspan="5" class="text-center py-5 text-muted">No line items added yet. Search and select products above.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- PO Options Modal -->
    <div class="modal fade" id="poOptionsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold">Create Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <p class="text-muted mb-4">How would you like to build this purchase order?</p>
                    <div class="d-grid gap-3">
                        <button class="btn btn-outline-primary py-3 fw-bold rounded-3" onclick="poOptionsModal.hide(); showCreateScreen()">
                            <i class="bi bi-pencil-square fs-4 d-block mb-1"></i> Create Manually
                        </button>
                        <button class="btn btn-primary py-3 fw-bold rounded-3" onclick="openAnalyzeModal()">
                            <i class="bi bi-bar-chart-fill fs-4 d-block mb-1"></i> Generate by Analyzing Sales
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Analyze Sales Modal -->
    <div class="modal fade" id="analyzeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold">Analyze Sales Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="analyzeForm">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Select Supplier</label>
                            <select id="analyzeSupplier" class="form-select" required>
                                <option value="">-- Choose Supplier --</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label small fw-bold">Start Date</label>
                                <input type="date" id="analyzeStart" class="form-control" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label small fw-bold">End Date</label>
                                <input type="date" id="analyzeEnd" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Date Preset</label>
                            <select class="form-select form-select-sm" onchange="applyDatePreset(this.value)">
                                <option value="">Custom Range</option>
                                <option value="7">Last 7 Days</option>
                                <option value="30">Last Month (30 Days)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Buffer Stock to Add (Qty)</label>
                            <input type="number" id="analyzeBuffer" class="form-control" value="0" min="0">
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4" onclick="analyzeSales()">Analyze & Generate</button>
                </div>
            </div>
        </div>
    </div>

    <!-- PO Details Modal -->
    <div class="modal fade" id="poDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold" id="modalPONumber">PO-XXXXXX</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="modalPrintArea">
                    <div class="row mb-4">
                        <div class="col-6">
                            <small class="text-uppercase text-muted fw-bold">Vendor Details</small>
                            <h5 class="fw-bold text-dark mt-1 mb-0" id="modalSupName">Supplier Name</h5>
                            <small class="text-muted" id="modalSupContact">Sunil Perera | 0718425858</small><br>
                            <small class="text-muted" id="modalSupAddress">Office Address</small>
                        </div>
                        <div class="col-6 text-end">
                            <small class="text-uppercase text-muted fw-bold">Order Summaries</small>
                            <div class="mt-1">Ordered: <span class="fw-bold" id="modalOrderDate">2026-05-18</span></div>
                            <div>Expected: <span class="fw-bold" id="modalExpectedDate">2026-05-18</span></div>
                            <div>Status: <span class="badge status-badge mt-1" id="modalStatusBadge">Draft</span></div>
                        </div>
                    </div>

                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Item Code</th>
                                <th>Product Details</th>
                                <th class="text-end" style="width: 120px;">Unit Cost</th>
                                <th class="text-center" style="width: 100px;">Qty</th>
                                <th class="text-end" style="width: 150px;">Line Total</th>
                            </tr>
                        </thead>
                        <tbody id="modalItemTableBody">
                            <!-- Items -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Grand Total Owed:</th>
                                <th class="text-end text-primary" id="modalTotalAmount">Rs. 0.00</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                    <button class="btn btn-outline-danger rounded-pill px-4" id="btnCancelPO" onclick="cancelCurrentPO()">Cancel Order</button>
                    <button class="btn btn-success rounded-pill px-4 fw-bold" id="btnTransferGRN" onclick="transferPOToGRN()">
                        <i class="bi bi-box-seam-fill"></i> Receive & Convert to GRN
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const poDetailsModal = new bootstrap.Modal(document.getElementById('poDetailsModal'));
        const poOptionsModal = new bootstrap.Modal(document.getElementById('poOptionsModal'));
        const analyzeModal = new bootstrap.Modal(document.getElementById('analyzeModal'));
        
        let suppliers = [];
        let purchaseOrders = [];
        let selectedPOId = null;
        let selectedPOHeader = null;
        
        // PO Builder State
        let poItems = [];

        // Fetch Initial Configurations
        async function loadConfig() {
            try {
                // Fetch Suppliers
                const resSup = await fetch('suppliers.php?action=getSuppliers');
                const dataSup = await resSup.json();
                if(dataSup.success) {
                    suppliers = dataSup.suppliers;
                    
                    const filterDropdown = document.getElementById('filterSupplier');
                    const formDropdown = document.getElementById('inputSupplier');
                    
                    const analyzeDropdown = document.getElementById('analyzeSupplier');
                    suppliers.forEach(s => {
                        filterDropdown.add(new Option(s.name, s.supplier_id));
                        formDropdown.add(new Option(s.name, s.supplier_id));
                        analyzeDropdown.add(new Option(s.name, s.supplier_id));
                    });
                }
            } catch(e) { console.error('Error loading config data.'); }
        }

        async function fetchPOs() {
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            const status = document.getElementById('filterStatus').value;
            const supplier = document.getElementById('filterSupplier').value;
            const search = document.getElementById('searchBox').value;

            try {
                const res = await fetch(`purchase-orders.php?action=getPurchaseOrders&start=${start}&end=${end}&status=${status}&supplier_id=${supplier}&search=${encodeURIComponent(search)}`);
                const data = await res.json();
                if(data.success) {
                    purchaseOrders = data.purchase_orders;
                    renderPOTable(purchaseOrders);
                }
            } catch(e) { showAlert('Error connecting to backend API.', 'danger'); }
        }

        function renderPOTable(list) {
            const tbody = document.getElementById('poTableBody');
            tbody.innerHTML = '';
            
            if(list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">No purchase orders found.</td></tr>';
                return;
            }

            list.forEach(po => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="ps-4 fw-bold text-dark">${po.po_number}</td>
                    <td>${po.order_date}</td>
                    <td class="fw-semibold text-muted">${po.supplier_name}</td>
                    <td class="text-end fw-bold text-primary">Rs. ${parseFloat(po.total_amount).toFixed(2)}</td>
                    <td class="text-center"><span class="badge status-badge badge-${po.status}">${po.status}</span></td>
                    <td class="text-center">
                        <div class="btn-group">
                            <button class="btn btn-sm btn-link text-primary" onclick="viewDetails(${po.po_id})"><i class="bi bi-eye-fill"></i> View</button>
                            ${po.status === 'Draft' ? `<button class="btn btn-sm btn-link text-secondary" onclick="editPO(${po.po_id})"><i class="bi bi-pencil"></i> Edit</button>` : ''}
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        async function viewDetails(id) {
            selectedPOId = id;
            try {
                const res = await fetch(`purchase-orders.php?action=getPODetails&po_id=${id}`);
                const data = await res.json();
                if(data.success) {
                    const po = data.purchase_order;
                    selectedPOHeader = po;
                    
                    document.getElementById('modalPONumber').textContent = po.po_number;
                    document.getElementById('modalSupName').textContent = po.supplier_name;
                    document.getElementById('modalSupContact').textContent = `${po.contact_person} | ${po.phone}`;
                    document.getElementById('modalSupAddress').textContent = po.address || 'No address specified';
                    
                    document.getElementById('modalOrderDate').textContent = po.order_date;
                    document.getElementById('modalExpectedDate').textContent = po.expected_date || 'N/A';
                    
                    const badge = document.getElementById('modalStatusBadge');
                    badge.className = `badge status-badge badge-${po.status}`;
                    badge.textContent = po.status;

                    const tbody = document.getElementById('modalItemTableBody');
                    tbody.innerHTML = '';
                    
                    data.items.forEach(item => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><small class="text-muted font-monospace">${item.item_code || '-'}</small></td>
                            <td class="fw-bold">${item.product_name} <small class="text-muted font-monospace">${item.product_code}</small></td>
                            <td class="text-end">Rs. ${parseFloat(item.cost_price).toFixed(2)}</td>
                            <td class="text-center">${item.quantity}</td>
                            <td class="text-end fw-semibold">Rs. ${parseFloat(item.line_total).toFixed(2)}</td>
                        `;
                        tbody.appendChild(tr);
                    });

                    document.getElementById('modalTotalAmount').textContent = 'Rs. ' + parseFloat(po.total_amount).toFixed(2);
                    
                    // Button Visibility
                    const grnBtn = document.getElementById('btnTransferGRN');
                    const cancelBtn = document.getElementById('btnCancelPO');
                    
                    if(po.status === 'Completed' || po.status === 'Cancelled') {
                        grnBtn.classList.add('d-none');
                        cancelBtn.classList.add('d-none');
                    } else {
                        grnBtn.classList.remove('d-none');
                        cancelBtn.classList.remove('d-none');
                    }

                    poDetailsModal.show();
                }
            } catch(e) { showAlert('Error fetching PO details.', 'danger'); }
        }

        // PO Creation / Items Management
        function showCreateScreen() {
            document.getElementById('historyView').classList.add('d-none');
            document.getElementById('createView').classList.remove('d-none');
            
            // Reset state
            document.getElementById('poForm').reset();
            document.getElementById('inputId').value = '';
            document.getElementById('createTitle').textContent = "Create Purchase Order";
            document.getElementById('inputStatus').value = "Draft";
            document.getElementById('btnDownloadPDF').classList.add('d-none');
            poItems = [];
            recalculatePOTotal();
            renderPOItems();
        }

        function openPOOptionsModal() {
            poOptionsModal.show();
        }

        function openAnalyzeModal() {
            poOptionsModal.hide();
            document.getElementById('analyzeForm').reset();
            const lastWeek = new Date();
            lastWeek.setDate(lastWeek.getDate() - 7);
            document.getElementById('analyzeStart').value = lastWeek.toISOString().split('T')[0];
            analyzeModal.show();
        }

        function applyDatePreset(days) {
            if(!days) return;
            const end = new Date();
            const start = new Date();
            start.setDate(end.getDate() - parseInt(days));
            document.getElementById('analyzeEnd').value = end.toISOString().split('T')[0];
            document.getElementById('analyzeStart').value = start.toISOString().split('T')[0];
        }

        async function analyzeSales() {
            const supplier = document.getElementById('analyzeSupplier').value;
            const start = document.getElementById('analyzeStart').value;
            const end = document.getElementById('analyzeEnd').value;
            const buffer = document.getElementById('analyzeBuffer').value;

            if(!supplier || !start || !end) {
                showAlert('Please fill all required fields.', 'warning');
                return;
            }

            try {
                const res = await fetch('purchase-orders.php?action=analyzeSalesForPO', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ supplier_id: supplier, start_date: start, end_date: end, buffer: buffer })
                });
                const data = await res.json();
                
                if(data.success) {
                    analyzeModal.hide();
                    showCreateScreen();
                    document.getElementById('inputSupplier').value = supplier;
                    
                    poItems = [];
                    if(data.items.length === 0) {
                        showAlert('No sales data found for this supplier in the selected date range.', 'warning');
                    } else {
                        data.items.forEach(item => {
                            poItems.push({
                                product_id: item.product_id,
                                product_name: item.product_name,
                                product_code: item.product_code,
                                cost_price: parseFloat(item.cost_price),
                                quantity: item.quantity,
                                sold_qty: item.sold_qty
                            });
                        });
                        showAlert(`Generated PO based on ${data.items.length} products sold.`, 'success');
                    }
                    renderPOItems();
                    recalculatePOTotal();
                } else { showAlert(data.message, 'danger'); }
            } catch(e) { showAlert('Error analyzing sales data.', 'danger'); }
        }

        function showHistoryScreen() {
            document.getElementById('createView').classList.add('d-none');
            document.getElementById('historyView').classList.remove('d-none');
            fetchPOs();
        }

        async function editPO(id) {
            try {
                const res = await fetch(`purchase-orders.php?action=getPODetails&po_id=${id}`);
                const data = await res.json();
                if(data.success) {
                    showCreateScreen();
                    const po = data.purchase_order;
                    document.getElementById('inputId').value = po.po_id;
                    document.getElementById('inputSupplier').value = po.supplier_id;
                    document.getElementById('inputOrderDate').value = po.order_date;
                    document.getElementById('inputExpectedDate').value = po.expected_date || '';
                    document.getElementById('inputStatus').value = po.status;
                    document.getElementById('createTitle').textContent = `Edit Purchase Order (${po.po_number})`;
                    document.getElementById('btnDownloadPDF').classList.remove('d-none');

                    poItems = data.items.map(item => ({
                        product_id: item.product_id,
                        product_name: item.product_name,
                        product_code: item.product_code,
                        cost_price: parseFloat(item.cost_price),
                        quantity: parseInt(item.quantity)
                    }));
                    renderPOItems();
                    recalculatePOTotal();
                }
            } catch(e) { showAlert('Error loading PO details for edit.', 'danger'); }
        }

        // Product Auto-complete Search
        async function searchProducts() {
            const query = document.getElementById('prodSearchBox').value;
            const resultsDiv = document.getElementById('searchResults');
            
            if(query.length < 2) {
                resultsDiv.classList.add('d-none');
                return;
            }

            try {
                const supplier_id = document.getElementById('inputSupplier').value;
                const res = await fetch(`products.php?action=searchProducts&search=${encodeURIComponent(query)}&supplier_id=${supplier_id}`);
                const data = await res.json();
                
                if(data.success && data.products.length > 0) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.classList.remove('d-none');
                    
                    data.products.forEach(p => {
                        const item = document.createElement('div');
                        item.className = 'search-result-item';
                        item.onclick = () => addProductToPO(p);
                        item.innerHTML = `
                            <div class="fw-bold">${p.name}</div>
                            <small class="text-muted">Barcode: ${p.product_code} | Price: Rs. ${parseFloat(p.price).toFixed(2)}</small>
                        `;
                        resultsDiv.appendChild(item);
                    });
                } else {
                    resultsDiv.classList.add('d-none');
                }
            } catch(e) { console.error('Error fetching search results.'); }
        }

        function addProductToPO(product) {
            document.getElementById('prodSearchBox').value = '';
            document.getElementById('searchResults').classList.add('d-none');

            // Check if product already added
            const exists = poItems.find(item => item.product_id == product.product_id);
            if(exists) {
                exists.quantity++;
            } else {
                poItems.push({
                    product_id: product.product_id,
                    product_name: product.name,
                    product_code: product.product_code,
                    cost_price: parseFloat(product.cost) || 0.00,
                    quantity: 1
                });
            }

            renderPOItems();
            recalculatePOTotal();
        }

        function renderPOItems() {
            const tbody = document.getElementById('poItemTableBody');
            tbody.innerHTML = '';

            if(poItems.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted">No line items added yet. Search and select products above.</td></tr>';
                return;
            }

            poItems.forEach((item, index) => {
                const subtotal = item.cost_price * item.quantity;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="fw-semibold text-dark">
                        ${item.product_name}<br>
                        <small class="text-muted">${item.product_code}</small>
                        ${item.sold_qty ? `<span class="badge bg-info ms-2">Analyzed Sold: ${item.sold_qty}</span>` : ''}
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end" value="${item.cost_price.toFixed(2)}" step="0.01" min="0.00" onchange="updateLineCost(${index}, this.value)">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-center" value="${item.quantity}" min="1" onchange="updateLineQty(${index}, this.value)">
                    </td>
                    <td class="text-end fw-bold text-primary">Rs. ${subtotal.toFixed(2)}</td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-danger border-0" onclick="removePOItem(${index})"><i class="bi bi-trash-fill"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function updateLineCost(index, value) {
            poItems[index].cost_price = parseFloat(value) || 0.00;
            renderPOItems();
            recalculatePOTotal();
        }

        function updateLineQty(index, value) {
            poItems[index].quantity = parseInt(value) || 1;
            renderPOItems();
            recalculatePOTotal();
        }

        function removePOItem(index) {
            poItems.splice(index, 1);
            renderPOItems();
            recalculatePOTotal();
        }

        function recalculatePOTotal() {
            let total = 0.00;
            poItems.forEach(item => {
                total += (item.cost_price * item.quantity);
            });
            document.getElementById('lblPOTotal').textContent = total.toLocaleString('en-LK', { minimumFractionDigits: 2 });
        }

        document.getElementById('poForm').onsubmit = async (e) => {
            e.preventDefault();
            
            if(poItems.length === 0) {
                showAlert('Please add at least one product item to the order.', 'warning');
                return;
            }

            const payload = {
                po_id: document.getElementById('inputId').value,
                supplier_id: document.getElementById('inputSupplier').value,
                order_date: document.getElementById('inputOrderDate').value,
                expected_date: document.getElementById('inputExpectedDate').value,
                status: document.getElementById('inputStatus').value,
                items: poItems,
                user_id: localStorage.getItem('user_id') || 1
            };

            try {
                const res = await fetch('purchase-orders.php?action=savePurchaseOrder', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(data.success) {
                    showAlert(data.message, 'success');
                    showHistoryScreen();
                } else { showAlert(data.message, 'danger'); }
            } catch(e) { showAlert('Error saving purchase order details.', 'danger'); }
        };

        async function cancelCurrentPO() {
            if(!confirm('Permanently cancel this purchase order?')) return;
            try {
                const res = await fetch('purchase-orders.php?action=updatePOStatus', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ po_id: selectedPOId, status: 'Cancelled' })
                });
                const data = await res.json();
                if(data.success) {
                    showAlert(data.message, 'warning');
                    poDetailsModal.hide();
                    fetchPOs();
                } else { showAlert(data.message, 'danger'); }
            } catch(e) { showAlert('Error cancelling purchase order.', 'danger'); }
        }

        // Convert / Transfer PO to GRN
        function transferPOToGRN() {
            poDetailsModal.hide();
            // Redirect inside the frame to grn.php with po_id parameter
            window.location.href = `grn.php?po_id=${selectedPOId}`;
        }

        function showAlert(msg, type) {
            const div = document.createElement('div');
            div.className = `alert alert-${type} alert-dismissible fade show shadow fixed-top m-3`;
            div.style.zIndex = 2000;
            div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.getElementById('alertContainer').appendChild(div);
            setTimeout(() => div.remove(), 3000);
        }

        window.downloadPOPDF = function() {
            const poId = document.getElementById('inputId').value;
            if(!poId) {
                showAlert('Please save the PO first to generate a PDF.', 'warning');
                return;
            }
            viewDetails(poId);
            setTimeout(() => {
                const printContent = document.getElementById('modalPrintArea').innerHTML;
                const printWin = window.open('', '_blank');
                printWin.document.write(`<html><head><title>Nelun POS - Purchase Order</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body style="padding:40px;"><h2>Purchase Order</h2><hr>${printContent}</body></html>`);
                printWin.document.close();
                printWin.focus();
                setTimeout(() => { printWin.print(); printWin.close(); }, 500);
            }, 600);
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadConfig();
            fetchPOs();
        });
    </script>
</body>
</html>
