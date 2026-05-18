<?php
// categories.php
// Category Management for Nelun POS
// Handles fetching, adding, updating, and deleting product categories.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once 'db_connection.php';

// Check if request is API (JSON) or standard HTML
$is_api = (isset($_GET['action']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false));

if ($is_api) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? null;
    $method = $_SERVER['REQUEST_METHOD'];

    $input = [];
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
    }

    // 1. GET ALL CATEGORIES
    if ($action === 'getCategories') {
        try {
            $stmt = $pdo->query("
                SELECT c.*, COUNT(p.product_id) as product_count 
                FROM categories c 
                LEFT JOIN Products p ON c.category_id = p.category_id 
                GROUP BY c.category_id 
                ORDER BY c.name ASC
            ");
            echo json_encode(["success" => true, "categories" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
        exit();
    }

    // 2. SAVE/UPDATE CATEGORY
    if ($action === 'saveCategory') {
        $id = $input['category_id'] ?? null;
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');

        if (empty($name)) {
            echo json_encode(["success" => false, "message" => "Category name is required."]);
            exit();
        }

        try {
            if ($id) {
                // Update
                $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE category_id = ?");
                $stmt->execute([$name, $description, $id]);
                echo json_encode(["success" => true, "message" => "Category updated successfully."]);
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $description]);
                echo json_encode(["success" => true, "message" => "Category added successfully."]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                echo json_encode(["success" => false, "message" => "A category with this name already exists."]);
            } else {
                echo json_encode(["success" => false, "message" => "Database Error: " . $e->getMessage()]);
            }
        }
        exit();
    }

    // 3. DELETE CATEGORY
    if ($action === 'deleteCategory') {
        $id = $input['category_id'] ?? null;
        try {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
            $stmt->execute([$id]);
            echo json_encode(["success" => true, "message" => "Category deleted."]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "Failed to delete: " . $e->getMessage()]);
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
    <title>Category Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; padding: 20px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .table th { font-weight: 600; color: #6c757d; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
        .category-badge { background-color: rgba(0, 122, 255, 0.1); color: #007AFF; font-weight: 600; }
    </style>
</head>
<body>

    <div id="alertContainer"></div>

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-0">Category Management</h2>
                <p class="text-muted small mb-0">Organize your shop products into clean categories</p>
            </div>
            <button class="btn btn-primary shadow-sm px-4 rounded-pill" onclick="openCategoryModal()">
                <i class="bi bi-plus-lg"></i> Add Category
            </button>
        </div>

        <div class="card border-0 overflow-hidden">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                <h6 class="mb-0 fw-bold">All Product Categories (<span id="countDisplay">0</span>)</h6>
                <div class="input-group input-group-sm" style="width: 250px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" id="searchBox" class="form-control border-start-0" placeholder="Search categories..." onkeyup="filterCategories()">
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Category Name</th>
                            <th>Description</th>
                            <th class="text-center">Active Products</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="categoryTableBody">
                        <tr><td colspan="4" class="text-center py-5 text-muted">Loading categories...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Category Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius:20px">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="modal-title fw-bold" id="modalTitle">Record Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="categoryForm">
                        <input type="hidden" id="inputId">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Category Name</label>
                            <input type="text" id="inputName" class="form-control" placeholder="e.g. Ballpoint Pens" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Description</label>
                            <textarea id="inputDescription" class="form-control" rows="3" placeholder="Optional description..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="categoryForm" class="btn btn-primary rounded-pill px-4">Save Category</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));
        let categories = [];

        async function fetchCategories() {
            try {
                const res = await fetch('categories.php?action=getCategories');
                const data = await res.json();
                if(data.success) {
                    categories = data.categories;
                    renderCategories(categories);
                } else {
                    showAlert('Error: ' + data.message, 'danger');
                }
            } catch(e) {
                showAlert('Error loading categories.', 'danger');
            }
        }

        function renderCategories(list) {
            const tbody = document.getElementById('categoryTableBody');
            tbody.innerHTML = '';
            
            if(list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-5 text-muted">No categories found.</td></tr>';
                document.getElementById('countDisplay').textContent = 0;
                return;
            }

            list.forEach(c => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="ps-4 fw-bold text-dark">${c.name}</td>
                    <td class="text-muted small">${c.description || '-'}</td>
                    <td class="text-center"><span class="badge category-badge px-2.5 py-1.5 rounded">${c.product_count} items</span></td>
                    <td class="text-center">
                        <div class="btn-group">
                            <button class="btn btn-sm btn-link text-primary" onclick='editCategory(${JSON.stringify(c)})'><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-link text-danger" onclick="deleteCategory(${c.category_id})"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
            document.getElementById('countDisplay').textContent = list.length;
        }

        function filterCategories() {
            const query = document.getElementById('searchBox').value.toLowerCase();
            const filtered = categories.filter(c => 
                c.name.toLowerCase().includes(query) || 
                (c.description && c.description.toLowerCase().includes(query))
            );
            renderCategories(filtered);
        }

        function openCategoryModal() {
            document.getElementById('categoryForm').reset();
            document.getElementById('inputId').value = '';
            document.getElementById('modalTitle').textContent = "Create New Category";
            categoryModal.show();
        }

        function editCategory(c) {
            document.getElementById('inputId').value = c.category_id;
            document.getElementById('inputName').value = c.name;
            document.getElementById('inputDescription').value = c.description || '';
            document.getElementById('modalTitle').textContent = "Update Category Details";
            categoryModal.show();
        }

        document.getElementById('categoryForm').onsubmit = async (e) => {
            e.preventDefault();
            const payload = {
                category_id: document.getElementById('inputId').value,
                name: document.getElementById('inputName').value,
                description: document.getElementById('inputDescription').value
            };

            try {
                const res = await fetch('categories.php?action=saveCategory', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(data.success) {
                    showAlert(data.message, 'success');
                    categoryModal.hide();
                    fetchCategories();
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch(e) {
                showAlert('Network error saving category.', 'danger');
            }
        };

        async function deleteCategory(id) {
            if(!confirm('Permanently delete this category? This will not delete the products inside, but their category will be unassigned.')) return;
            try {
                const res = await fetch('categories.php?action=deleteCategory', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ category_id: id })
                });
                const data = await res.json();
                if(data.success) {
                    showAlert('Category deleted successfully.', 'warning');
                    fetchCategories();
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch(e) {
                showAlert('Network error.', 'danger');
            }
        }

        function showAlert(msg, type) {
            const div = document.createElement('div');
            div.className = `alert alert-${type} alert-dismissible fade show shadow fixed-top m-3`;
            div.style.zIndex = 2000;
            div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.getElementById('alertContainer').appendChild(div);
            setTimeout(() => div.remove(), 3000);
        }

        document.addEventListener('DOMContentLoaded', fetchCategories);
    </script>
</body>
</html>
