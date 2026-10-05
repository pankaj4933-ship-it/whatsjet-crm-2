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

    // Disable foreign key checks during schema fixes
    try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;"); } catch (\Throwable $e) {}

    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Found " . count($tables) . " existing tables in database.\n";

    $sqlPath = __DIR__ . '/database.sql';

    if (empty($tables)) {
        echo "Database is empty. Importing full database/database.sql statement by statement...\n";
        if (file_exists($sqlPath)) {
            $sqlContent = file_get_contents($sqlPath);
            // Split into individual SQL queries
            $queries = splitSqlStatements($sqlContent);
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
            echo "Import completed: {$successCount} queries executed, {$failCount} skipped/failed.\n";
        }
    }

    // Comprehensive table schema verification & AUTO_INCREMENT enforcement
    echo "Verifying PRIMARY KEY and AUTO_INCREMENT across all CRM tables...\n";
    $tableColumns = [
        'activity_logs' => ['_id', 'INT UNSIGNED'],
        'background_tasks' => ['_id', 'INT UNSIGNED'],
        'bot_flows' => ['_id', 'INT UNSIGNED'],
        'bot_replies' => ['_id', 'INT UNSIGNED'],
        'campaigns' => ['_id', 'INT UNSIGNED'],
        'campaign_groups' => ['_id', 'INT UNSIGNED'],
        'configurations' => ['_id', 'INT UNSIGNED'],
        'contacts' => ['_id', 'INT UNSIGNED'],
        'contact_bot_flow_sessions' => ['_id', 'INT UNSIGNED'],
        'contact_custom_fields' => ['_id', 'INT UNSIGNED'],
        'contact_custom_field_values' => ['_id', 'INT UNSIGNED'],
        'contact_groups' => ['_id', 'INT UNSIGNED'],
        'contact_labels' => ['_id', 'INT UNSIGNED'],
        'countries' => ['_id', 'INT UNSIGNED'],
        'credit_transactions' => ['_id', 'INT UNSIGNED'],
        'failed_jobs' => ['id', 'BIGINT UNSIGNED'],
        'group_contacts' => ['_id', 'INT UNSIGNED'],
        'info_materials' => ['_id', 'INT UNSIGNED'],
        'jobs' => ['id', 'BIGINT UNSIGNED'],
        'labels' => ['_id', 'INT UNSIGNED'],
        'login_attempts' => ['_id', 'INT UNSIGNED'],
        'login_logs' => ['_id', 'INT UNSIGNED'],
        'manual_subscriptions' => ['_id', 'INT UNSIGNED'],
        'message_labels' => ['_id', 'INT UNSIGNED'],
        'pages' => ['_id', 'INT UNSIGNED'],
        'password_resets' => ['_id', 'INT UNSIGNED'],
        'response_webhook_actions' => ['_id', 'INT UNSIGNED'],
        'response_webhook_action_logs' => ['_id', 'INT UNSIGNED'],
        'response_webhook_logs' => ['_id', 'INT UNSIGNED'],
        'subscriptions' => ['id', 'BIGINT UNSIGNED'],
        'subscription_items' => ['id', 'BIGINT UNSIGNED'],
        'tickets' => ['_id', 'INT UNSIGNED'],
        'transactions' => ['_id', 'INT UNSIGNED'],
        'users' => ['_id', 'INT UNSIGNED'],
        'user_devices' => ['_id', 'INT UNSIGNED'],
        'user_roles' => ['_id', 'TINYINT UNSIGNED'],
        'user_settings' => ['_id', 'INT UNSIGNED'],
        'vendors' => ['_id', 'INT UNSIGNED'],
        'vendor_notifications' => ['_id', 'INT UNSIGNED'],
        'vendor_settings' => ['_id', 'INT UNSIGNED'],
        'vendor_users' => ['_id', 'INT UNSIGNED'],
        'whatsapp_calls' => ['_id', 'INT UNSIGNED'],
        'whatsapp_message_logs' => ['_id', 'INT UNSIGNED'],
        'whatsapp_message_queue' => ['_id', 'INT UNSIGNED'],
        'whatsapp_templates' => ['_id', 'INT UNSIGNED'],
        'whatsapp_webhook_queue' => ['_id', 'INT UNSIGNED'],
    ];

    foreach ($tableColumns as $tableName => $colInfo) {
        $colName = $colInfo[0];
        $colType = $colInfo[1];

        try {
            // Check if table exists
            $stmt = $pdo->prepare("SHOW TABLES LIKE :table");
            $stmt->execute([':table' => $tableName]);
            if (!$stmt->fetch()) {
                continue;
            }

            // Inspect column details
            $stmtCol = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$colName}'");
            $colData = $stmtCol->fetch(PDO::FETCH_ASSOC);

            if ($colData) {
                // Check if Primary Key is set
                if (empty($colData['Key']) || $colData['Key'] !== 'PRI') {
                    try {
                        $pdo->exec("ALTER TABLE `{$tableName}` ADD PRIMARY KEY (`{$colName}`);");
                    } catch (\Throwable $e) {
                        // Already primary key or duplicate
                    }
                }

                // Check if AUTO_INCREMENT is set
                if (stripos($colData['Extra'] ?? '', 'auto_increment') === false) {
                    echo "Applying AUTO_INCREMENT to table `{$tableName}`.`{$colName}`...\n";
                    try {
                        $pdo->exec("ALTER TABLE `{$tableName}` MODIFY `{$colName}` {$colType} NOT NULL AUTO_INCREMENT;");
                        echo "  [OK] `{$tableName}`.`{$colName}` is now AUTO_INCREMENT.\n";
                    } catch (\Throwable $e) {
                        echo "  [WARN] Failed to set AUTO_INCREMENT on `{$tableName}`: " . $e->getMessage() . "\n";
                    }
                }
            }
        } catch (\Throwable $e) {
            echo "  [ERROR] Table {$tableName} inspection error: " . $e->getMessage() . "\n";
        }
    }

    // Check & seed user_roles if empty
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

    // Check & seed admin user if empty
    try {
        $stmtUsers = $pdo->query("SELECT COUNT(*) FROM `users`");
        if ($stmtUsers && $stmtUsers->fetchColumn() == 0) {
            echo "Seeding default superadmin user...\n";
            $pdo->exec("INSERT INTO `users` (`_id`, `_uid`, `created_at`, `updated_at`, `username`, `email`, `password`, `status`, `remember_token`, `first_name`, `last_name`, `mobile_number`, `user_roles__id`) VALUES
                (1, '50ee1967-7341-4c3a-b071-f2ea0722b179', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'superadmin', 'superadmin@yourdomain.com', '$2y$10$G17OyUEA26E4lKN4dFBn7eChwGRBdW8ik0f3b7cSayCMVFVgKiG.2', 1, 'O4G7hgyto34OhcWQUYM9ULx3kSEMNTrFIsflasaiq0AgfeBWVBxGeK9Kwp', 'Super', 'Administrator', '9999999999', 1)
                ON DUPLICATE KEY UPDATE `status`=1;");
        }
    } catch (\Throwable $e) {}

    // Re-enable foreign key checks
    try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch (\Throwable $e) {}

    echo "=== Database initialization completed successfully! ===\n";

} catch (\Throwable $e) {
    echo "Database initialization notice: " . $e->getMessage() . "\n";
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
