<?php
// rollback_branch.php
// One-time cleanup: Removes all branch-system database changes from Nelun_db.
// Run ONCE via browser: https://nelun.suzxlabs.com/rollback_branch.php
// DELETE THIS FILE after running successfully.

header('Content-Type: text/plain; charset=utf-8');
require_once 'db_connection.php';

echo "🧹 Nelun POS — Branch System Database Rollback\n";
echo "=================================================\n\n";

// -----------------------------------------------------------------------
// Helper: check if a table exists
// -----------------------------------------------------------------------
function tableExists(PDO $pdo, string $db, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $stmt->execute([$db, $table]);
    return (bool) $stmt->fetchColumn();
}

// -----------------------------------------------------------------------
// Helper: check if a column exists
// -----------------------------------------------------------------------
function columnExists(PDO $pdo, string $db, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$db, $table, $column]);
    return (bool) $stmt->fetchColumn();
}

// -----------------------------------------------------------------------
// Helper: check if a foreign key constraint exists
// -----------------------------------------------------------------------
function fkExists(PDO $pdo, string $db, string $table, string $constraint): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $stmt->execute([$db, $table, $constraint]);
    return (bool) $stmt->fetchColumn();
}

$dbName = 'Nelun_db';
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");

// -----------------------------------------------------------------------
// STEP 1: Drop branch_id FK & column from tables it may have been added to
// -----------------------------------------------------------------------
// Exact FK names discovered from live DB + common variants for all tables
$tablesWithBranchId = [
    'users'                => ['fk_user_branch',  'fk_users_branch',  'users_ibfk_branch',  'fk_branch_users'],
    'sales'                => ['fk_sales_branch',  'fk_sale_branch',   'sales_ibfk_branch',  'fk_branch_sales'],
    'Products'             => ['fk_products_branch','fk_product_branch','products_ibfk_branch','fk_branch_products'],
    'purchase_orders'      => ['fk_purchase_orders_branch','fk_po_branch','purchase_orders_ibfk_branch'],
    'goods_received_notes' => ['fk_grn_branch',   'fk_goods_received_notes_branch', 'goods_received_notes_ibfk_branch'],
];

foreach ($tablesWithBranchId as $table => $fkCandidates) {
    if (!tableExists($pdo, $dbName, $table)) {
        echo "⏭️  Table '$table' not found — skipping.\n";
        continue;
    }

    if (!columnExists($pdo, $dbName, $table, 'branch_id')) {
        echo "ℹ️  'branch_id' column not found in '$table' — skipping.\n";
        continue;
    }

    // 1. Drop whichever FK constraint exists
    foreach ($fkCandidates as $fkName) {
        if (fkExists($pdo, $dbName, $table, $fkName)) {
            try {
                $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$fkName`");
                echo "✅ Dropped FK '$fkName' from '$table'.\n";
            } catch (PDOException $e) {
                echo "⚠️  Could not drop FK '$fkName' from '$table': " . $e->getMessage() . "\n";
            }
        }
    }

    // 2. Drop the column
    try {
        $pdo->exec("ALTER TABLE `$table` DROP COLUMN `branch_id`");
        echo "✅ Dropped column 'branch_id' from '$table'.\n";
    } catch (PDOException $e) {
        echo "❌ Failed to drop 'branch_id' from '$table': " . $e->getMessage() . "\n";
    }
}

// -----------------------------------------------------------------------
// STEP 2: Drop branch-related tables (order matters — child tables first)
// -----------------------------------------------------------------------
$branchTables = [
    'branch_stock',       // stock assigned per branch
    'branch_transfers',   // stock transfers between branches
    'branch_users',       // branch-user assignment pivot
    'branches',           // main branches table
];

echo "\n";
foreach ($branchTables as $table) {
    if (tableExists($pdo, $dbName, $table)) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "✅ Dropped table '$table'.\n";
        } catch (PDOException $e) {
            echo "❌ Failed to drop '$table': " . $e->getMessage() . "\n";
        }
    } else {
        echo "ℹ️  Table '$table' does not exist — skipping.\n";
    }
}

$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

echo "\n=================================================\n";
echo "🎉 Branch rollback complete. Please DELETE this file now.\n";
?>
