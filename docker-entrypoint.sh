#!/bin/bash
set -e

# Port configuration for Railway
PORT="${PORT:-80}"
echo "Configuring Apache to listen on port ${PORT}..."
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Discover packages and clear caches
php artisan package:discover --ansi || true
php artisan config:clear || true
php artisan view:clear || true

# Auto import database if tables are empty
php -r '
try {
    $host = env("DB_HOST");
    $db   = env("DB_DATABASE");
    $user = env("DB_USERNAME");
    $pass = env("DB_PASSWORD");
    $port = env("DB_PORT", 3306);
    if ($host && $db && $user) {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15
        ];
        if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
        }
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass, $options);
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) {
            echo "Database is empty. Importing database/database.sql...\n";
            $sqlFile = "/var/www/html/database/database.sql";
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                $pdo->exec($sql);
                echo "Database schema imported successfully!\n";
            }
        } else {
            echo "Database already initialized with " . count($tables) . " tables.\n";
        }
    }
} catch (\Throwable $e) {
    echo "Database auto-init notice: " . $e->getMessage() . "\n";
}
' || true

echo "Starting Apache in foreground..."
exec apache2-foreground
