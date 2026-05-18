<?php
// product-management.php
// Handles Product UI, Export, and Import Logic

require_once 'db_connection.php'; 

// --- HANDLE EXPORT (File Download) ---
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $filename = "products_export_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    // Header row
    fputcsv($output, ['ID', 'Product Name', 'Item Code', 'Barcode', 'Price', 'Cost', 'Quantity', 'Status']);

    $stmt = $pdo->query("SELECT product_id, name, item_code, product_code, price, cost, quantity, status FROM Products ORDER BY product_id DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['item_code'])) $row['item_code'] = "\t" . $row['item_code'];
        if (!empty($row['product_code'])) $row['product_code'] = "\t" . $row['product_code'];
        $row['status'] = $row['status'] ?? 'Active';
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// --- HANDLE IMPORT (CSV Upload) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload failed.']);
        exit();
    }

    $file = $_FILES['csvFile']['tmp_name'];
    $handle = fopen($file, "r");
    
    if ($handle === FALSE) {
        echo json_encode(['success' => false, 'message' => 'Cannot open file.']);
        exit();
    }

    $imported = 0;
    $updated = 0;

    try {
        $pdo->beginTransaction();
        
        $sql = "INSERT INTO Products (product_id, name, item_code, product_code, price, cost, quantity, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                name=VALUES(name), item_code=VALUES(item_code), product_code=VALUES(product_code), 
                price=VALUES(price), cost=VALUES(cost), quantity=VALUES(quantity), status=VALUES(status)";
        
        $stmt = $pdo->prepare($sql);
        fgetcsv($handle); // Skip Header

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $id = !empty($data[0]) ? (int)$data[0] : null; 
            $name = trim($data[1]);
            $itemCode = isset($data[2]) ? trim(str_replace(["\t", '"'], '', $data[2])) : '';
            $barcode = isset($data[3]) ? trim(str_replace(["\t", '"'], '', $data[3])) : '';
            $price = isset($data[4]) ? (float)str_replace(',', '', trim($data[4])) : 0;
            $cost = isset($data[5]) ? (float)str_replace(',', '', trim($data[5])) : 0;
            $qty = isset($data[6]) ? (int)$data[6] : 0;
            $status = isset($data[7]) ? trim($data[7]) : 'Active';

            if (empty($name)) continue; 

            $stmt->execute([$id, $name, $itemCode, $barcode, $price, $cost, $qty, $status]);
            
            if ($stmt->rowCount() > 0) {
                if ($id) $updated++; else $imported++;
            }
        }

        $pdo->commit();
        fclose($handle);
        echo json_encode(['success' => true, 'message' => "Import Successful! Added: $imported, Updated: $updated"]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f0f2f5; 
            padding-bottom: 80px; 
        }
        
        .header-sticky {
            position: sticky;
            top: 0;
            z-index: 1020;
            background: #f0f2f5;
            padding-top: 15px;
            padding-bottom: 10px;
        }

        .card { 
            border: none; 
            border-radius: 12px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
        }

        /* Responsive View Controls */
        @media (max-width: 767.98px) {
            #desktopView { display: none !important; }
            #mobileView { display: block !important; }
            .btn-text-responsive { display: none; }
            .filter-row { flex-direction: column; gap: 10px; }
            .filter-row .input-group, .filter-row select { width: 100%; }
        }
        @media (min-width: 768px) {
            #desktopView { display: block !important; }
            #mobileView { display: none !important; }
            .btn-text-responsive { display: inline; }
        }

        /* Table & Card Styles */
        .table-custom th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }
        .table-custom td { vertical-align: middle; font-size: 0.95rem; }
        
        .mobile-product-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
        }
        
        .mobile-main-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .mobile-left { flex: 1; padding-right: 10px; }
        .mobile-right { text-align: right; display: flex; flex-direction: column; align-items: flex-end; min-width: 100px; }

        .prod-name-mobile { font-size: 1rem; font-weight: 600; color: #212529; margin-bottom: 6px; line-height: 1.3; }
        .prod-price-mobile { font-size: 1.15rem; font-weight: 700; color: #0d6efd; }
        .meta-code { font-family: monospace; color: #6c757d; background: #f8f9fa; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; display: inline-block; margin-top: 6px; border: 1px solid #dee2e6; }

        .mobile-actions {
            margin-top: 12px; padding-top: 10px; border-top: 1px dashed #dee2e6; display: flex; gap: 10px;
        }
        .mobile-actions .btn { flex: 1; border-radius: 8px; font-size: 0.9rem; }

        .badge-stock { font-size: 0.75rem; padding: 5px 8px; border-radius: 6px; font-weight: 500; }
        .bg-low-stock { background-color: #ffc107; color: #000; }
        .bg-out-stock { background-color: #dc3545; color: #fff; }
        .bg-good-stock { background-color: #198754; color: #fff; }

        .form-check-input:checked { background-color: #198754; border-color: #198754; }
        .status-label { font-size: 0.75rem; width: 50px; display: inline-block; }
        .hidden-elm { display: none !important; }

        .pagination-container { margin-top: 20px; display: flex; justify-content: space-between; align-items: center; }
        
        .filter-row { display: flex; gap: 10px; margin-bottom: 10px; }
    </style>
</head>
<body>

    <div class="container-fluid px-2 px-lg-5">
        
        <!-- Header Section -->
        <div class="header-sticky">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="fw-bold text-dark m-0"><i class="bi bi-box-seam me-2"></i>Products</h4>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm admin-only" onclick="document.getElementById('importFile').click()">
                        <i class="bi bi-upload"></i> <span class="btn-text-responsive">Import</span>
                    </button>
                    <input type="file" id="importFile" accept=".csv" style="display: none;" onchange="uploadCSV(this)">

                    <button class="btn btn-outline-success btn-sm" onclick="window.location.href='?action=export_csv'">
                        <i class="bi bi-download"></i> <span class="btn-text-responsive">Export</span>
                    </button>
                    
                    <button class="btn btn-primary btn-sm admin-only" onclick="openAddModal()">
                        <i class="bi bi-plus-lg"></i> <span class="btn-text-responsive">New Product</span>
                    </button>
                </div>
            </div>

            <!-- Enhanced Search & Filter Bar -->
            <div class="card p-2 mb-2">
                <div class="filter-row align-items-center">
                    <div class="input-group" style="flex: 2;">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0 ps-0 shadow-none" id="searchInput" placeholder="Search by Name, Code or Barcode...">
                    </div>
                    
                    <select class="form-select" id="statusFilter" style="flex: 1; max-width: 130px;">
                        <option value="All">All Status</option>
                        <option value="Active" selected>Active Only</option>
                        <option value="Inactive">Inactive Only</option>
                    </select>

                    <select class="form-select" id="stockFilter" style="flex: 1; max-width: 130px;">
                        <option value="All" selected>All Stock</option>
                        <option value="Low">Low Stock</option>
                        <option value="Out">Out of Stock</option>
                    </select>

                    <select class="form-select" id="catFilter" style="flex: 1; max-width: 160px;">
                        <option value="All" selected>All Categories</option>
                    </select>

                    <select class="form-select" id="supFilter" style="flex: 1; max-width: 160px;">
                        <option value="All" selected>All Suppliers</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <!-- Loading State -->
        <div id="loadingSpinner" class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="text-muted mt-2">Loading inventory...</p>
        </div>

        <!-- DESKTOP VIEW -->
        <div class="card" id="desktopView">
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Details</th>
                            <th>Item Code</th>
                            <th>Barcode</th>
                            <th>Status</th>
                            <th class="text-center">Stock</th>
                            <th class="text-end">Price</th>
                            <th class="text-end cost-column">Cost</th>
                            <th class="text-center action-column">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="productTableBody"></tbody>
                </table>
            </div>
        </div>

        <!-- MOBILE VIEW -->
        <div id="mobileView"></div>

        <!-- Empty State -->
        <div id="emptyState" class="text-center py-5 d-none">
            <i class="bi bi-inbox display-1 text-muted opacity-25"></i>
            <p class="mt-3 text-muted">No products found matching filters.</p>
        </div>
        
        <!-- Pagination -->
        <div class="pagination-container d-none" id="paginationControls">
            <button class="btn btn-outline-secondary btn-sm" id="prevPageBtn" disabled>
                <i class="bi bi-chevron-left"></i> Prev
            </button>
            <span class="text-muted small fw-bold" id="pageInfo">Page 1</span>
            <button class="btn btn-outline-secondary btn-sm" id="nextPageBtn" disabled>
                Next <i class="bi bi-chevron-right"></i>
            </button>
        </div>

    </div>

    <!-- Add/Edit Modal -->
    <div class="modal fade" id="productModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="productForm">
                        <input type="hidden" id="productId">
                        <div class="mb-3">
                            <label class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="prodName" required>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Item Code</label>
                                <input type="text" class="form-control" id="prodItemCode">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Barcode</label>
                                <input type="text" class="form-control" id="prodCode" placeholder="Auto">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Price</label>
                                <input type="number" class="form-control" id="prodPrice" step="0.01" required>
                            </div>
                            <div class="col-6 mb-3 cost-field-modal">
                                <label class="form-label">Cost</label>
                                <input type="number" class="form-control" id="prodCost" step="0.01">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Category</label>
                                <select id="prodCategory" class="form-select">
                                    <option value="">-- No Category --</option>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Supplier</label>
                                <select id="prodSupplier" class="form-select">
                                    <option value="">-- No Supplier --</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-4 mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="prodQty" required>
                            </div>
                            <div class="col-4 mb-3">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" class="form-control" id="prodReorder" value="10" required>
                            </div>
                            <div class="col-4 mb-3">
                                <label class="form-label">Status</label>
                                <select id="prodStatus" class="form-select">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div id="formMessage" class="text-danger small mb-2"></div>
                        <button type="submit" class="btn btn-primary w-100">Save Product</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Barcode Print Modal -->
    <div class="modal fade" id="barcodeModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Print Barcode</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product Name</label>
                        <input type="text" class="form-control" id="printName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Barcode</label>
                        <input type="text" class="form-control" id="printCode" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity to Print</label>
                        <input type="number" class="form-control" id="printQty" value="1" min="1">
                    </div>
                    <button class="btn btn-success w-100" onclick="confirmPrint()">Print Now</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JS Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let currentPage = 1;
        const itemsPerPage = 20;
        let totalPages = 1;
        let searchTimeout = null;
        let productsMap = {}; 

        const productModal = new bootstrap.Modal(document.getElementById('productModal'));
        const barcodeModal = new bootstrap.Modal(document.getElementById('barcodeModal'));
        
        const userRole = localStorage.getItem('role') || 'Branch_User'; 
        const isCashier = (userRole.toLowerCase() !== 'admin'); // Admins get full access, others view-only

        let categories = [];
        let suppliers = [];

        async function loadMetadata() {
            try {
                // Load Categories
                const resCat = await fetch('categories.php?action=getCategories');
                const dataCat = await resCat.json();
                if (dataCat.success) {
                    categories = dataCat.categories;
                    const filter = document.getElementById('catFilter');
                    const select = document.getElementById('prodCategory');
                    categories.forEach(c => {
                        filter.add(new Option(c.name, c.category_id));
                        select.add(new Option(c.name, c.category_id));
                    });
                }

                // Load Suppliers
                const resSup = await fetch('suppliers.php?action=getSuppliers');
                const dataSup = await resSup.json();
                if (dataSup.success) {
                    suppliers = dataSup.suppliers;
                    const filter = document.getElementById('supFilter');
                    const select = document.getElementById('prodSupplier');
                    suppliers.forEach(s => {
                        filter.add(new Option(s.name, s.supplier_id));
                        select.add(new Option(s.name, s.supplier_id));
                    });
                }
            } catch (e) { console.error('Error loading categories and suppliers: ', e); }
        }

        document.addEventListener('DOMContentLoaded', async () => {
            await loadMetadata();
            fetchProducts();
            
            if (isCashier) {
                document.querySelectorAll('.cost-column').forEach(el => el.classList.add('hidden-elm'));
                document.querySelectorAll('.action-column').forEach(el => el.classList.add('hidden-elm'));
                document.querySelectorAll('.cost-field-modal').forEach(el => el.classList.add('hidden-elm'));
                document.querySelectorAll('.admin-only').forEach(el => el.classList.add('hidden-elm'));
            }

            document.getElementById('prevPageBtn').addEventListener('click', () => {
                if(currentPage > 1) { currentPage--; fetchProducts(); }
            });
            document.getElementById('nextPageBtn').addEventListener('click', () => {
                if(currentPage < totalPages) { currentPage++; fetchProducts(); }
            });
        });

        // --- FILTER EVENTS ---
        document.getElementById('searchInput').addEventListener('input', debounceSearch);
        document.getElementById('statusFilter').addEventListener('change', () => { currentPage = 1; fetchProducts(); });
        document.getElementById('stockFilter').addEventListener('change', () => { currentPage = 1; fetchProducts(); });
        document.getElementById('catFilter').addEventListener('change', () => { currentPage = 1; fetchProducts(); });
        document.getElementById('supFilter').addEventListener('change', () => { currentPage = 1; fetchProducts(); });

        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentPage = 1; 
                fetchProducts();
            }, 500);
        }

        async function fetchProducts() {
            document.getElementById('loadingSpinner').classList.remove('d-none');
            const searchTerm = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const stock = document.getElementById('stockFilter').value;
            const cat = document.getElementById('catFilter').value;
            const sup = document.getElementById('supFilter').value;

            try {
                // Pass filters to API
                const url = `products.php?action=getProductsPaginated&page=${currentPage}&limit=${itemsPerPage}&search=${encodeURIComponent(searchTerm)}&status=${status}&stock=${stock}&category_id=${cat}&supplier_id=${sup}`;
                const res = await fetch(url);
                const data = await res.json();
                
                if (data.success) {
                    renderProducts(data.products);
                    
                    totalPages = data.total_pages;
                    document.getElementById('pageInfo').textContent = `Page ${data.current_page} of ${data.total_pages}`;
                    document.getElementById('prevPageBtn').disabled = data.current_page <= 1;
                    document.getElementById('nextPageBtn').disabled = data.current_page >= data.total_pages;
                    
                    if(data.total_pages > 1) document.getElementById('paginationControls').classList.remove('d-none');
                    else document.getElementById('paginationControls').classList.add('d-none');
                } else {
                    showAlert('Failed to load products: ' + (data.message || 'Server Error'), 'danger');
                }
            } catch (error) {
                console.error(error);
                showAlert('Network error. Check connection.', 'danger');
            } finally {
                document.getElementById('loadingSpinner').classList.add('d-none');
            }
        }

        function renderProducts(products) {
            const tbody = document.getElementById('productTableBody');
            const mobileContainer = document.getElementById('mobileView');
            const emptyState = document.getElementById('emptyState');
            
            tbody.innerHTML = '';
            mobileContainer.innerHTML = '';
            productsMap = {}; 

            if (!products || products.length === 0) {
                emptyState.classList.remove('d-none');
                return;
            } else {
                emptyState.classList.add('d-none');
            }

            products.forEach(p => {
                productsMap[p.product_id] = p;

                const stock = parseInt(p.quantity);
                let stockBadgeClass = 'bg-good-stock';
                let stockText = 'In Stock';
                if (stock === 0) { stockBadgeClass = 'bg-out-stock'; stockText = 'Out'; }
                else if (stock <= 5) { stockBadgeClass = 'bg-low-stock'; stockText = 'Low'; }

                const isActive = p.status !== 'Inactive';
                const statusLabel = isActive ? 'Active' : 'Inactive';
                const checkedAttr = isActive ? 'checked' : '';
                const disabledAttr = isCashier ? 'disabled' : ''; 
                
                const statusSwitchHtml = `
                    <div class="form-check form-switch d-inline-block align-middle">
                        <input class="form-check-input" type="checkbox" role="switch" 
                            id="statusSwitch_${p.product_id}" 
                            ${checkedAttr} ${disabledAttr}
                            onchange="toggleProductStatus(${p.product_id}, this)">
                        <label class="form-check-label status-label text-muted ms-1" for="statusSwitch_${p.product_id}">
                            ${statusLabel}
                        </label>
                    </div>
                `;

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <span class="fw-bold text-dark">${p.name}</span><br>
                        <span class="badge bg-light text-muted border text-uppercase" style="font-size:0.65rem;">${p.category_name || 'No Category'}</span>
                    </td>
                    <td>
                        <small class="text-muted bg-light px-2 py-1 rounded border">${p.item_code || '-'}</small><br>
                        <small class="text-muted" style="font-size:0.75rem;">${p.supplier_name || ''}</small>
                    </td>
                    <td><small class="text-primary font-monospace">${p.product_code}</small></td>
                    <td>${statusSwitchHtml}</td>
                    <td class="text-center">
                        <span class="badge ${stockBadgeClass} badge-stock">${stock}</span>
                    </td>
                    <td class="text-end fw-bold">Rs. ${parseFloat(p.price).toFixed(2)}</td>
                    <td class="text-end text-muted cost-column ${isCashier ? 'hidden-elm' : ''}">
                        Rs. ${parseFloat(p.cost || 0).toFixed(2)}
                    </td>
                    <td class="text-center action-column ${isCashier ? 'hidden-elm' : ''}">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="openBarcode('${p.name}', '${p.product_code}')" title="Print Barcode"><i class="bi bi-upc-scan"></i></button>
                            <button class="btn btn-outline-primary" onclick='editProduct(${JSON.stringify(p)})' title="Edit"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-outline-danger" onclick="deleteProduct(${p.product_id})" title="Delete"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);

                const card = document.createElement('div');
                card.className = 'mobile-product-card';
                const showItemCode = p.item_code && p.item_code !== p.product_code;
                const actionsHtml = !isCashier ? `
                    <div class="mobile-actions">
                        <button class="btn btn-light btn-sm text-secondary" onclick="openBarcode('${p.name}', '${p.product_code}')"><i class="bi bi-upc-scan"></i> Print</button>
                        <button class="btn btn-light btn-sm text-primary" onclick='editProduct(${JSON.stringify(p)})'><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-light btn-sm text-danger" onclick="deleteProduct(${p.product_id})"><i class="bi bi-trash"></i> Del</button>
                    </div>` : '';

                card.innerHTML = `
                    <div class="mobile-main-row">
                        <div class="mobile-left">
                            <div class="prod-name-mobile">${p.name}</div>
                            <div class="mb-1"><span class="badge bg-light text-muted border text-uppercase" style="font-size:0.65rem;">${p.category_name || 'No Category'}</span></div>
                            <div class="prod-price-mobile">Rs. ${parseFloat(p.price).toFixed(2)}</div>
                             <div class="mt-2">${statusSwitchHtml}</div>
                        </div>
                        <div class="mobile-right">
                            <span class="badge ${stockBadgeClass} badge-stock mb-1">${stockText} (${stock})</span>
                            <span class="meta-code">${p.product_code}</span>
                            ${showItemCode ? `<small class="text-muted mt-1" style="font-size:0.75rem;">${p.item_code}</small>` : ''}
                        </div>
                    </div>
                    ${p.supplier_name ? `<div class="mt-1 small text-muted">Supplier: ${p.supplier_name}</div>` : ''}
                    ${!isCashier ? `<div class="mt-1 pt-1 border-top small text-muted">Cost: Rs. ${parseFloat(p.cost || 0).toFixed(2)}</div>` : ''}
                    ${actionsHtml}
                `;
                mobileContainer.appendChild(card);
            });
        }

        window.toggleProductStatus = async function(id, checkbox) {
            const p = productsMap[id];
            if (!p) return;
            const newStatus = checkbox.checked ? 'Active' : 'Inactive';
            const originalStatus = p.status || 'Active';
            
            const payload = { ...p, status: newStatus };

            try {
                const res = await fetch('products.php?action=updateProduct', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                
                if (data.success) {
                    p.status = newStatus;
                    checkbox.nextElementSibling.textContent = newStatus;
                    // If filtering by "Active" and we just made it "Inactive", refresh list
                    const currentFilter = document.getElementById('statusFilter').value;
                    if (currentFilter !== 'All') {
                         setTimeout(() => fetchProducts(), 500); // Small delay to let user see change
                    }
                } else {
                    throw new Error(data.message);
                }
            } catch (e) {
                console.error(e);
                showAlert('Failed to update status.', 'danger');
                checkbox.checked = !checkbox.checked;
            }
        };

        function openAddModal() {
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            document.getElementById('prodCategory').value = '';
            document.getElementById('prodSupplier').value = '';
            document.getElementById('prodReorder').value = '10';
            document.getElementById('modalTitle').textContent = 'Add Product';
            document.getElementById('prodStatus').value = 'Active'; 
            productModal.show();
        }
 
        window.editProduct = function(p) {
            document.getElementById('productId').value = p.product_id;
            document.getElementById('prodName').value = p.name;
            document.getElementById('prodItemCode').value = p.item_code || '';
            document.getElementById('prodCode').value = p.product_code;
            document.getElementById('prodPrice').value = p.price;
            document.getElementById('prodCost').value = p.cost;
            document.getElementById('prodCategory').value = p.category_id || '';
            document.getElementById('prodSupplier').value = p.supplier_id || '';
            document.getElementById('prodQty').value = p.quantity;
            document.getElementById('prodReorder').value = p.reorder_level ?? 10;
            document.getElementById('prodStatus').value = p.status || 'Active'; 
            document.getElementById('modalTitle').textContent = 'Edit Product';
            productModal.show();
        }

        document.getElementById('productForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('productId').value;
            const action = id ? 'updateProduct' : 'addProduct';
            
            const payload = {
                product_id: id,
                name: document.getElementById('prodName').value,
                item_code: document.getElementById('prodItemCode').value,
                product_code: document.getElementById('prodCode').value,
                price: document.getElementById('prodPrice').value,
                cost: document.getElementById('prodCost').value,
                category_id: document.getElementById('prodCategory').value,
                supplier_id: document.getElementById('prodSupplier').value,
                quantity: document.getElementById('prodQty').value,
                reorder_level: document.getElementById('prodReorder').value,
                status: document.getElementById('prodStatus').value 
            };

            try {
                const res = await fetch(`products.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showAlert('Product saved successfully!', 'success');
                    productModal.hide();
                    fetchProducts();
                } else {
                    document.getElementById('formMessage').textContent = data.message || 'Error saving product';
                }
            } catch (err) {
                console.error(err);
                document.getElementById('formMessage').textContent = 'Network error.';
            }
        });

        window.deleteProduct = async function(id) {
            if (!confirm('Are you sure? This will hide the product (Set Inactive).')) return;
            try {
                const res = await fetch('products.php?action=deleteProduct', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ product_id: id })
                });
                const data = await res.json();
                if(data.success) {
                    showAlert('Product set to Inactive.', 'warning');
                    fetchProducts();
                } else {
                    showAlert('Failed: ' + data.message, 'danger');
                }
            } catch (e) {
                showAlert('Network error.', 'danger');
            }
        }

        window.openBarcode = function(name, code) {
            document.getElementById('printName').value = name;
            document.getElementById('printCode').value = code;
            barcodeModal.show();
        }
        
        window.confirmPrint = function() {
            const name = document.getElementById('printName').value;
            const code = document.getElementById('printCode').value;
            const qty = document.getElementById('printQty').value;
            if(qty < 1) return;
            window.open(`print_barcode.html?name=${encodeURIComponent(name)}&code=${encodeURIComponent(code)}&qty=${qty}`, '_blank', 'width=800,height=600');
            barcodeModal.hide();
        }

        window.uploadCSV = async function(input) {
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];
            const formData = new FormData();
            formData.append('csvFile', file);
            formData.append('action', 'import_csv');
            document.getElementById('loadingSpinner').classList.remove('d-none');
            try {
                const res = await fetch('product-management.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) { showAlert(data.message, 'success'); fetchProducts(); } 
                else { showAlert(data.message || 'Import failed', 'danger'); }
            } catch (err) {
                console.error(err); showAlert('Upload Error: ' + err.message, 'danger');
            } finally {
                document.getElementById('loadingSpinner').classList.add('d-none');
                input.value = ''; 
            }
        }

        function showAlert(msg, type) {
            const div = document.createElement('div');
            div.className = `alert alert-${type} alert-dismissible fade show shadow`;
            div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.getElementById('alertContainer').innerHTML = '';
            document.getElementById('alertContainer').appendChild(div);
            setTimeout(() => div.remove(), 4000);
        }
    </script>
</body>
</html>