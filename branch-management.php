<?php
// branch-management.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Management - Nelun POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F2F2F7; }
        .card { border-radius: 16px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .table th { background-color: #f8f9fa; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; color: #6c757d; }
        .table td { vertical-align: middle; }
    </style>
</head>
<body>
<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">Branch Management</h3>
            <p class="text-muted mb-0">Manage store locations and branches</p>
        </div>
        <button class="btn btn-primary shadow-sm" onclick="openBranchModal()">
            <i class="bi bi-plus-lg me-1"></i> Add Branch
        </button>
    </div>

    <div id="alertContainer"></div>

    <div class="card p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Branch Name</th>
                        <th>Location</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody id="branchTableBody">
                    <tr><td colspan="4" class="text-center py-4">Loading branches...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Branch Modal -->
<div class="modal fade" id="branchModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="branchModalTitle">Add Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="branchForm">
                    <input type="hidden" id="branchId">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Branch Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="branchName" required placeholder="e.g., City Branch">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Location</label>
                        <input type="text" class="form-control" id="branchLocation" placeholder="e.g., Colombo">
                    </div>
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary" id="saveBranchBtn">Save Branch</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const branchModal = new bootstrap.Modal(document.getElementById('branchModal'));
    
    // Auth Check
    const role = localStorage.getItem('role') || '';
    if (role.toLowerCase() !== 'admin' && role.toLowerCase() !== 'manager') {
        document.body.innerHTML = '<div class="container mt-5"><div class="alert alert-danger shadow-sm">Access Denied: Admins Only.</div></div>';
    } else {
        loadBranches();
    }

    function showAlert(msg, type) {
        document.getElementById('alertContainer').innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show shadow-sm" role="alert">
                ${msg}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
    }

    async function loadBranches() {
        try {
            const res = await fetch('branch_api.php?action=get_branches');
            const data = await res.json();
            const tbody = document.getElementById('branchTableBody');
            tbody.innerHTML = '';
            
            if(data.success && data.branches.length > 0) {
                data.branches.forEach(b => {
                    tbody.innerHTML += `
                        <tr>
                            <td class="ps-4 fw-bold text-muted">#${b.branch_id}</td>
                            <td class="fw-bold">${b.branch_name}</td>
                            <td>${b.location || '-'}</td>
                            <td class="text-muted"><small>${b.created_at || '-'}</small></td>
                        </tr>
                    `;
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">No branches found.</td></tr>';
            }
        } catch(e) {
            showAlert('Error loading branches', 'danger');
        }
    }

    function openBranchModal() {
        document.getElementById('branchForm').reset();
        document.getElementById('branchId').value = '';
        document.getElementById('branchModalTitle').textContent = 'Add Branch';
        branchModal.show();
    }

    document.getElementById('branchForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        // Since we only have 'get_branches' and 'login' right now, a save functionality
        // would require an update to branch_api.php.
        // For now, inform the user if they try to save.
        showAlert('Branch saving functionality will be available shortly.', 'info');
        branchModal.hide();
    });
</script>
</body>
</html>
