# Stage 1: Build Stage
FROM php:8.3-fpm-alpine AS build

# Install build dependencies and PHP extensions needed for building
RUN apk add --no-cache \
    build-base \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libxpm-dev \
    oniguruma-dev

# Install Composer from the official Composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy application files and install PHP dependencies
COPY . .
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# Create the storage symlink
RUN php artisan storage:link

# Stage 2: Production Stage with Nginx and Supervisor
FROM php:8.3-fpm-alpine

# Install Nginx, Supervisor, and production dependencies
RUN apk add --no-cache \
    zip \
    libzip-dev \
    freetype \
    libjpeg-turbo \
    libpng \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    oniguruma-dev \
    gettext-dev \
    nginx \
    supervisor

# Install necessary PHP extensions
RUN docker-php-ext-configure zip \
    && docker-php-ext-install zip pdo pdo_mysql \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install bcmath exif gettext opcache \
    && rm -rf /var/cache/apk/*

# Copy the built application from the build stage
COPY --from=build /var/www /var/www

# Create Supervisor log directory to avoid the "directory does not exist" error
RUN mkdir -p /var/log/supervisor

# Fix ownership/permissions
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Copy your custom Nginx configuration file into the container.
# Ensure you have a deploy/nginx.conf file that points the root to /var/www/public.
COPY deploy/nginx.conf /etc/nginx/http.d/default.conf

WORKDIR /var/www

# Expose port 80 (HTTP)
EXPOSE 80

# Copy Supervisor configuration file
COPY deploy/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Start Supervisor to manage Nginx, PHP-FPM, and the Laravel scheduler
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]