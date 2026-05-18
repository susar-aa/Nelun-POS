<?php
// suppliers.php
// Supplier Profile & Payments Ledger for Nelun POS
// Handles supplier CRUD, ledger details, cash/cheque/transfer payments, and cheque clearing logic.

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

    // 1. GET ALL SUPPLIERS WITH OUTSTANDING BALANCES
    if ($action === 'getSuppliers') {
        try {
            $sql = "
                SELECT s.*, 
                       COALESCE(SUM(l.credit) - SUM(l.debit), 0.00) as outstanding_balance,
                       (SELECT MAX(created_at) FROM supplier_ledger WHERE supplier_id = s.supplier_id) as last_activity
                FROM suppliers s
                LEFT JOIN supplier_ledger l ON s.supplier_id = l.supplier_id
                GROUP BY s.supplier_id
                ORDER BY s.name ASC
            ";
            $stmt = $pdo->query($sql);
            echo json_encode(["success" => true, "suppliers" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 2. GET SUPPLIER PROFILE & FULL LEDGER
    if ($action === 'getSupplierProfile') {
        $supplier_id = $_GET['supplier_id'] ?? null;
        if (!$supplier_id) {
            echo json_encode(["success" => false, "message" => "Supplier ID is required."]);
            exit();
        }

        try {
            // Fetch profile
            $stmtProfile = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
            $stmtProfile->execute([$supplier_id]);
            $profile = $stmtProfile->fetch(PDO::FETCH_ASSOC);

            if (!$profile) {
                echo json_encode(["success" => false, "message" => "Supplier not found."]);
                exit();
            }

            // Fetch running ledger transactions chronologically
            $stmtLedger = $pdo->prepare("
                SELECT l.*,
                       CASE 
                           WHEN l.transaction_type = 'GRN' THEN (SELECT grn_number FROM goods_received_notes WHERE grn_id = l.reference_id)
                           WHEN l.transaction_type = 'Payment' THEN (SELECT payment_method FROM supplier_payments WHERE payment_id = l.reference_id)
                           WHEN l.transaction_type = 'Cheque_Return' THEN 'Cheque Return'
                           ELSE 'Adjustment'
                       END as details,
                       CASE 
                           WHEN l.transaction_type = 'Payment' THEN (SELECT cheque_number FROM supplier_payments WHERE payment_id = l.reference_id)
                           ELSE NULL
                       END as cheque_number,
                       CASE 
                           WHEN l.transaction_type = 'Payment' THEN (SELECT cheque_status FROM supplier_payments WHERE payment_id = l.reference_id)
                           ELSE NULL
                       END as cheque_status,
                       CASE 
                           WHEN l.transaction_type = 'Payment' THEN (SELECT cheque_date FROM supplier_payments WHERE payment_id = l.reference_id)
                           ELSE NULL
                       END as cheque_date
                FROM supplier_ledger l
                WHERE l.supplier_id = ?
                ORDER BY l.created_at ASC, l.ledger_id ASC
            ");
            $stmtLedger->execute([$supplier_id]);
            $ledgerRaw = $stmtLedger->fetchAll(PDO::FETCH_ASSOC);

            // Compute running balance in code to be 100% reliable
            $ledger = [];
            $running_balance = 0.00;
            foreach ($ledgerRaw as $row) {
                $debit = (float)$row['debit'];
                $credit = (float)$row['credit'];
                $running_balance += ($credit - $debit);
                $row['running_balance'] = $running_balance;
                $ledger[] = $row;
            }

            // Summaries
            $stmtSum = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(credit), 0.00) as total_purchases,
                    COALESCE(SUM(debit), 0.00) as total_paid
                FROM supplier_ledger 
                WHERE supplier_id = ?
            ");
            $stmtSum->execute([$supplier_id]);
            $sums = $stmtSum->fetch(PDO::FETCH_ASSOC);
            $sums['outstanding'] = $sums['total_purchases'] - $sums['total_paid'];

            echo json_encode([
                "success" => true,
                "profile" => $profile,
                "ledger" => array_reverse($ledger), // Display newest first in UI
                "summaries" => $sums
            ]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 3. SAVE/UPDATE SUPPLIER
    if ($action === 'saveSupplier') {
        $id = $input['supplier_id'] ?? null;
        $name = trim($input['name'] ?? '');
        $contact_person = trim($input['contact_person'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? '');
        $address = trim($input['address'] ?? '');

        if (empty($name)) {
            echo json_encode(["success" => false, "message" => "Supplier name is required."]);
            exit();
        }

        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=? WHERE supplier_id=?");
                $stmt->execute([$name, $contact_person, $phone, $email, $address, $id]);
                echo json_encode(["success" => true, "message" => "Supplier details updated."]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $contact_person, $phone, $email, $address]);
                echo json_encode(["success" => true, "message" => "Supplier registered successfully."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 4. DELETE SUPPLIER
    if ($action === 'deleteSupplier') {
        $id = $input['supplier_id'] ?? null;
        try {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
            $stmt->execute([$id]);
            echo json_encode(["success" => true, "message" => "Supplier removed successfully."]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "Cannot delete supplier: " . $e->getMessage()]);
        }
        exit();
    }

    // 5. SAVE SUPPLIER PAYMENT
    if ($action === 'savePayment') {
        $supplier_id = $input['supplier_id'] ?? null;
        $payment_date = $input['payment_date'] ?? date('Y-m-d');
        $amount = (float)($input['amount'] ?? 0.00);
        $method = $input['payment_method'] ?? 'Cash';
        $cheque_number = trim($input['cheque_number'] ?? '');
        $cheque_date = !empty($input['cheque_date']) ? $input['cheque_date'] : null;
        $notes = trim($input['notes'] ?? '');
        $user_id = $input['user_id'] ?? 1;

        if (!$supplier_id || $amount <= 0) {
            echo json_encode(["success" => false, "message" => "Invalid supplier ID or amount."]);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Insert Payment
            $stmtPay = $pdo->prepare("
                INSERT INTO supplier_payments (supplier_id, payment_date, amount, payment_method, cheque_number, cheque_date, cheque_status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $cheque_status = ($method === 'Cheque') ? 'Pending' : null;
            $stmtPay->execute([$supplier_id, $payment_date, $amount, $method, $cheque_number, $cheque_date, $cheque_status, $notes, $user_id]);
            $payment_id = $pdo->lastInsertId();

            // Calculate current running balance
            $stmtBal = $pdo->prepare("SELECT COALESCE(SUM(credit) - SUM(debit), 0.00) FROM supplier_ledger WHERE supplier_id = ?");
            $stmtBal->execute([$supplier_id]);
            $cur_bal = (float)$stmtBal->fetchColumn();
            $new_bal = $cur_bal - $amount;

            // Log in Ledger (Debit reduces our liability to the supplier)
            $stmtLedger = $pdo->prepare("
                INSERT INTO supplier_ledger (supplier_id, transaction_type, reference_id, debit, credit, balance, notes)
                VALUES (?, 'Payment', ?, ?, 0.00, ?, ?)
            ");
            
            $ref_details = "Paid via $method" . ($method === 'Cheque' ? " (Chq #: $cheque_number)" : "");
            $stmtLedger->execute([$supplier_id, $payment_id, $amount, $new_bal, $ref_details]);

            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Payment of Rs. " . number_format($amount, 2) . " saved."]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 6. UPDATE CHEQUE STATUS (Realize / Return)
    if ($action === 'updateChequeStatus') {
        $payment_id = $input['payment_id'] ?? null;
        $new_status = $input['status'] ?? null; // 'Realized' or 'Returned'

        if (!$payment_id || !in_array($new_status, ['Realized', 'Returned'])) {
            echo json_encode(["success" => false, "message" => "Invalid parameters."]);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Get payment details
            $stmtPayInfo = $pdo->prepare("SELECT * FROM supplier_payments WHERE payment_id = ?");
            $stmtPayInfo->execute([$payment_id]);
            $payment = $stmtPayInfo->fetch(PDO::FETCH_ASSOC);

            if (!$payment || $payment['payment_method'] !== 'Cheque') {
                throw new Exception("Cheque payment details not found.");
            }

            if ($payment['cheque_status'] !== 'Pending') {
                throw new Exception("Cheque has already been cleared or returned.");
            }

            // Update Cheque Status
            $stmtUpdatePay = $pdo->prepare("UPDATE supplier_payments SET cheque_status = ? WHERE payment_id = ?");
            $stmtUpdatePay->execute([$new_status, $payment_id]);

            if ($new_status === 'Returned') {
                // If cheque is returned, we must restore the liability owed to the supplier.
                // Insert a "Cheque_Return" transaction in the ledger (Credit increases outstanding liability)
                $supplier_id = $payment['supplier_id'];
                $amount = $payment['amount'];

                $stmtBal = $pdo->prepare("SELECT COALESCE(SUM(credit) - SUM(debit), 0.00) FROM supplier_ledger WHERE supplier_id = ?");
                $stmtBal->execute([$supplier_id]);
                $cur_bal = (float)$stmtBal->fetchColumn();
                $new_bal = $cur_bal + $amount;

                $stmtLedger = $pdo->prepare("
                    INSERT INTO supplier_ledger (supplier_id, transaction_type, reference_id, debit, credit, balance, notes)
                    VALUES (?, 'Cheque_Return', ?, 0.00, ?, ?, ?)
                ");
                $chq_num = $payment['cheque_number'];
                $stmtLedger->execute([$supplier_id, $payment_id, $amount, $new_bal, "Cheque #$chq_num Returned/Bounced"]);
            }

            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Cheque marked as $new_status successfully."]);
        } catch (Exception $e) {
            $pdo->rollBack();
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
    <title>Supplier Directory & Ledger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; padding: 20px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .table th { font-weight: 600; color: #6c757d; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        
        .supplier-item {
            border-left: 4px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .supplier-item:hover { background-color: #F2F2F7; }
        .supplier-item.active {
            border-left-color: #007AFF;
            background-color: rgba(0, 122, 255, 0.05);
        }

        .summary-card {
            border-radius: 12px;
            padding: 15px;
            color: white;
            height: 100%;
        }
        .bg-gradient-blue { background: linear-gradient(135deg, #007AFF 0%, #0056B3 100%); }
        .bg-gradient-green { background: linear-gradient(135deg, #34C759 0%, #248A3D 100%); }
        .bg-gradient-orange { background: linear-gradient(135deg, #FF9500 0%, #C77300 100%); }

        .cheque-badge { font-size: 0.7rem; padding: 3px 6px; border-radius: 6px; }
        .chq-pending { background-color: #ffeeba; color: #856404; }
        .chq-realized { background-color: #d4edda; color: #155724; }
        .chq-returned { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

    <div id="alertContainer"></div>

    <div class="container-fluid">
        <div class="row g-4">
            
            <!-- Left Side: Directory -->
            <div class="col-lg-4 col-md-5">
                <div class="card p-3 h-100 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="fw-bold mb-0">Suppliers</h4>
                        <button class="btn btn-primary btn-sm px-3 rounded-pill" onclick="openSupplierModal()">
                            <i class="bi bi-plus-lg"></i> Register
                        </button>
                    </div>
                    
                    <div class="input-group input-group-sm mb-3">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="searchBox" class="form-control border-start-0" placeholder="Search suppliers..." onkeyup="filterSuppliers()">
                    </div>

                    <div class="list-group list-group-flush overflow-y-auto" style="max-height: 70vh;" id="supplierList">
                        <!-- Loaded Dynamically -->
                    </div>
                </div>
            </div>

            <!-- Right Side: Profile & Ledger -->
            <div class="col-lg-8 col-md-7">
                <div class="card p-4 h-100 bg-white d-none" id="supplierProfileContainer">
                    
                    <!-- Profile Header -->
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
                        <div>
                            <h2 class="fw-bold mb-1" id="profName">Supplier Name</h2>
                            <p class="text-muted small mb-0"><i class="bi bi-person-fill"></i> <span id="profContact">Contact Person</span> | <i class="bi bi-telephone-fill"></i> <span id="profPhone">00000000</span></p>
                            <p class="text-muted small mb-0"><i class="bi bi-envelope-fill"></i> <span id="profEmail">info@nelun.com</span> | <i class="bi bi-geo-alt-fill"></i> <span id="profAddress">Address</span></p>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-primary btn-sm px-3 rounded-pill" onclick="editCurrentSupplier()">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-success btn-sm px-3 rounded-pill" onclick="openPaymentModal()">
                                <i class="bi bi-cash"></i> Make Payment
                            </button>
                        </div>
                    </div>

                    <!-- Summary Blocks -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-card bg-gradient-blue">
                                <small class="text-uppercase opacity-75">Total Purchases</small>
                                <h3 class="fw-bold mt-1 mb-0">Rs. <span id="lblPurchases">0.00</span></h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card bg-gradient-green">
                                <small class="text-uppercase opacity-75">Total Paid</small>
                                <h3 class="fw-bold mt-1 mb-0">Rs. <span id="lblPaid">0.00</span></h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card bg-gradient-orange">
                                <small class="text-uppercase opacity-75">Outstanding Balance</small>
                                <h3 class="fw-bold mt-1 mb-0">Rs. <span id="lblOutstanding">0.00</span></h3>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <ul class="nav nav-tabs border-bottom-0 mb-3" id="profileTabs">
                        <li class="nav-item">
                            <button class="nav-link active fw-bold" id="ledgerTabBtn" onclick="switchTab('ledger')">Payments Ledger</button>
                        </li>
                    </ul>

                    <!-- Ledger Table -->
                    <div class="table-responsive" style="max-height: 45vh;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light sticky-top">
                                <tr>
                                    <th>Date</th>
                                    <th>Transaction Details</th>
                                    <th class="text-end">Debit (Paid)</th>
                                    <th class="text-end">Credit (GRN)</th>
                                    <th class="text-end">Owed Balance</th>
                                </tr>
                            </thead>
                            <tbody id="ledgerTableBody">
                                <!-- Loaded dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Empty Selection State -->
                <div class="card p-5 h-100 bg-white text-center justify-content-center" id="emptyProfileState">
                    <i class="bi bi-person-lines-fill display-1 text-muted opacity-25"></i>
                    <h4 class="fw-bold mt-3 text-muted">No Supplier Selected</h4>
                    <p class="text-muted">Select a supplier from the list on the left to inspect their payments, GRN invoices, cheques, and running ledger.</p>
                </div>

            </div>
        </div>
    </div>

    <!-- Supplier CRUD Modal -->
    <div class="modal fade" id="supplierModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold" id="supModalTitle">Register Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="supplierForm">
                        <input type="hidden" id="supId">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Supplier / Business Name</label>
                            <input type="text" id="supName" class="form-control" placeholder="e.g. Atlas Axillia Co." required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Contact Person</label>
                            <input type="text" id="supContact" class="form-control" placeholder="e.g. Mr. Sunil Perera" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Phone Number</label>
                                <input type="text" id="supPhone" class="form-control" placeholder="e.g. 0718425858" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Email Address</label>
                                <input type="email" id="supEmail" class="form-control" placeholder="e.g. sunil@atlas.lk">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Office Address</label>
                            <textarea id="supAddress" class="form-control" rows="2" placeholder="e.g. Malabe, Colombo"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="supplierForm" class="btn btn-primary rounded-pill px-4">Register Supplier</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Entry Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold">Record Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="paymentForm">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Amount to Pay (Rs.)</label>
                            <input type="number" id="payAmount" class="form-control fw-bold fs-4 text-success" step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Payment Method</label>
                                <select id="payMethod" class="form-select" onchange="toggleChequeFields()" required>
                                    <option value="Cash">Cash</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Bank_Transfer">Bank Transfer</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Date of Payment</label>
                                <input type="date" id="payDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div id="chequeFields" class="d-none">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold">Cheque Number</label>
                                    <input type="text" id="payChqNumber" class="form-control" placeholder="6-digit Cheque No.">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold">Cheque Date</label>
                                    <input type="date" id="payChqDate" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Notes / Description</label>
                            <textarea id="payNotes" class="form-control" rows="2" placeholder="e.g. Paid for invoice GRN-1002"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="paymentForm" class="btn btn-success rounded-pill px-4">Submit Payment</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));
        const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
        
        let suppliers = [];
        let selectedSupplierId = null;

        async function fetchSuppliers() {
            try {
                const res = await fetch('suppliers.php?action=getSuppliers');
                const data = await res.json();
                if(data.success) {
                    suppliers = data.suppliers;
                    renderDirectory(suppliers);
                    if(selectedSupplierId) {
                        selectSupplier(selectedSupplierId);
                    }
                }
            } catch(e) { showAlert('Error loading suppliers directory.', 'danger'); }
        }

        function renderDirectory(list) {
            const container = document.getElementById('supplierList');
            container.innerHTML = '';
            
            if(list.length === 0) {
                container.innerHTML = '<div class="text-center py-5 text-muted small">No suppliers found.</div>';
                return;
            }

            list.forEach(s => {
                const bal = parseFloat(s.outstanding_balance);
                const balText = bal.toLocaleString('en-LK', { minimumFractionDigits: 2 });
                
                const item = document.createElement('div');
                item.className = `list-group-item supplier-item p-3 ${selectedSupplierId == s.supplier_id ? 'active' : ''}`;
                item.onclick = () => selectSupplier(s.supplier_id);
                item.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h6 class="mb-0 fw-bold">${s.name}</h6>
                        <span class="badge rounded-pill bg-light text-dark fw-bold border" style="font-size:0.75rem;">Owed: Rs. ${balText}</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>${s.contact_person}</span>
                        <span>${s.phone}</span>
                    </div>
                `;
                container.appendChild(item);
            });
        }

        function filterSuppliers() {
            const query = document.getElementById('searchBox').value.toLowerCase();
            const filtered = suppliers.filter(s => 
                s.name.toLowerCase().includes(query) || 
                s.contact_person.toLowerCase().includes(query) || 
                s.phone.includes(query)
            );
            renderDirectory(filtered);
        }

        async function selectSupplier(id) {
            selectedSupplierId = id;
            
            // Set visually active
            document.querySelectorAll('.supplier-item').forEach(el => el.classList.remove('active'));
            fetchSuppliersElements(); // Highlights the selected item
            
            document.getElementById('emptyProfileState').classList.add('d-none');
            document.getElementById('supplierProfileContainer').classList.remove('d-none');

            try {
                const res = await fetch(`suppliers.php?action=getSupplierProfile&supplier_id=${id}`);
                const data = await res.json();
                if(data.success) {
                    const prof = data.profile;
                    const sums = data.summaries;
                    
                    document.getElementById('profName').textContent = prof.name;
                    document.getElementById('profContact').textContent = prof.contact_person;
                    document.getElementById('profPhone').textContent = prof.phone;
                    document.getElementById('profEmail').textContent = prof.email || 'N/A';
                    document.getElementById('profAddress').textContent = prof.address || 'N/A';

                    document.getElementById('lblPurchases').textContent = parseFloat(sums.total_purchases).toLocaleString('en-LK', { minimumFractionDigits: 2 });
                    document.getElementById('lblPaid').textContent = parseFloat(sums.total_paid).toLocaleString('en-LK', { minimumFractionDigits: 2 });
                    
                    const outstanding = parseFloat(sums.outstanding);
                    const outstandingDisplay = document.getElementById('lblOutstanding');
                    outstandingDisplay.textContent = outstanding.toLocaleString('en-LK', { minimumFractionDigits: 2 });
                    
                    if (outstanding > 0) {
                        outstandingDisplay.parentElement.parentElement.className = 'summary-card bg-gradient-orange';
                    } else {
                        outstandingDisplay.parentElement.parentElement.className = 'summary-card bg-gradient-green';
                    }

                    renderLedger(data.ledger);
                }
            } catch(e) { showAlert('Failed to fetch supplier details.', 'danger'); }
        }

        function renderLedger(ledger) {
            const tbody = document.getElementById('ledgerTableBody');
            tbody.innerHTML = '';
            
            if(ledger.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted">No transaction ledger entries recorded.</td></tr>';
                return;
            }

            ledger.forEach(row => {
                const date = row.created_at.split(' ')[0];
                const debit = parseFloat(row.debit);
                const credit = parseFloat(row.credit);
                const bal = parseFloat(row.running_balance);

                let badgeHtml = '';
                if(row.cheque_status) {
                    let statusClass = 'pending';
                    if (row.cheque_status === 'Realized') statusClass = 'realized';
                    if (row.cheque_status === 'Returned') statusClass = 'returned';
                    
                    badgeHtml = `<div class="mt-1"><span class="cheque-badge chq-${statusClass}">Chq: ${row.cheque_number} (${row.cheque_status})</span>`;
                    
                    if(row.cheque_status === 'Pending') {
                        badgeHtml += `
                            <button class="btn btn-sm btn-link text-success p-0 ms-2 small fw-bold" onclick="updateCheque(${row.reference_id}, 'Realized')" style="font-size:0.7rem;">Realize</button>
                            <button class="btn btn-sm btn-link text-danger p-0 ms-2 small fw-bold" onclick="updateCheque(${row.reference_id}, 'Returned')" style="font-size:0.7rem;">Bounce</button>
                        `;
                    }
                    badgeHtml += '</div>';
                }

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${date}</td>
                    <td>
                        <span class="fw-semibold text-dark">${row.transaction_type}</span> 
                        <span class="text-muted small">(${row.details})</span>
                        ${badgeHtml}
                        ${row.notes ? `<div class="text-muted small mt-1 italic">${row.notes}</div>` : ''}
                    </td>
                    <td class="text-end text-success fw-medium">${debit > 0 ? 'Rs. ' + debit.toFixed(2) : '-'}</td>
                    <td class="text-end text-danger fw-medium">${credit > 0 ? 'Rs. ' + credit.toFixed(2) : '-'}</td>
                    <td class="text-end fw-bold">Rs. ${bal.toFixed(2)}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function fetchSuppliersElements() {
            document.querySelectorAll('.supplier-item').forEach(el => {
                const title = el.querySelector('h6').textContent;
                const match = suppliers.find(s => s.name === title);
                if(match && match.supplier_id == selectedSupplierId) {
                    el.classList.add('active');
                }
            });
        }

        // CRUD Helpers
        function openSupplierModal() {
            document.getElementById('supplierForm').reset();
            document.getElementById('supId').value = '';
            document.getElementById('supModalTitle').textContent = "Register New Supplier";
            supplierModal.show();
        }

        function editCurrentSupplier() {
            const match = suppliers.find(s => s.supplier_id == selectedSupplierId);
            if(!match) return;
            document.getElementById('supId').value = match.supplier_id;
            document.getElementById('supName').value = match.name;
            document.getElementById('supContact').value = match.contact_person;
            document.getElementById('supPhone').value = match.phone;
            document.getElementById('supEmail').value = match.email || '';
            document.getElementById('supAddress').value = match.address || '';
            document.getElementById('supModalTitle').textContent = "Edit Supplier Details";
            supplierModal.show();
        }

        document.getElementById('supplierForm').onsubmit = async (e) => {
            e.preventDefault();
            const payload = {
                supplier_id: document.getElementById('supId').value,
                name: document.getElementById('supName').value,
                contact_person: document.getElementById('supContact').value,
                phone: document.getElementById('supPhone').value,
                email: document.getElementById('supEmail').value,
                address: document.getElementById('supAddress').value
            };

            try {
                const res = await fetch('suppliers.php?action=saveSupplier', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(data.success) {
                    showAlert(data.message, 'success');
                    supplierModal.hide();
                    fetchSuppliers();
                } else { showAlert(data.message, 'danger'); }
            } catch(e) { showAlert('Error saving supplier.', 'danger'); }
        };

        // Payments Entry
        function openPaymentModal() {
            document.getElementById('paymentForm').reset();
            document.getElementById('chequeFields').className = 'd-none';
            paymentModal.show();
        }

        function toggleChequeFields() {
            const method = document.getElementById('payMethod').value;
            const fields = document.getElementById('chequeFields');
            if(method === 'Cheque') {
                fields.className = 'd-block';
                document.getElementById('payChqNumber').required = true;
                document.getElementById('payChqDate').required = true;
            } else {
                fields.className = 'd-none';
                document.getElementById('payChqNumber').required = false;
                document.getElementById('payChqDate').required = false;
            }
        }

        document.getElementById('paymentForm').onsubmit = async (e) => {
            e.preventDefault();
            
            const payload = {
                supplier_id: selectedSupplierId,
                amount: document.getElementById('payAmount').value,
                payment_method: document.getElementById('payMethod').value,
                payment_date: document.getElementById('payDate').value,
                cheque_number: document.getElementById('payChqNumber').value,
                cheque_date: document.getElementById('payChqDate').value,
                notes: document.getElementById('payNotes').value,
                user_id: localStorage.getItem('user_id') || 1
            };

            try {
                const res = await fetch('suppliers.php?action=savePayment', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(data.success) {
                    showAlert(data.message, 'success');
                    paymentModal.hide();
                    fetchSuppliers();
                } else { showAlert(data.message, 'danger'); }
            } catch(e) { showAlert('Error recording payment.', 'danger'); }
        };

        async function updateCheque(id, status) {
            if(!confirm(`Confirm marking Cheque as ${status}?`)) return;
            try {
                const res = await fetch('suppliers.php?action=updateChequeStatus', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ payment_id: id, status: status })
                });
                const data = await res.json();
                if(data.success) {
                    showAlert(data.message, 'success');
                    fetchSuppliers();
                } else { showAlert(data.message, 'danger'); }
            } catch(e) { showAlert('Network error updating cheque.', 'danger'); }
        }

        function switchTab(tab) {
            // Ledger is default
        }

        function showAlert(msg, type) {
            const div = document.createElement('div');
            div.className = `alert alert-${type} alert-dismissible fade show shadow fixed-top m-3`;
            div.style.zIndex = 2000;
            div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.getElementById('alertContainer').appendChild(div);
            setTimeout(() => div.remove(), 3000);
        }

        document.addEventListener('DOMContentLoaded', fetchSuppliers);
    </script>
</body>
</html>
