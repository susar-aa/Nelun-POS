<?php
// update_db.php
// Nelun POS Database Schema Migration Tool
// Run this file in your browser or console to update your database schema.

header('Content-Type: text/plain');
require_once 'db_connection.php';

echo "🚀 Starting Nelun POS Schema Migrations...\n";
echo "========================================\n\n";

$queries = [
    // 1. Categories Table
    "CREATE TABLE IF NOT EXISTS categories (
        category_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 2. Suppliers Table
    "CREATE TABLE IF NOT EXISTS suppliers (
        supplier_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        contact_person VARCHAR(100),
        phone VARCHAR(20),
        email VARCHAR(100),
        address TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 3. Add category_id and supplier_id columns to Products table
    "ALTER TABLE Products ADD COLUMN category_id INT NULL;",
    "ALTER TABLE Products ADD COLUMN supplier_id INT NULL;",

    // 4. Add foreign keys to Products table
    "ALTER TABLE Products ADD CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL;",
    "ALTER TABLE Products ADD CONSTRAINT fk_product_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL;",

    // 5. Purchase Orders Table
    "CREATE TABLE IF NOT EXISTS purchase_orders (
        po_id INT AUTO_INCREMENT PRIMARY KEY,
        po_number VARCHAR(50) UNIQUE NOT NULL,
        supplier_id INT NOT NULL,
        order_date DATE NOT NULL,
        expected_date DATE NULL,
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        status ENUM('Draft', 'Sent', 'Completed', 'Cancelled') DEFAULT 'Draft',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 6. PO Items Table
    "CREATE TABLE IF NOT EXISTS po_items (
        po_item_id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(10,2) GENERATED ALWAYS AS (quantity * cost_price) STORED,
        FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 7. Goods Received Notes (GRN) Table
    "CREATE TABLE IF NOT EXISTS goods_received_notes (
        grn_id INT AUTO_INCREMENT PRIMARY KEY,
        grn_number VARCHAR(50) UNIQUE NOT NULL,
        po_id INT NULL,
        supplier_id INT NOT NULL,
        received_date DATE NOT NULL,
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        paid_amount DECIMAL(10,2) DEFAULT 0.00,
        payment_method ENUM('Cash', 'Cheque', 'Bank_Transfer', 'Credit') DEFAULT 'Credit',
        status ENUM('Completed', 'Returned') DEFAULT 'Completed',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE SET NULL,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 8. GRN Items Table
    "CREATE TABLE IF NOT EXISTS grn_items (
        grn_item_id INT AUTO_INCREMENT PRIMARY KEY,
        grn_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity_received INT NOT NULL DEFAULT 1,
        cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(10,2) GENERATED ALWAYS AS (quantity_received * cost_price) STORED,
        FOREIGN KEY (grn_id) REFERENCES goods_received_notes(grn_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 9. Product Batches Table (For FIFO Tracking)
    "CREATE TABLE IF NOT EXISTS product_batches (
        batch_id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        grn_item_id INT NULL,
        cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity_received INT NOT NULL,
        quantity_remaining INT NOT NULL,
        status ENUM('Active', 'Depleted') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE CASCADE,
        FOREIGN KEY (grn_item_id) REFERENCES grn_items(grn_item_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 10. Supplier Payments Table
    "CREATE TABLE IF NOT EXISTS supplier_payments (
        payment_id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        payment_date DATE NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_method ENUM('Cash', 'Cheque', 'Bank_Transfer') NOT NULL DEFAULT 'Cash',
        cheque_number VARCHAR(50) NULL,
        cheque_date DATE NULL,
        cheque_status ENUM('Pending', 'Realized', 'Returned') DEFAULT 'Pending',
        bank_details VARCHAR(150) NULL,
        notes TEXT,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 11. Supplier Ledger Table
    "CREATE TABLE IF NOT EXISTS supplier_ledger (
        ledger_id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        transaction_type ENUM('GRN', 'Payment', 'Cheque_Return', 'Refund') NOT NULL,
        reference_id INT NOT NULL,
        debit DECIMAL(10,2) DEFAULT 0.00,
        credit DECIMAL(10,2) DEFAULT 0.00,
        balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 12. Add cost_price and batch_id to sale_items table
    "ALTER TABLE sale_items ADD COLUMN cost_price DECIMAL(10,2) NULL;",
    "ALTER TABLE sale_items ADD COLUMN batch_id INT NULL;",
    "ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_batch FOREIGN KEY (batch_id) REFERENCES product_batches(batch_id) ON DELETE SET NULL;"
];

foreach ($queries as $index => $sql) {
    try {
        $pdo->exec($sql);
        echo "✅ Step " . ($index + 1) . " completed successfully.\n";
    } catch (PDOException $e) {
        // Suppress "duplicate column/constraint" errors as they indicate columns/constraints already exist
        if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "ℹ️ Step " . ($index + 1) . " skipped (Column/Constraint already exists).\n";
        } else {
            echo "❌ Step " . ($index + 1) . " failed: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n📦 Initializing product batches for existing inventory...\n";
try {
    // Select active products that have quantity > 0 and no batches yet
    $stmt = $pdo->query("
        SELECT p.product_id, p.quantity, p.cost, p.price 
        FROM Products p
        WHERE p.quantity > 0 
          AND p.product_id NOT IN (SELECT DISTINCT product_id FROM product_batches)
    ");
    $existingProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($existingProducts) > 0) {
        $pdo->beginTransaction();
        $stmtInsertBatch = $pdo->prepare("
            INSERT INTO product_batches (product_id, cost_price, selling_price, quantity_received, quantity_remaining, status) 
            VALUES (?, ?, ?, ?, ?, 'Active')
        ");
        
        foreach ($existingProducts as $p) {
            $cost = $p['cost'] ?? 0;
            $price = $p['price'] ?? 0;
            $qty = $p['quantity'];
            $stmtInsertBatch->execute([$p['product_id'], $cost, $price, $qty, $qty]);
        }
        $pdo->commit();
        echo "✅ Created " . count($existingProducts) . " initial stock batches for existing active inventory.\n";
    } else {
        echo "ℹ️ Existing active inventory is already batch-initialized or has no items with stock.\n";
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "❌ Failed to initialize stock batches: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "🎉 Nelun POS Schema Migration Completed!\n";
?>
