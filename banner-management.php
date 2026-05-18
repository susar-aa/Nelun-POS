<?php
// banner-management.php
header('Access-Control-Allow-Origin: *');

// Configuration
$uploadDir = 'banners/';
$allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// Ensure directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// --- HANDLE ACTIONS ---
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Upload Image
    if ($action === 'upload' && isset($_FILES['bannerFile'])) {
        $file = $_FILES['bannerFile'];
        $fileName = basename($file['name']);
        $targetPath = $uploadDir . $fileName;
        $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));

        if (in_array($fileType, $allowedTypes)) {
            if ($file['size'] < 5000000) { // 5MB Limit
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $message = "Banner uploaded successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error moving file.";
                    $messageType = "danger";
                }
            } else {
                $message = "File is too large (Max 5MB).";
                $messageType = "warning";
            }
        } else {
            $message = "Invalid file type. Only JPG, PNG, GIF, WEBP allowed.";
            $messageType = "danger";
        }
    }

    // 2. Delete Image
    if ($action === 'delete' && isset($_POST['filename'])) {
        $filename = basename($_POST['filename']); // Security: basename prevents directory traversal
        $filePath = $uploadDir . $filename;

        if (file_exists($filePath)) {
            unlink($filePath);
            $message = "Banner deleted.";
            $messageType = "success";
        } else {
            $message = "File not found.";
            $messageType = "danger";
        }
    }
}

// --- GET EXISTING IMAGES ---
$images = [];
if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedTypes)) {
                $images[] = $file;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banner Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; padding: 20px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .banner-card { position: relative; overflow: hidden; border-radius: 10px; transition: transform 0.2s; }
        .banner-card:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.1); }
        .banner-img { width: 100%; height: 180px; object-fit: cover; display: block; }
        .delete-btn { 
            position: absolute; top: 10px; right: 10px; 
            background: rgba(220, 53, 69, 0.9); color: white; 
            border: none; padding: 5px 10px; border-radius: 6px; 
            opacity: 0; transition: opacity 0.2s; 
        }
        .banner-card:hover .delete-btn { opacity: 1; }
        .upload-area { border: 2px dashed #dee2e6; border-radius: 12px; padding: 30px; text-align: center; background: #fff; cursor: pointer; transition: 0.2s; }
        .upload-area:hover { border-color: #0d6efd; background: #f8f9fa; }
        .file-input { display: none; }
    </style>
</head>
<body>

    <div class="container" style="max-width: 900px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold m-0"><i class="bi bi-images me-2 text-primary"></i>Banner Management</h3>
                <p class="text-muted m-0">Manage screensaver images for Live Monitor</p>
            </div>
            <a href="dashboard.html" class="btn btn-outline-secondary">Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Upload Section -->
        <div class="card mb-4">
            <div class="card-body">
                <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="bannerFile" id="bannerFile" class="file-input" accept="image/*" onchange="document.getElementById('uploadForm').submit()">
                    
                    <div class="upload-area" onclick="document.getElementById('bannerFile').click()">
                        <i class="bi bi-cloud-upload display-4 text-primary opacity-50"></i>
                        <h5 class="mt-3">Click to Upload New Banner</h5>
                        <p class="text-muted small mb-0">Supports JPG, PNG, WEBP (Max 5MB)</p>
                    </div>
                </form>
            </div>
        </div>

        <!-- Gallery Section -->
        <h5 class="fw-bold mb-3 text-secondary">Active Banners (<?= count($images) ?>)</h5>
        
        <?php if (empty($images)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-card-image display-1 opacity-25"></i>
                <p class="mt-3">No banners found. Upload one to start.</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($images as $img): ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="banner-card bg-white shadow-sm">
                            <img src="<?= $uploadDir . $img ?>" alt="Banner" class="banner-img">
                            <div class="p-2 border-top">
                                <small class="text-muted text-truncate d-block"><?= $img ?></small>
                            </div>
                            
                            <form action="" method="POST" onsubmit="return confirm('Delete this banner?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="filename" value="<?= $img ?>">
                                <button type="submit" class="delete-btn">
                                    <i class="bi bi-trash-fill"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>