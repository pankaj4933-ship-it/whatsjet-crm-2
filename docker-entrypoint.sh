#!/bin/bash
set -e

# Port configuration for Render / Railway
PORT="${PORT:-80}"
echo "Configuring Apache to listen on port ${PORT}..."
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Run database auto-initialization script
if [ -f "/var/www/html/database/init-db.php" ]; then
    php /var/www/html/database/init-db.php || true
fi

# Discover packages and clear caches
php artisan package:discover --ansi || true
php artisan config:clear || true
php artisan view:clear || true

echo "Starting Apache in foreground..."
exec apache2-foreground
