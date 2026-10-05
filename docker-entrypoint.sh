#!/bin/bash
set -e

# Port configuration for Render / Railway
PORT="${PORT:-80}"
echo "Configuring Apache to listen on port ${PORT}..."
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Ensure storage & bootstrap permissions
mkdir -p /var/www/html/storage/logs /var/www/html/storage/framework/views /var/www/html/storage/framework/sessions /var/www/html/storage/framework/cache
touch /var/www/html/storage/logs/laravel.log || true
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache || true

# Run database auto-initialization script
if [ -f "/var/www/html/database/init-db.php" ]; then
    php /var/www/html/database/init-db.php || true
fi

# Discover packages and clear caches
php artisan package:discover --ansi || true
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

echo "Starting Apache in foreground..."
exec apache2-foreground
