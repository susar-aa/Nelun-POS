<?php
// test_db.php
header('Content-Type: text/plain');

$host = 'localhost';
$port = '3306';
$db   = 'Nelun_db';
$user = 'suzxlabs';
$pass = 'Susara@200611003614';

echo "Testing connection to database '$db' on '$host:$port' with user '$user'...\n\n";

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connected Successfully!\n";
    echo "Server Version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
    
} catch (PDOException $e) {
    echo "❌ Connection Failed:\n";
    echo "Error Code: " . $e->getCode() . "\n";
    echo "Message: " . $e->getMessage() . "\n";
}
?>