# Stage 1: PHP base dengan composer
FROM composer:2 as vendor
WORKDIR /app
COPY database/ database/
COPY composer.json composer.json
COPY composer.lock composer.lock
RUN composer install --ignore-platform-reqs --no-interaction --no-plugins --no-scripts --prefer-dist

# Stage 2: PHP-FPM dengan ekstensi yang dibutuhkan
FROM php:8.2-fpm-alpine AS app
WORKDIR /var/www/html

# Instal dependensi PHP & sistem (termasuk untuk Imagick, Redis, Python)
# Ini bagian yang perlu disesuaikan untuk Alpine Linux
RUN apk add --no-cache \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    imagemagick-dev \ # Untuk Imagick
    oniguruma-dev \ # Untuk mbstring
    libxml2-dev \
    # Dependensi Python & OpenCV (ini bisa kompleks di Alpine)
    python3 py3-pip python3-dev build-base linux-headers \
    && apk add --virtual .build-deps $PHPIZE_DEPS imagemagick-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd zip pdo pdo_mysql pdo_pgsql bcmath soap exif pcntl sockets intl \
    && pecl install imagick redis \ # Instal imagick dan redis via pecl
    && docker-php-ext-enable imagick redis \
    && apk del .build-deps

# Instal OpenCV & NumPy untuk Python (ini akan berjalan di dalam container)
COPY scripts/requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir -r /tmp/requirements.txt

# Copy file aplikasi dan vendor
COPY --from=vendor /app/vendor/ /var/www/html/vendor/
COPY . /var/www/html/

# Atur permission
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Expose port PHP-FPM
EXPOSE 9000
CMD ["php-fpm"]

# Stage 3: Nginx
FROM nginx:alpine
WORKDIR /var/www/html
COPY --from=app /var/www/html/public /var/www/html/public
COPY nginx.conf /etc/nginx/conf.d/default.conf # Salin nginx.conf Anda
# Jika Procfile Anda menjalankan skrip heroku-php-nginx, itu tidak akan dipakai di sini.
# Nginx akan dijalankan oleh Docker.
