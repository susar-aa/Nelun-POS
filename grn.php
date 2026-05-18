<?php
// grn.php
// Goods Received Notes (GRN) System for Nelun POS
// Handles receiving inventory, generating stock batches, updating prices, and ledger logging.

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

    // 1. GET ALL GRN TRANSACTIONS
    if ($action === 'getGRNs') {
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-d');
        $supplier = $_GET['supplier_id'] ?? 'All';
        $search = $_GET['search'] ?? '';

        try {
            $sql = "
                SELECT grn.*, s.name as supplier_name, u.username as creator_name
                FROM goods_received_notes grn
                JOIN suppliers s ON grn.supplier_id = s.supplier_id
                LEFT JOIN users u ON grn.created_by = u.user_id
                WHERE grn.received_date BETWEEN ? AND ?
            ";
            $params = [$start, $end];

            if ($supplier !== 'All') {
                $sql .= " AND grn.supplier_id = ?";
                $params[] = $supplier;
            }

            if (!empty($search)) {
                $sql .= " AND (grn.grn_number LIKE ? OR s.name LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $sql .= " ORDER BY grn.grn_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(["success" => true, "grns" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 2. GET SINGLE GRN DETAILS
    if ($action === 'getGRNDetails') {
        $grn_id = $_GET['grn_id'] ?? null;
        if (!$grn_id) {
            echo json_encode(["success" => false, "message" => "GRN ID is required."]);
            exit();
        }

        try {
            $stmtHeader = $pdo->prepare("
                SELECT grn.*, s.name as supplier_name, s.phone, s.contact_person, u.username as creator_name, po.po_number
                FROM goods_received_notes grn
                JOIN suppliers s ON grn.supplier_id = s.supplier_id
                LEFT JOIN purchase_orders po ON grn.po_id = po.po_id
                LEFT JOIN users u ON grn.created_by = u.user_id
                WHERE grn.grn_id = ?
            ");
            $stmtHeader->execute([$grn_id]);
            $grn = $stmtHeader->fetch(PDO::FETCH_ASSOC);

            if (!$grn) {
                echo json_encode(["success" => false, "message" => "GRN not found."]);
                exit();
            }

            $stmtItems = $pdo->prepare("
                SELECT gi.*, p.name as product_name, p.product_code
                FROM grn_items gi
                JOIN Products p ON gi.product_id = p.product_id
                WHERE gi.grn_id = ?
            ");
            $stmtItems->execute([$grn_id]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["success" => true, "grn" => $grn, "items" => $items]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 3. PROCESS AND SAVE A GRN INTAKE (CREATE BATCHES & ADJUST BALANCES)
    if ($action === 'saveGRN') {
        $po_id = !empty($input['po_id']) ? (int)$input['po_id'] : null;
        $supplier_id = (int)($input['supplier_id'] ?? 0);
        $received_date = $input['received_date'] ?? date('Y-m-d');
        $payment_method = $input['payment_method'] ?? 'Credit';
        $paid_amount = (float)($input['paid_amount'] ?? 0.00);
        $cheque_number = trim($input['cheque_number'] ?? '');
        $cheque_date = !empty($input['cheque_date']) ? $input['cheque_date'] : null;
        $items = $input['items'] ?? [];
        $user_id = $input['user_id'] ?? 1;

        if (!$supplier_id || empty($items)) {
            echo json_encode(["success" => false, "message" => "Supplier and items are required."]);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // 1. Calculate Grand Total of GRN
            $total_amount = 0.00;
            foreach ($items as $item) {
                $total_amount += ((int)$item['quantity_received'] * (float)$item['cost_price']);
            }

            // 2. Generate GRN Unique Number
            $date_slug = date('Ymd');
            $stmtCount = $pdo->query("SELECT COUNT(*) FROM goods_received_notes WHERE received_date = CURRENT_DATE()");
            $today_count = (int)$stmtCount->fetchColumn() + 1;
            $grn_number = "GRN-" . $date_slug . "-" . str_pad($today_count, 4, '0', STR_PAD_LEFT);

            // 3. Insert GRN Header
            $stmtHeader = $pdo->prepare("
                INSERT INTO goods_received_notes (grn_number, po_id, supplier_id, received_date, total_amount, paid_amount, payment_method, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtHeader->execute([$grn_number, $po_id, $supplier_id, $received_date, $total_amount, $paid_amount, $payment_method, $user_id]);
            $grn_id = $pdo->lastInsertId();

            // 4. Update PO status to Completed if applicable
            if ($po_id) {
                $stmtPO = $pdo->prepare("UPDATE purchase_orders SET status = 'Completed' WHERE po_id = ?");
                $stmtPO->execute([$po_id]);
            }

            // Prepare statements for inserting items, batches, and updating products
            $stmtInsertItem = $pdo->prepare("
                INSERT INTO grn_items (grn_id, product_id, quantity_received, cost_price, selling_price)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtInsertBatch = $pdo->prepare("
                INSERT INTO product_batches (product_id, grn_item_id, cost_price, selling_price, quantity_received, quantity_remaining, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Active')
            ");
            $stmtUpdateCatalog = $pdo->prepare("
                UPDATE Products 
                SET quantity = quantity + ?, cost = ?, price = ?
                WHERE product_id = ?
            ");

            // 5. Process Items
            foreach ($items as $item) {
                $product_id = (int)$item['product_id'];
                $qty = (int)$item['quantity_received'];
                $cost = (float)$item['cost_price'];
                $price = (float)$item['selling_price'];

                // Insert into grn_items
                $stmtInsertItem->execute([$grn_id, $product_id, $qty, $cost, $price]);
                $grn_item_id = $pdo->lastInsertId();

                // Create Product Batch card
                $stmtInsertBatch->execute([$product_id, $grn_item_id, $cost, $price, $qty, $qty]);

                // Update catalog quantities and catalog price cards
                $stmtUpdateCatalog->execute([$qty, $cost, $price, $product_id]);
            }

            // 6. Update Supplier Ledger (Account Bookkeeping)
            // A: Log the Credit (increases outstanding balance)
            $stmtBal1 = $pdo->prepare("SELECT COALESCE(SUM(credit) - SUM(debit), 0.00) FROM supplier_ledger WHERE supplier_id = ?");
            $stmtBal1->execute([$supplier_id]);
            $cur_bal = (float)$stmtBal1->fetchColumn();
            $new_bal = $cur_bal + $total_amount;

            $stmtLedger1 = $pdo->prepare("
                INSERT INTO supplier_ledger (supplier_id, transaction_type, reference_id, debit, credit, balance, notes)
                VALUES (?, 'GRN', ?, 0.00, ?, ?, ?)
            ");
            $stmtLedger1->execute([$supplier_id, $grn_id, $total_amount, $new_bal, "Received inventory invoice #$grn_number"]);

            // B: Log the Payment if paid_amount > 0 (reduces outstanding liability)
            if ($paid_amount > 0) {
                // Insert supplier payment
                $stmtPay = $pdo->prepare("
                    INSERT INTO supplier_payments (supplier_id, payment_date, amount, payment_method, cheque_number, cheque_date, cheque_status, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $cheque_status = ($payment_method === 'Cheque') ? 'Pending' : null;
                $stmtPay->execute([$supplier_id, $received_date, $paid_amount, $payment_method, $cheque_number, $cheque_date, $cheque_status, "Partial/Full payment at inventory receipt", $user_id]);
                $payment_id = $pdo->lastInsertId();

                // Log debit entry in ledger
                $new_bal2 = $new_bal - $paid_amount;
                $stmtLedger2 = $pdo->prepare("
                    INSERT INTO supplier_ledger (supplier_id, transaction_type, reference_id, debit, credit, balance, notes)
                    VALUES (?, 'Payment', ?, ?, 0.00, ?, ?)
                ");
                $ref_notes = "Paid via $payment_method during intake #$grn_number";
                $stmtLedger2->execute([$supplier_id, $payment_id, $paid_amount, $new_bal2, $ref_notes]);
            }

            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Goods received, catalog updated, ledger posted successfully.", "grn_id" => $grn_id]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => "Database Error: " . $e->getMessage()]);
        }
        exit();
    }
}

// Check if loaded from Purchase Order directly
$po_id_param = $_GET['po_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Received Note (GRN)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; padding: 20px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .table th { font-weight: 600; color: #6c757d; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }

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
                    <h2 class="fw-bold mb-0">Goods Received Notes (GRN)</h2>
                    <p class="text-muted small mb-0">Track store inventory intakes and ledger transactions</p>
                </div>
                <button class="btn btn-primary shadow-sm px-4 rounded-pill" onclick="showCreateScreen()">
                    <i class="bi bi-box-seam-fill"></i> Receive Stock (GRN)
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
                    <div class="col-md-3">
                        <label class="form-label small text-muted fw-bold">Supplier</label>
                        <select id="filterSupplier" class="form-select form-select-sm">
                            <option value="All">All Suppliers</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted fw-bold">Search GRN #</label>
                        <input type="text" id="searchBox" class="form-control form-control-sm" placeholder="e.g. GRN-2026...">
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-dark btn-sm w-100" onclick="fetchGRNs()">Filter</button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="card border-0 overflow-hidden bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">GRN Number</th>
                                <th>Received Date</th>
                                <th>Supplier</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-end">Paid Amount</th>
                                <th class="text-center">Method</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="grnTableBody">
                            <tr><td colspan="7" class="text-center py-5 text-muted">Loading GRNs history...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create/Intake GRN Screen -->
        <div id="createView" class="d-none">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="fw-bold mb-0">Record Goods Received</h2>
                    <p class="text-muted small mb-0" id="grnCreateSub">Receive supplier stock, set retail prices and batches</p>
                </div>
                <button class="btn btn-light border px-4 rounded-pill" onclick="showHistoryScreen()">
                    <i class="bi bi-arrow-left"></i> Back to History
                </button>
            </div>

            <div class="row g-4">
                <!-- GRN Intake Form Info -->
                <div class="col-lg-4">
                    <div class="card p-4 bg-white mb-4">
                        <h5 class="fw-bold mb-3 border-bottom pb-2">GRN Metadata</h5>
                        <form id="grnForm">
                            <input type="hidden" id="inputPOId">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Supplier</label>
                                <select id="inputSupplier" class="form-select" required>
                                    <option value="">-- Select Supplier --</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Date Received</label>
                                <input type="date" id="inputReceivedDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <h5 class="fw-bold mt-4 mb-3 border-bottom pb-2">Billing & Ledger Log</h5>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Payment Terms / Method</label>
                                <select id="inputPayMethod" class="form-select" onchange="togglePayFields()" required>
                                    <option value="Credit">Credit (Add to Ledger Owed)</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Bank_Transfer">Bank Transfer</option>
                                </select>
                            </div>
                            <div class="mb-3" id="paidAmountFields">
                                <label class="form-label small fw-bold">Paid Amount (Rs.)</label>
                                <input type="number" id="inputPaidAmount" class="form-control fw-semibold" step="0.01" min="0.00" value="0.00" onkeyup="checkPartialPaid()">
                            </div>
                            <div id="chequeIntakeFields" class="d-none">
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <label class="form-label small fw-bold">Cheque Number</label>
                                        <input type="text" id="inputChqNumber" class="form-control" placeholder="6-digits">
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label class="form-label small fw-bold">Cheque Date</label>
                                        <input type="date" id="inputChqDate" class="form-control">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="border-top pt-3 mt-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small fw-semibold text-muted">Intake Value:</span>
                                    <span class="fw-bold text-dark fs-5">Rs. <span id="lblGRNSub">0.00</span></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="small fw-semibold text-muted">Amount Owed / Ledger Debit:</span>
                                    <span class="fw-bold text-danger fs-5">Rs. <span id="lblGRNOwed">0.00</span></span>
                                </div>
                                <button type="submit" class="btn btn-success w-100 py-2.5 rounded-pill fw-bold shadow-sm">Save Intake & Update Stock</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- GRN Intake Item List -->
                <div class="col-lg-8">
                    <div class="card p-4 bg-white h-100">
                        <h5 class="fw-bold mb-3 border-bottom pb-2">Intake Product Batches</h5>
                        
                        <!-- Manual product loader, hidden if loaded PO -->
                        <div class="position-relative mb-4" id="itemAdderContainer">
                            <label class="form-label small fw-bold">Search & Add Product Manually</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-search text-success"></i></span>
                                <input type="text" id="prodSearchBox" class="form-control" placeholder="Type product name or scan barcode to add..." onkeyup="searchProducts()">
                            </div>
                            <div class="search-results-dropdown d-none" id="searchResults">
                                <!-- Suggested items -->
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product details</th>
                                        <th style="width: 140px;">Unit Cost</th>
                                        <th style="width: 140px;">Retail Price</th>
                                        <th style="width: 100px;">Qty Received</th>
                                        <th class="text-end">Line Total</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="grnItemTableBody">
                                    <tr><td colspan="6" class="text-center py-5 text-muted">No products received. Select or import items.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- GRN Details Modal -->
    <div class="modal fade" id="grnDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold" id="modalGRNNumber">GRN-XXXXXX</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="modalPrintArea">
                    <div class="row mb-4">
                        <div class="col-6">
                            <small class="text-uppercase text-muted fw-bold">Purchased From</small>
                            <h5 class="fw-bold text-dark mt-1 mb-0" id="modalSupName">Supplier Name</h5>
                            <small class="text-muted" id="modalSupContact">0718425858</small>
                        </div>
                        <div class="col-6 text-end">
                            <small class="text-uppercase text-muted fw-bold">Intake Details</small>
                            <div class="mt-1">Date: <span class="fw-bold" id="modalReceivedDate">2026-05-18</span></div>
                            <div id="modalRefPO">Source PO: <span class="fw-bold" id="modalSourcePO">N/A</span></div>
                        </div>
                    </div>

                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Product Details</th>
                                <th class="text-end" style="width: 140px;">Unit Cost</th>
                                <th class="text-end" style="width: 140px;">Retail Price</th>
                                <th class="text-center" style="width: 100px;">Qty Received</th>
                                <th class="text-end" style="width: 150px;">Line Total</th>
                            </tr>
                        </thead>
                        <tbody id="modalItemTableBody">
                            <!-- Items -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Invoice Subtotal:</th>
                                <th class="text-end" id="modalTotalAmount">Rs. 0.00</th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-end">Paid Amount (at receipt):</th>
                                <th class="text-end text-success" id="modalPaidAmount">Rs. 0.00</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                    <button class="btn btn-primary rounded-pill px-4" onclick="window.print()"><i class="bi bi-printer"></i> Print Invoice</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const grnDetailsModal = new bootstrap.Modal(document.getElementById('grnDetailsModal'));
        
        let suppliers = [];
        let grns = [];
        let grnItems = []; // PO Conversion or manually built

        // Check if pre-load is needed
        const urlParams = new URLSearchParams(window.location.search);
        const preloadPoId = urlParams.get('po_id');

        async function loadConfig() {
            try {
                const resSup = await fetch('suppliers.php?action=getSuppliers');
                const dataSup = await resSup.json();
                if(dataSup.success) {
                    suppliers = dataSup.suppliers;
                    
                    const filterDropdown = document.getElementById('filterSupplier');
                    const formDropdown = document.getElementById('inputSupplier');
                    
                    suppliers.forEach(s => {
                        filterDropdown.add(new Option(s.name, s.supplier_id));
                        formDropdown.add(new Option(s.name, s.supplier_id));
                    });
                }
                
                if (preloadPoId) {
                    loadFromPO(preloadPoId);
                }
            } catch(e) { console.error('Error fetching suppliers dropdowns.'); }
        }

        async function fetchGRNs() {
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            const supplier = document.getElementById('filterSupplier').value;
            const search = document.getElementById('searchBox').value;

            try {
                const res = await fetch(`grn.php?action=getGRNs&start=${start}&end=${end}&supplier_id=${supplier}&search=${encodeURIComponent(search)}`);
                const data = await res.json();
                if(data.success) {
                    grns = data.grns;
                    renderGRNTable(grns);
                }
            } catch(e) { showAlert('Error fetching GRN history.', 'danger'); }
        }

        function renderGRNTable(list) {
            const tbody = document.getElementById('grnTableBody');
            tbody.innerHTML = '';

            if(list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">No GRN transactions found.</td></tr>';
                return;
            }

            list.forEach(g => {
                const total = parseFloat(g.total_amount);
                const paid = parseFloat(g.paid_amount);
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="ps-4 fw-bold text-dark">${g.grn_number}</td>
                    <td>${g.received_date}</td>
                    <td class="fw-semibold text-muted">${g.supplier_name}</td>
                    <td class="text-end fw-bold">Rs. ${total.toFixed(2)}</td>
                    <td class="text-end text-success fw-bold">Rs. ${paid.toFixed(2)}</td>
                    <td class="text-center"><span class="badge bg-light text-dark fw-bold border">${g.payment_method}</span></td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-link text-primary" onclick="viewDetails(${g.grn_id})"><i class="bi bi-eye-fill"></i> View Details</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        async function viewDetails(id) {
            try {
                const res = await fetch(`grn.php?action=getGRNDetails&grn_id=${id}`);
                const data = await res.json();
                if(data.success) {
                    const grn = data.grn;
                    
                    document.getElementById('modalGRNNumber').textContent = grn.grn_number;
                    document.getElementById('modalSupName').textContent = grn.supplier_name;
                    document.getElementById('modalSupContact').textContent = grn.phone;
                    document.getElementById('modalReceivedDate').textContent = grn.received_date;
                    
                    if (grn.po_number) {
                        document.getElementById('modalRefPO').classList.remove('d-none');
                        document.getElementById('modalSourcePO').textContent = grn.po_number;
                    } else {
                        document.getElementById('modalRefPO').classList.add('d-none');
                    }

                    const tbody = document.getElementById('modalItemTableBody');
                    tbody.innerHTML = '';
                    
                    data.items.forEach(item => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><span class="fw-bold">${item.product_name}</span><br><small class="text-muted font-monospace">${item.product_code}</small></td>
                            <td class="text-end">Rs. ${parseFloat(item.cost_price).toFixed(2)}</td>
                            <td class="text-end">Rs. ${parseFloat(item.selling_price).toFixed(2)}</td>
                            <td class="text-center">${item.quantity_received}</td>
                            <td class="text-end fw-semibold">Rs. ${parseFloat(item.line_total).toFixed(2)}</td>
                        `;
                        tbody.appendChild(tr);
                    });

                    document.getElementById('modalTotalAmount').textContent = 'Rs. ' + parseFloat(grn.total_amount).toFixed(2);
                    document.getElementById('modalPaidAmount').textContent = 'Rs. ' + parseFloat(grn.paid_amount).toFixed(2);
                    
                    grnDetailsModal.show();
                }
            } catch(e) { showAlert('Error getting GRN details.', 'danger'); }
        }

        // Intake screen
        function showCreateScreen() {
            document.getElementById('historyView').classList.add('d-none');
            document.getElementById('createView').classList.remove('d-none');
            
            // Reset
            document.getElementById('grnForm').reset();
            document.getElementById('inputPOId').value = '';
            document.getElementById('grnCreateSub').textContent = "Receive supplier stock, set retail prices and batches";
            document.getElementById('paidAmountFields').classList.remove('d-none');
            
            grnItems = [];
            renderGRNItems();
            recalculateGRN();
        }

        function showHistoryScreen() {
            document.getElementById('createView').classList.add('d-none');
            document.getElementById('historyView').classList.remove('d-none');
            // Clean URL query
            if(window.location.search) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            fetchGRNs();
        }

        async function loadFromPO(po_id) {
            try {
                const res = await fetch(`purchase-orders.php?action=getPODetails&po_id=${po_id}`);
                const data = await res.json();
                if(data.success) {
                    showCreateScreen();
                    const po = data.purchase_order;
                    
                    document.getElementById('inputPOId').value = po.po_id;
                    document.getElementById('inputSupplier').value = po.supplier_id;
                    document.getElementById('grnCreateSub').textContent = `Transferring Purchase Order (${po.po_number}) to Goods Intakes`;

                    grnItems = data.items.map(item => ({
                        product_id: item.product_id,
                        product_name: item.product_name,
                        product_code: item.product_code,
                        cost_price: parseFloat(item.cost_price),
                        selling_price: parseFloat(item.cost_price * 1.3), // Default suggested price (30% markup)
                        quantity_received: parseInt(item.quantity)
                    }));
                    renderGRNItems();
                    recalculateGRN();
                }
            } catch(e) { showAlert('Error loading source PO details.', 'danger'); }
        }

        // Manual search
        async function searchProducts() {
            const query = document.getElementById('prodSearchBox').value;
            const resultsDiv = document.getElementById('searchResults');
            
            if(query.length < 2) {
                resultsDiv.classList.add('d-none');
                return;
            }

            try {
                const res = await fetch(`products.php?action=searchProducts&search=${encodeURIComponent(query)}`);
                const data = await res.json();
                
                if(data.success && data.products.length > 0) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.classList.remove('d-none');
                    
                    data.products.forEach(p => {
                        const item = document.createElement('div');
                        item.className = 'search-result-item';
                        item.onclick = () => addProductToGRN(p);
                        item.innerHTML = `
                            <div class="fw-bold">${p.name}</div>
                            <small class="text-muted">Barcode: ${p.product_code} | Price: Rs. ${parseFloat(p.price).toFixed(2)}</small>
                        `;
                        resultsDiv.appendChild(item);
                    });
                } else {
                    resultsDiv.classList.add('d-none');
                }
            } catch(e) { console.error('Error searching products.'); }
        }

        function addProductToGRN(product) {
            document.getElementById('prodSearchBox').value = '';
            document.getElementById('searchResults').classList.add('d-none');

            const exists = grnItems.find(item => item.product_id == product.product_id);
            if(exists) {
                exists.quantity_received++;
            } else {
                grnItems.push({
                    product_id: product.product_id,
                    product_name: product.name,
                    product_code: product.product_code,
                    cost_price: parseFloat(product.cost || 0.00),
                    selling_price: parseFloat(product.price || 0.00),
                    quantity_received: 1
                });
            }

            renderGRNItems();
            recalculateGRN();
        }

        function renderGRNItems() {
            const tbody = document.getElementById('grnItemTableBody');
            tbody.innerHTML = '';

            if(grnItems.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">No products received. Select or import items.</td></tr>';
                return;
            }

            grnItems.forEach((item, index) => {
                const subtotal = item.cost_price * item.quantity_received;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="fw-semibold text-dark">${item.product_name}<br><small class="text-muted font-monospace">${item.product_code}</small></td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end" value="${item.cost_price.toFixed(2)}" step="0.01" min="0.00" onchange="updateLineCost(${index}, this.value)">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-end" value="${item.selling_price.toFixed(2)}" step="0.01" min="0.00" onchange="updateLinePrice(${index}, this.value)">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm text-center" value="${item.quantity_received}" min="1" onchange="updateLineQty(${index}, this.value)">
                    </td>
                    <td class="text-end fw-bold text-primary">Rs. ${subtotal.toFixed(2)}</td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-danger border-0" onclick="removeGRNItem(${index})"><i class="bi bi-trash-fill"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function updateLineCost(index, value) {
            grnItems[index].cost_price = parseFloat(value) || 0.00;
            renderGRNItems();
            recalculateGRN();
        }

        function updateLinePrice(index, value) {
            grnItems[index].selling_price = parseFloat(value) || 0.00;
            renderGRNItems();
            recalculateGRN();
        }

        function updateLineQty(index, value) {
            grnItems[index].quantity_received = parseInt(value) || 1;
            renderGRNItems();
            recalculateGRN();
        }

        function removeGRNItem(index) {
            grnItems.splice(index, 1);
            renderGRNItems();
            recalculateGRN();
        }

        function recalculateGRN() {
            let total = 0.00;
            grnItems.forEach(item => {
                total += (item.cost_price * item.quantity_received);
            });

            document.getElementById('lblGRNSub').textContent = total.toLocaleString('en-LK', { minimumFractionDigits: 2 });
            
            const method = document.getElementById('inputPayMethod').value;
            const paidInput = document.getElementById('inputPaidAmount');
            
            if (method !== 'Credit' && method !== 'Cheque') {
                paidInput.value = total.toFixed(2);
            }
            
            checkPartialPaid();
        }

        function togglePayFields() {
            const method = document.getElementById('inputPayMethod').value;
            const chqDiv = document.getElementById('chequeIntakeFields');
            const paidDiv = document.getElementById('paidAmountFields');
            
            if (method === 'Cheque') {
                chqDiv.className = 'd-block';
                document.getElementById('inputChqNumber').required = true;
                document.getElementById('inputChqDate').required = true;
                paidDiv.classList.remove('d-none');
            } else {
                chqDiv.className = 'd-none';
                document.getElementById('inputChqNumber').required = false;
                document.getElementById('inputChqDate').required = false;
            }

            recalculateGRN();
        }

        function checkPartialPaid() {
            const sub = parseFloat(document.getElementById('lblGRNSub').textContent.replace(/,/g, '')) || 0.00;
            const paid = parseFloat(document.getElementById('inputPaidAmount').value) || 0.00;
            
            const owed = Math.max(0, sub - paid);
            document.getElementById('lblGRNOwed').textContent = owed.toLocaleString('en-LK', { minimumFractionDigits: 2 });
        }

        document.getElementById('grnForm').onsubmit = async (e) => {
            e.preventDefault();
            
            if(grnItems.length === 0) {
                showAlert('Please load or select products to check-in stock.', 'warning');
                return;
            }

            const payload = {
                po_id: document.getElementById('inputPOId').value,
                supplier_id: document.getElementById('inputSupplier').value,
                received_date: document.getElementById('inputReceivedDate').value,
                payment_method: document.getElementById('inputPayMethod').value,
                paid_amount: document.getElementById('inputPaidAmount').value,
                cheque_number: document.getElementById('inputChqNumber').value,
                cheque_date: document.getElementById('inputChqDate').value,
                items: grnItems,
                user_id: localStorage.getItem('user_id') || 1
            };

            try {
                const res = await fetch('grn.php?action=saveGRN', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(data.success) {
                    showAlert(data.message, 'success');
                    showHistoryScreen();
                } else { showAlert(data.message, 'danger'); }
            } catch(e) { showAlert('Error recording GRN stocks.', 'danger'); }
        };

        function showAlert(msg, type) {
            const div = document.createElement('div');
            div.className = `alert alert-${type} alert-dismissible fade show shadow fixed-top m-3`;
            div.style.zIndex = 2000;
            div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.getElementById('alertContainer').appendChild(div);
            setTimeout(() => div.remove(), 3000);
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadConfig();
            fetchGRNs();
        });
    </script>
</body>
</html>
