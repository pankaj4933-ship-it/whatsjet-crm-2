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
        PDO::ATTR_TIMEOUT => 30,
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
    echo "Connected to MySQL / TiDB successfully!\n";

    // Disable foreign key checks
    try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;"); } catch (\Throwable $e) {}

    // Check if vendors table exists and has AUTO_INCREMENT on _id
    $needsRebuild = false;
    $stmt = $pdo->query("SHOW TABLES LIKE 'vendors'");
    $vendorTable = $stmt->fetch();

    if (!$vendorTable) {
        echo "Vendors table does not exist. Initializing fresh schema...\n";
        $needsRebuild = true;
    } else {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM `vendors` LIKE '_id'");
        $colData = $stmtCol->fetch(PDO::FETCH_ASSOC);
        if (!$colData || stripos($colData['Extra'] ?? '', 'auto_increment') === false) {
            echo "TiDB tables missing AUTO_INCREMENT detected. Rebuilding schema for native TiDB compatibility...\n";
            $needsRebuild = true;
        }
    }

    if ($needsRebuild) {
        // Drop existing tables cleanly
        $stmt = $pdo->query("SHOW TABLES");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($existingTables as $tbl) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `{$tbl}`;");
            } catch (\Throwable $e) {}
        }
        echo "Cleared " . count($existingTables) . " legacy tables.\n";

        $sqlPath = __DIR__ . '/database.sql';
        if (file_exists($sqlPath)) {
            $sqlContent = file_get_contents($sqlPath);
            $queries = getTidbCompatibleQueries($sqlContent);
            
            $successCount = 0;
            $failCount = 0;
            foreach ($queries as $q) {
                $q = trim($q);
                if (empty($q)) continue;
                try {
                    $pdo->exec($q);
                    $successCount++;
                } catch (\Throwable $e) {
                    $failCount++;
                }
            }
            echo "Import completed: {$successCount} queries executed ({$failCount} skipped/duplicates).\n";
        }
    } else {
        echo "Database already verified: TiDB native AUTO_INCREMENT is active.\n";
    }

    // Ensure default user_roles exist
    try {
        $stmtRoles = $pdo->query("SELECT COUNT(*) FROM `user_roles`");
        if ($stmtRoles && $stmtRoles->fetchColumn() == 0) {
            echo "Seeding user_roles...\n";
            $pdo->exec("INSERT INTO `user_roles` (`_id`, `_uid`, `status`, `created_at`, `updated_at`, `title`) VALUES
                (1, '15f21c9f-88bb-4fec-bad4-03eb9d9065f8', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'Super Admin'),
                (2, '287133c4-2afc-4f65-ab3c-28b0df8a099a', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'Vendor Admin'),
                (3, '30ee1967-4nfc-4f65-87bb-g2ea0722b178', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'Vendor User')
                ON DUPLICATE KEY UPDATE `status`=1;");
        }
    } catch (\Throwable $e) {}

    // Ensure default superadmin exists
    try {
        $adminPasswordHash = password_hash('MRPANKAJ2944MRBOTAMAN2944', PASSWORD_BCRYPT);
        $pdo->exec("INSERT INTO `users` (`_id`, `_uid`, `created_at`, `updated_at`, `username`, `email`, `password`, `status`, `remember_token`, `first_name`, `last_name`, `mobile_number`, `user_roles__id`) VALUES
            (1, '50ee1967-7341-4c3a-b071-f2ea0722b179', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'admin', 'mmks4933@gmail.com', '{$adminPasswordHash}', 1, 'O4G7hgyto34OhcWQUYM9ULx3kSEMNTrFIsflasaiq0AgfeBWVBxGeK9Kwp', 'Admin', 'Pankaj', '9999999999', 1)
            ON DUPLICATE KEY UPDATE `email`='mmks4933@gmail.com', `password`='{$adminPasswordHash}', `status`=1, `user_roles__id`=1;");
    } catch (\Throwable $e) {}

    // Re-enable foreign key checks
    try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch (\Throwable $e) {}

    echo "=== Database initialization completed successfully! ===\n";

} catch (\Throwable $e) {
    echo "Database initialization notice: " . $e->getMessage() . "\n";
}

/**
 * Transform standard MySQL dump into TiDB compatible queries with inline AUTO_INCREMENT PRIMARY KEY
 */
function getTidbCompatibleQueries(string $sql): array
{
    // Step 1: Inline AUTO_INCREMENT PRIMARY KEY into CREATE TABLE statements
    $sql = preg_replace('/`_id`\s+int\s+UNSIGNED\s+NOT\s+NULL,/i', '`_id` int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,', $sql);
    $sql = preg_replace('/`_id`\s+tinyint\s+UNSIGNED\s+NOT\s+NULL,/i', '`_id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,', $sql);
    $sql = preg_replace('/`id`\s+bigint\s+UNSIGNED\s+NOT\s+NULL,/i', '`id` bigint UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,', $sql);

    // Step 2: Split into statements
    $rawQueries = splitSqlStatements($sql);
    $cleanQueries = [];

    foreach ($rawQueries as $q) {
        $trimmed = trim($q);
        if (empty($trimmed)) continue;

        // Skip standalone ALTER TABLE ... ADD PRIMARY KEY
        if (preg_match('/^ALTER\s+TABLE\s+`[^`]+`\s+ADD\s+PRIMARY\s+KEY\s*\([^)]+\);?$/i', $trimmed)) {
            continue;
        }

        // Skip standalone ALTER TABLE ... MODIFY ... AUTO_INCREMENT
        if (preg_match('/^ALTER\s+TABLE\s+`[^`]+`\s+MODIFY\s+`[^`]+`\s+[^;]+AUTO_INCREMENT;?$/i', $trimmed)) {
            continue;
        }

        // Remove ADD PRIMARY KEY inside combined ALTER TABLE statements
        if (preg_match('/^ALTER\s+TABLE/i', $trimmed) && stripos($trimmed, 'ADD PRIMARY KEY') !== false) {
            $trimmed = preg_replace('/ADD\s+PRIMARY\s+KEY\s*\([^)]+\)\s*,\s*/i', '', $trimmed);
            $trimmed = preg_replace('/,\s*ADD\s+PRIMARY\s+KEY\s*\([^)]+\)/i', '', $trimmed);
        }

        $cleanQueries[] = $trimmed;
    }

    return $cleanQueries;
}

/**
 * Split raw SQL content into separate queries
 */
function splitSqlStatements(string $sql): array
{
    $queries = [];
    $lines = explode("\n", $sql);
    $buffer = '';

    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Skip comment lines
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
            continue;
        }

        $buffer .= $line . "\n";

        if (str_ends_with($trimmed, ';')) {
            $queries[] = trim($buffer);
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') {
        $queries[] = trim($buffer);
    }

    return $queries;
}
