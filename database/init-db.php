<?php

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: 3306;
$db   = getenv('DB_DATABASE') ?: 'laravel';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

echo "=== Initializing Database Connection ===\n";
echo "Host: {$host}:{$port}, Database: {$db}, User: {$user}\n";

if (!$host || !$db || !$user) {
    echo "Database credentials not provided. Skipping auto-import.\n";
    exit(0);
}

try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 20,
    ];
    
    $caPath = getenv('MYSQL_ATTR_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';
    if (file_exists($caPath)) {
        echo "Using SSL CA certificate: {$caPath}\n";
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
    }
    
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "Connected to MySQL successfully!\n";

    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Found " . count($tables) . " existing tables in database.\n";

    if (empty($tables)) {
        echo "Database is empty. Importing database/database.sql...\n";
        $sqlPath = __DIR__ . '/database.sql';
        if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);
            $pdo->exec($sql);
            echo "SUCCESS: database/database.sql imported successfully!\n";
        } else {
            echo "ERROR: database.sql not found at {$sqlPath}\n";
        }
    } else {
        echo "Database already has tables. Skipping import.\n";
    }
} catch (\Throwable $e) {
    echo "Database initialization notice: " . $e->getMessage() . "\n";
}
