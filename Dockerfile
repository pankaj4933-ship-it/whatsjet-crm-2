FROM php:8.2-apache

# Install official PHP extension installer (handles all dependencies and compilation)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install system utilities and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    default-mysql-client \
    ca-certificates \
    && install-php-extensions pdo_mysql mbstring exif pcntl bcmath gd zip intl gettext opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite for Laravel routes
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install PHP dependencies without platform requirement issues and without build-time scripts
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs --no-interaction --no-security-blocking --no-scripts

# Set permissions for storage, public/media-storage and bootstrap/cache
RUN mkdir -p /var/www/html/public/media-storage /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/public/media-storage /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 777 /var/www/html/public/media-storage /var/www/html/storage /var/www/html/bootstrap/cache

# Configure Apache DocumentRoot to public directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Configure Apache ServerName, KeepAlive and MPM Prefork (optimized for 512MB RAM)
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && printf "KeepAlive On\n\
MaxKeepAliveRequests 100\n\
KeepAliveTimeout 2\n\
<IfModule mpm_prefork_module>\n\
    StartServers             2\n\
    MinSpareServers          2\n\
    MaxSpareServers          4\n\
    MaxRequestWorkers        8\n\
    MaxConnectionsPerChild 200\n\
</IfModule>\n" > /etc/apache2/mods-available/mpm_prefork.conf

# Configure PHP settings (memory limit and OPcache to reduce RAM usage and speed up responses)
RUN printf "memory_limit = 128M\n\
upload_max_filesize = 64M\n\
post_max_size = 64M\n\
max_execution_time = 60\n\
opcache.enable = 1\n\
opcache.enable_cli = 0\n\
opcache.memory_consumption = 32\n\
opcache.interned_strings_buffer = 8\n\
opcache.max_accelerated_files = 10000\n\
opcache.validate_timestamps = 0\n\
opcache.save_comments = 1\n" > /usr/local/etc/php/conf.d/docker-php-custom.ini

# Setup startup entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENV PORT 80
EXPOSE 80

CMD ["docker-entrypoint.sh"]
