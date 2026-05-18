<?php
// expenses.php
// Expense Management for Nelun POS
// Handles fetching, adding, updating, deleting, and exporting expenses.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once 'db_connection.php';

// --- ACTION HANDLER ---
$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// Handle JSON Input for POST requests
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
}

// 1. GET EXPENSES (API)
if ($action === 'getExpenses') {
    header('Content-Type: application/json');
    $startDate = $_GET['start'] ?? date('Y-m-01');
    $endDate = $_GET['end'] ?? date('Y-m-d');
    $search = $_GET['search'] ?? '';
    $category = $_GET['category'] ?? 'All';

    try {
        $sql = "
            SELECT e.*, u.username 
            FROM expenses e 
            LEFT JOIN users u ON e.user_id = u.user_id 
            WHERE e.expense_date BETWEEN ? AND ?
        ";
        
        $params = [$startDate, $endDate];

        if (!empty($search)) {
            $sql .= " AND e.description LIKE ?";
            $params[] = "%$search%";
        }

        if ($category !== 'All') {
            $sql .= " AND e.category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY e.expense_date DESC, e.expense_time DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(["success" => true, "expenses" => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit();
}

// 2. SAVE/UPDATE EXPENSE (API)
if ($action === 'saveExpense') {
    header('Content-Type: application/json');
    
    $expenseId = $input['expense_id'] ?? null;
    $category = $input['category'] ?? 'General';
    $description = $input['description'] ?? '';
    $amount = $input['amount'] ?? 0;
    $date = $input['expense_date'] ?? date('Y-m-d');
    $time = date('H:i:s');
    $userId = $input['user_id'] ?? null;

    try {
        // --- CRITICAL FIX: Foreign Key Validation ---
        $checkUser = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
        $checkUser->execute([$userId]);
        $exists = $checkUser->fetchColumn();

        if (!$exists) {
            $fallback = $pdo->query("SELECT user_id FROM users ORDER BY user_id ASC LIMIT 1");
            $userId = $fallback->fetchColumn();
            
            if (!$userId) {
                echo json_encode(["success" => false, "message" => "No users found in database. Please create a user first."]);
                exit();
            }
        }

        if ($expenseId) {
            // Update
            $stmt = $pdo->prepare("UPDATE expenses SET category=?, description=?, amount=?, expense_date=?, user_id=? WHERE expense_id=?");
            $stmt->execute([$category, $description, $amount, $date, $userId, $expenseId]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO expenses (category, description, amount, expense_date, expense_time, user_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$category, $description, $amount, $date, $time, $userId]);
        }

        echo json_encode(["success" => true, "message" => "Expense saved."]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Database Error: " . $e->getMessage()]);
    }
    exit();
}

// 3. DELETE EXPENSE (API)
if ($action === 'deleteExpense') {
    header('Content-Type: application/json');
    $expenseId = $input['expense_id'] ?? null;
    try {
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE expense_id = ?");
        $stmt->execute([$expenseId]);
        echo json_encode(["success" => true]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit();
}

// 4. EXPORT CSV
if ($action === 'export_csv') {
    $start = $_GET['start'] ?? date('Y-m-01');
    $end = $_GET['end'] ?? date('Y-m-d');
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Expenses_'.$start.'_to_'.$end.'.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Time', 'Category', 'Description', 'Amount', 'User']);

    $stmt = $pdo->prepare("
        SELECT e.expense_id, e.expense_date, e.expense_time, e.category, e.description, e.amount, u.username 
        FROM expenses e 
        LEFT JOIN users u ON e.user_id = u.user_id 
        WHERE e.expense_date BETWEEN ? AND ?
        ORDER BY e.expense_date DESC
    ");
    $stmt->execute([$start, $end]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; padding: 20px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .table th { font-weight: 600; color: #6c757d; text-transform: uppercase; font-size: 0.8rem; }
        
        /* Category Card Styles */
        .cat-card {
            background: white;
            border-radius: 15px;
            padding: 15px;
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.2s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .cat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
            border-color: #007AFF;
        }
        .cat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            background: #F2F2F7;
            color: #007AFF;
            font-size: 1.2rem;
        }
        .cat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #8E8E93;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .cat-value {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1C1C1E;
        }
        
        /* Summary Accent */
        .summary-main {
            background: linear-gradient(135deg, #1C1C1E 0%, #3A3A3C 100%);
            color: white;
        }
    </style>
</head>
<body>

    <div id="alertContainer"></div>

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-0">Expense Analytics</h2>
                <p class="text-muted small mb-0">Track and manage your business outgoings</p>
            </div>
            <button class="btn btn-primary shadow-sm px-4 rounded-pill" onclick="openExpenseModal()">
                <i class="bi bi-plus-lg"></i> Add Expense
            </button>
        </div>

        <!-- Dynamic Category Cards Row -->
        <div id="categoryCardsRow" class="row g-3 mb-4">
            <!-- Loading state or JS injected cards -->
        </div>

        <!-- Period Totals & Filters -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card p-3 summary-main h-100">
                    <small class="text-uppercase fw-bold opacity-75" style="font-size: 0.7rem; letter-spacing: 1px;">Period Grand Total</small>
                    <h2 class="fw-bold mb-0 mt-1">Rs. <span id="totalExpenseDisplay">0.00</span></h2>
                </div>
            </div>
            <div class="col-lg-9 col-md-6">
                <div class="card p-3 h-100">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small text-muted fw-bold">From</label>
                            <input type="date" id="startDate" class="form-control form-control-sm" value="<?= date('Y-m-01') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted fw-bold">To</label>
                            <input type="date" id="endDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted fw-bold">Filter Category</label>
                            <select id="filterCategory" class="form-select form-select-sm">
                                <option value="All">All Categories</option>
                                <option value="Electricity">Electricity</option>
                                <option value="Water">Water</option>
                                <option value="Rent">Rent</option>
                                <option value="Salary">Salary</option>
                                <option value="Stationery">Stationery</option>
                                <option value="Meals">Meals</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button class="btn btn-dark btn-sm w-100" onclick="fetchExpenses()">Apply Filters</button>
                            <button class="btn btn-outline-success btn-sm" onclick="exportCSV()" title="Export CSV"><i class="bi bi-file-earmark-spreadsheet"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="card border-0 overflow-hidden">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                <h6 class="mb-0 fw-bold">Transaction History (<span id="countDisplay">0</span>)</h6>
                <div class="input-group input-group-sm" style="width: 250px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" id="searchDesc" class="form-control border-start-0" placeholder="Search description..." onkeyup="fetchExpenses()">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Date & Time</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Cashier</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="expenseTableBody">
                        <!-- Loaded via JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="expenseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold" id="modalTitle">Record Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="expenseForm">
                        <input type="hidden" id="inputId">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Category</label>
                            <div class="input-group">
                                <select id="inputCategory" class="form-select" required>
                                    <option value="General">General</option>
                                    <option value="Electricity">Electricity</option>
                                    <option value="Water">Water</option>
                                    <option value="Rent">Rent</option>
                                    <option value="Salary">Salary</option>
                                    <option value="Stationery">Stationery</option>
                                    <option value="Meals">Meals</option>
                                    <option value="Maintenance">Maintenance</option>
                                    <option value="Other">Other</option>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" onclick="addNewCategoryPrompt()">+</button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Description</label>
                            <input type="text" id="inputDescription" class="form-control" placeholder="e.g. Monthly Shop Rent" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Amount (Rs.)</label>
                                <input type="number" id="inputAmount" class="form-control fw-bold fs-5" step="0.01" min="0.01" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Date</label>
                                <input type="date" id="inputDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="expenseForm" class="btn btn-primary rounded-pill px-4">Save Entry</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const USER_ID = localStorage.getItem('user_id');
        const expenseModal = new bootstrap.Modal(document.getElementById('expenseModal'));
        let currentExpenses = [];

        // Category Icon Mapping
        const CAT_ICONS = {
            'Electricity': 'bi-lightning-charge',
            'Water': 'bi-droplet',
            'Rent': 'bi-house-door',
            'Salary': 'bi-cash-stack',
            'Stationery': 'bi-pencil-square',
            'Meals': 'bi-egg-fried',
            'Maintenance': 'bi-tools',
            'General': 'bi-card-list',
            'Other': 'bi-wallet2'
        };

        async function fetchExpenses() {
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            const search = document.getElementById('searchDesc').value;
            const cat = document.getElementById('filterCategory').value;

            try {
                const res = await fetch(`expenses.php?action=getExpenses&start=${start}&end=${end}&search=${encodeURIComponent(search)}&category=${cat}`);
                const data = await res.json();
                
                if(data.success) {
                    currentExpenses = data.expenses;
                    renderView();
                }
            } catch(e) { showAlert('Error connecting to server.', 'danger'); }
        }

        function renderView() {
            const tbody = document.getElementById('expenseTableBody');
            const catRow = document.getElementById('categoryCardsRow');
            tbody.innerHTML = '';
            catRow.innerHTML = '';
            
            let total = 0;
            const categoryTotals = {};

            if(currentExpenses.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">No records found.</td></tr>';
            }

            currentExpenses.forEach(e => {
                const amt = parseFloat(e.amount);
                total += amt;
                
                // Aggregate category data
                categoryTotals[e.category] = (categoryTotals[e.category] || 0) + amt;

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="ps-4">
                        <div class="fw-bold text-dark">${e.expense_date}</div>
                        <small class="text-muted" style="font-size:0.7rem">${e.expense_time}</small>
                    </td>
                    <td><span class="badge bg-light text-dark border">${e.category}</span></td>
                    <td>${e.description}</td>
                    <td><small class="text-muted fw-medium">${e.username || 'System'}</small></td>
                    <td class="text-end fw-bold">Rs. ${amt.toFixed(2)}</td>
                    <td class="text-center">
                        <div class="btn-group">
                            <button class="btn btn-sm btn-link text-primary" onclick='editExpense(${JSON.stringify(e)})'><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-link text-danger" onclick="deleteExpense(${e.expense_id})"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });

            // Render Category Cards
            Object.keys(categoryTotals).sort().forEach(cat => {
                const catAmt = categoryTotals[cat];
                const icon = CAT_ICONS[cat] || CAT_ICONS['Other'];
                
                const col = document.createElement('div');
                col.className = 'col-6 col-md-4 col-lg-2';
                col.innerHTML = `
                    <div class="cat-card">
                        <div class="cat-icon"><i class="bi ${icon}"></i></div>
                        <div class="cat-label">${cat}</div>
                        <div class="cat-value">Rs. ${catAmt.toLocaleString('en-LK', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}</div>
                    </div>
                `;
                catRow.appendChild(col);
            });

            document.getElementById('totalExpenseDisplay').textContent = total.toLocaleString('en-LK', { minimumFractionDigits: 2 });
            document.getElementById('countDisplay').textContent = currentExpenses.length;
        }

        function openExpenseModal() {
            document.getElementById('expenseForm').reset();
            document.getElementById('inputId').value = '';
            document.getElementById('modalTitle').textContent = "Record New Expense";
            document.getElementById('inputDate').value = new Date().toISOString().split('T')[0];
            expenseModal.show();
        }

        function editExpense(e) {
            document.getElementById('inputId').value = e.expense_id;
            document.getElementById('inputCategory').value = e.category;
            document.getElementById('inputDescription').value = e.description;
            document.getElementById('inputAmount').value = e.amount;
            document.getElementById('inputDate').value = e.expense_date;
            document.getElementById('modalTitle').textContent = "Update Expense Entry";
            expenseModal.show();
        }

        document.getElementById('expenseForm').onsubmit = async (e) => {
            e.preventDefault();
            const payload = {
                expense_id: document.getElementById('inputId').value,
                category: document.getElementById('inputCategory').value,
                description: document.getElementById('inputDescription').value,
                amount: document.getElementById('inputAmount').value,
                expense_date: document.getElementById('inputDate').value,
                user_id: USER_ID || 1
            };

            try {
                const res = await fetch('expenses.php?action=saveExpense', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(data.success) {
                    showAlert('Record saved successfully.', 'success');
                    expenseModal.hide();
                    fetchExpenses();
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch(e) { showAlert('Network error', 'danger'); }
        };

        async function deleteExpense(id) {
            if(!confirm('Permanently delete this record?')) return;
            try {
                const res = await fetch('expenses.php?action=deleteExpense', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ expense_id: id })
                });
                const data = await res.json();
                if(data.success) {
                    showAlert('Record deleted.', 'warning');
                    fetchExpenses();
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch(e) { showAlert('Network error', 'danger'); }
        }

        window.addNewCategoryPrompt = function() {
            const newCat = prompt("Enter new category name:");
            if(newCat && newCat.trim() !== "") {
                const select = document.getElementById('inputCategory');
                const opt = new Option(newCat.trim(), newCat.trim());
                select.add(opt);
                select.value = newCat.trim();
            }
        }

        function exportCSV() {
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            window.location.href = `expenses.php?action=export_csv&start=${start}&end=${end}`;
        }

        function showAlert(msg, type) {
            const div = document.createElement('div');
            div.className = `alert alert-${type} alert-dismissible fade show shadow fixed-top m-3`;
            div.style.zIndex = 2000;
            div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.getElementById('alertContainer').appendChild(div);
            setTimeout(() => div.remove(), 3000);
        }

        document.addEventListener('DOMContentLoaded', fetchExpenses);
    </script>
</body>
</html>