# Stage 1: Build Stage
FROM php:8.3-fpm-alpine AS build

# Install build dependencies and PHP extensions needed for installation/building
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

# Stage 2: Production Stage with Nginx
FROM php:8.3-fpm-alpine

# Install Nginx and production dependencies
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
    nginx

# Install necessary PHP extensions
RUN docker-php-ext-configure zip \
    && docker-php-ext-install zip pdo pdo_mysql \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install bcmath exif gettext opcache \
    && rm -rf /var/cache/apk/*

# Copy the built application from the build stage
COPY --from=build /var/www /var/www

# Copy your custom Nginx configuration file into the container
# Ensure you have a deploy/nginx.conf file that points the root to /var/www/public
COPY deploy/nginx.conf /etc/nginx/http.d/default.conf

WORKDIR /var/www

# Expose port 80 (HTTP)
EXPOSE 80

# Start Nginx and PHP-FPM
CMD ["sh", "-c", "nginx && php-fpm"]