<?php
require_once 'db_connection.php';

try {
    // 1. Add reorder_level to Products
    try {
        $pdo->exec("ALTER TABLE Products ADD COLUMN reorder_level INT NOT NULL DEFAULT 10;");
        echo "✅ Added reorder_level to Products.\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21') {
            echo "ℹ️ reorder_level already exists.\n";
        } else {
            echo "❌ Error adding reorder_level: " . $e->getMessage() . "\n";
        }
    }

    // 2. Create unified Cheques Table
    $sqlCheques = "CREATE TABLE IF NOT EXISTS cheques (
        cheque_id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('Issued', 'Received') NOT NULL,
        payee_payer_name VARCHAR(150) NOT NULL,
        bank_name VARCHAR(150) NOT NULL,
        cheque_number VARCHAR(50) NOT NULL,
        banking_date DATE NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('Pending', 'Realized', 'Returned', 'Cancelled') DEFAULT 'Pending',
        reference_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sqlCheques);
    echo "✅ Cheques table created or already exists.\n";

} catch (Exception $e) {
    echo "❌ General Error: " . $e->getMessage() . "\n";
}
?>
