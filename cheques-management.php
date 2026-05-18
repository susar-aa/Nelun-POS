<?php
// cheques-management.php
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

    // 1. GET ALL CHEQUES (Grouped)
    if ($action === 'getCheques') {
        try {
            $sql = "SELECT * FROM cheques ORDER BY banking_date ASC, cheque_id DESC";
            $stmt = $pdo->query($sql);
            $cheques = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group by banking date
            $grouped = [];
            foreach ($cheques as $chq) {
                $date = $chq['banking_date'];
                if (!isset($grouped[$date])) {
                    $grouped[$date] = [];
                }
                $grouped[$date][] = $chq;
            }
            
            echo json_encode(["success" => true, "grouped_cheques" => $grouped, "raw" => $cheques]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 2. SAVE/UPDATE CHEQUE
    if ($action === 'saveCheque') {
        $id = $input['cheque_id'] ?? null;
        $type = $input['type'] ?? 'Issued';
        $payee = trim($input['payee_payer_name'] ?? '');
        $bank = trim($input['bank_name'] ?? '');
        $number = trim($input['cheque_number'] ?? '');
        $date = $input['banking_date'] ?? date('Y-m-d');
        $amount = (float)($input['amount'] ?? 0.00);
        $status = $input['status'] ?? 'Pending';

        if (empty($payee) || empty($number) || $amount <= 0) {
            echo json_encode(["success" => false, "message" => "Payee, Cheque number, and amount are required."]);
            exit();
        }

        try {
            if ($id) {
                // For updates, we don't automatically update reference tables (e.g., supplier ledger) because it can get complex. We only update the cheque record here.
                $stmt = $pdo->prepare("UPDATE cheques SET type=?, payee_payer_name=?, bank_name=?, cheque_number=?, banking_date=?, amount=?, status=? WHERE cheque_id=?");
                $stmt->execute([$type, $payee, $bank, $number, $date, $amount, $status, $id]);
                
                // If it's linked to a supplier payment, cascade the status
                if ($status === 'Realized' || $status === 'Returned') {
                    $stmtRef = $pdo->prepare("SELECT reference_id FROM cheques WHERE cheque_id = ? AND type = 'Issued'");
                    $stmtRef->execute([$id]);
                    $ref = $stmtRef->fetchColumn();
                    if ($ref) {
                        $pdo->exec("UPDATE supplier_payments SET cheque_status = '$status' WHERE payment_id = $ref");
                    }
                }
                
                echo json_encode(["success" => true, "message" => "Cheque details updated."]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO cheques (type, payee_payer_name, bank_name, cheque_number, banking_date, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$type, $payee, $bank, $number, $date, $amount, $status]);
                echo json_encode(["success" => true, "message" => "Cheque registered successfully."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 3. DELETE CHEQUE
    if ($action === 'deleteCheque') {
        $id = $input['cheque_id'] ?? null;
        try {
            $stmt = $pdo->prepare("DELETE FROM cheques WHERE cheque_id = ?");
            $stmt->execute([$id]);
            echo json_encode(["success" => true, "message" => "Cheque deleted."]);
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
    <title>Cheque Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; padding: 20px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .date-group-header {
            background-color: #e9ecef;
            padding: 10px 15px;
            font-weight: 600;
            border-radius: 8px;
            margin-top: 20px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
        }
        .chq-card {
            background: white;
            border-left: 5px solid #6c757d;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: transform 0.2s;
        }
        .chq-card:hover { transform: translateY(-2px); }
        .chq-Issued { border-left-color: #007AFF; }
        .chq-Received { border-left-color: #34C759; }
        
        .status-Pending { color: #FF9500; font-weight: 600; }
        .status-Realized { color: #34C759; font-weight: 600; }
        .status-Returned { color: #FF3B30; font-weight: 600; }
        .status-Cancelled { color: #8E8E93; font-weight: 600; }
    </style>
</head>
<body>
    <div id="alertContainer"></div>

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold mb-0">Cheque Management</h3>
            <button class="btn btn-primary rounded-pill px-4" onclick="openModal()">
                <i class="bi bi-plus-lg"></i> Add Manual Cheque
            </button>
        </div>

        <div class="row">
            <div class="col-md-3">
                <div class="card p-3 sticky-top" style="top: 20px;">
                    <h6 class="fw-bold mb-3">Filters</h6>
                    <div class="mb-3">
                        <label class="form-label small">Search</label>
                        <input type="text" id="filterSearch" class="form-control form-control-sm" placeholder="Payee or Chq No..." oninput="renderCheques()">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Status</label>
                        <select id="filterStatus" class="form-select form-select-sm" onchange="renderCheques()">
                            <option value="All">All Statuses</option>
                            <option value="Pending" selected>Pending</option>
                            <option value="Realized">Realized</option>
                            <option value="Returned">Returned</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Type</label>
                        <select id="filterType" class="form-select form-select-sm" onchange="renderCheques()">
                            <option value="All">All Types</option>
                            <option value="Issued">Issued (Payments)</option>
                            <option value="Received">Received (Sales)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-9" id="chequesContainer">
                <!-- Grouped Cheques Loaded Here -->
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal fade" id="chequeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold" id="modalTitle">Register Cheque</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="chequeForm">
                        <input type="hidden" id="chqId">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Type</label>
                                <select id="chqType" class="form-select" required>
                                    <option value="Issued">Issued (Outward)</option>
                                    <option value="Received">Received (Inward)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Status</label>
                                <select id="chqStatus" class="form-select" required>
                                    <option value="Pending">Pending</option>
                                    <option value="Realized">Realized</option>
                                    <option value="Returned">Returned</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Payee / Payer Name</label>
                            <input type="text" id="chqPayee" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Bank Name</label>
                                <input type="text" id="chqBank" class="form-control" placeholder="e.g. BOC">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Cheque Number</label>
                                <input type="text" id="chqNumber" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Banking Date</label>
                                <input type="date" id="chqDate" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Amount (Rs.)</label>
                                <input type="number" id="chqAmount" class="form-control text-success fw-bold" step="0.01" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="chequeForm" class="btn btn-primary rounded-pill px-4">Save Cheque</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const chequeModal = new bootstrap.Modal(document.getElementById('chequeModal'));
        let allCheques = [];

        function showAlert(msg, type) {
            const container = document.getElementById('alertContainer');
            container.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert" style="position:fixed; top:20px; right:20px; z-index:9999;">
                    ${msg} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            setTimeout(() => { container.innerHTML = ''; }, 4000);
        }

        async function fetchCheques() {
            try {
                const res = await fetch('cheques-management.php?action=getCheques');
                const data = await res.json();
                if (data.success) {
                    allCheques = data.raw;
                    renderCheques();
                }
            } catch (e) {
                showAlert('Failed to load cheques', 'danger');
            }
        }

        function renderCheques() {
            const search = document.getElementById('filterSearch').value.toLowerCase();
            const status = document.getElementById('filterStatus').value;
            const type = document.getElementById('filterType').value;
            
            const container = document.getElementById('chequesContainer');
            container.innerHTML = '';

            // Filter
            let filtered = allCheques.filter(c => {
                const matchSearch = c.payee_payer_name.toLowerCase().includes(search) || c.cheque_number.includes(search);
                const matchStatus = status === 'All' || c.status === status;
                const matchType = type === 'All' || c.type === type;
                return matchSearch && matchStatus && matchType;
            });

            if (filtered.length === 0) {
                container.innerHTML = '<div class="text-center text-muted p-5 bg-white rounded">No cheques found matching filters.</div>';
                return;
            }

            // Group by Date
            const grouped = {};
            filtered.forEach(c => {
                if (!grouped[c.banking_date]) grouped[c.banking_date] = [];
                grouped[c.banking_date].push(c);
            });

            // Sort Dates Ascending
            const sortedDates = Object.keys(grouped).sort();

            sortedDates.forEach(date => {
                const totalForDate = grouped[date].reduce((sum, c) => sum + parseFloat(c.amount), 0);
                
                // Format Date nicely
                const dateObj = new Date(date);
                const isPast = dateObj < new Date(new Date().setHours(0,0,0,0));
                const dateLabel = isPast ? `<span class="text-danger"><i class="bi bi-exclamation-circle-fill"></i> ${date} (Overdue)</span>` : date;

                const header = document.createElement('div');
                header.className = 'date-group-header';
                header.innerHTML = `
                    <span><i class="bi bi-calendar3"></i> Banking Date: ${dateLabel}</span>
                    <span class="badge bg-secondary rounded-pill">Total: Rs. ${totalForDate.toLocaleString('en-LK', {minimumFractionDigits:2})}</span>
                `;
                container.appendChild(header);

                grouped[date].forEach(c => {
                    const card = document.createElement('div');
                    card.className = `chq-card chq-${c.type}`;
                    
                    card.innerHTML = `
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <small class="text-uppercase text-muted fw-bold" style="font-size:0.65rem;">${c.type} To/From</small><br>
                                <span class="fw-bold">${c.payee_payer_name}</span>
                            </div>
                            <div class="col-md-3">
                                <span class="fw-monospace">${c.bank_name || 'Bank N/A'} - <span class="text-primary">${c.cheque_number}</span></span>
                            </div>
                            <div class="col-md-2 text-center">
                                <span class="status-${c.status}">${c.status}</span>
                            </div>
                            <div class="col-md-2 text-end">
                                <span class="fw-bold fs-5">Rs. ${parseFloat(c.amount).toLocaleString('en-LK', {minimumFractionDigits:2})}</span>
                            </div>
                            <div class="col-md-2 text-end">
                                <button class="btn btn-sm btn-light text-primary" onclick='editCheque(${JSON.stringify(c)})'><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-light text-danger" onclick="deleteCheque(${c.cheque_id})"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    `;
                    container.appendChild(card);
                });
            });
        }

        function openModal() {
            document.getElementById('chequeForm').reset();
            document.getElementById('chqId').value = '';
            document.getElementById('chqDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('modalTitle').textContent = 'Register New Cheque';
            chequeModal.show();
        }

        window.editCheque = function(c) {
            document.getElementById('chqId').value = c.cheque_id;
            document.getElementById('chqType').value = c.type;
            document.getElementById('chqPayee').value = c.payee_payer_name;
            document.getElementById('chqBank').value = c.bank_name;
            document.getElementById('chqNumber').value = c.cheque_number;
            document.getElementById('chqDate').value = c.banking_date;
            document.getElementById('chqAmount').value = c.amount;
            document.getElementById('chqStatus').value = c.status;
            document.getElementById('modalTitle').textContent = 'Edit Cheque';
            chequeModal.show();
        }

        document.getElementById('chequeForm').onsubmit = async (e) => {
            e.preventDefault();
            const payload = {
                cheque_id: document.getElementById('chqId').value,
                type: document.getElementById('chqType').value,
                payee_payer_name: document.getElementById('chqPayee').value,
                bank_name: document.getElementById('chqBank').value,
                cheque_number: document.getElementById('chqNumber').value,
                banking_date: document.getElementById('chqDate').value,
                amount: document.getElementById('chqAmount').value,
                status: document.getElementById('chqStatus').value
            };

            try {
                const res = await fetch('cheques-management.php?action=saveCheque', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(data.success) {
                    showAlert(data.message, 'success');
                    chequeModal.hide();
                    fetchCheques();
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch(e) { showAlert('Error saving cheque.', 'danger'); }
        }

        window.deleteCheque = async function(id) {
            if(!confirm("Are you sure you want to delete this cheque?")) return;
            try {
                const res = await fetch('cheques-management.php?action=deleteCheque', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cheque_id: id })
                });
                const data = await res.json();
                if(data.success) {
                    showAlert('Cheque deleted.', 'success');
                    fetchCheques();
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch(e) { showAlert('Error deleting cheque.', 'danger'); }
        }

        fetchCheques();
    </script>
</body>
</html>
