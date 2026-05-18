<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$dir = 'banners';
$images = [];

// Ensure folder exists
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

if (is_dir($dir)) {
    $files = scandir($dir);
    foreach ($files as $file) {
        // Skip current/parent dir pointers
        if ($file !== '.' && $file !== '..') {
            $path = $dir . '/' . $file;
            // Get extension
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            // Check if valid image
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
                // Add to list
                $images[] = $path; 
            }
        }
    }
}

// Reset array keys to be safe JSON
$images = array_values($images);

echo json_encode(['success' => true, 'images' => $images]);
?>