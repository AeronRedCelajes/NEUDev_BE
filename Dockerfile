# Use an official PHP image with FPM on Alpine Linux
FROM php:8.1-fpm-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    build-base \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libxpm-dev \
    oniguruma-dev \
    supervisor \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-xpm \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring exif pcntl gd

# Install Composer from the official Composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application files to the container
COPY . .

# Install PHP dependencies via Composer
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# Create the storage symlink
RUN php artisan storage:link

# Copy the Supervisor configuration file into the container
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose the port PHP-FPM listens on (default 9000)
EXPOSE 9000

# Start Supervisor, which will in turn run PHP-FPM and the scheduler
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]