# Gunakan base image PHP-FPM resmi
FROM php:8.2-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Instal dependensi sistem untuk ekstensi PHP dan Python
# Alpine Linux menggunakan apk
RUN apk add --no-cache \
    # Untuk PHP extensions
    $PHPIZE_DEPS \ # build-base, autoconf, dll.
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    freetype-dev \
    imagemagick-dev \
    # Untuk Python dan OpenCV (ini bagian yang mungkin rumit di Alpine)
    python3 \
    py3-pip \
    # Dependensi OpenCV mungkin perlu: build-base, cmake, linux-headers,
    # libjpeg-turbo-dev, libpng-dev, tiff-dev, jasper-dev, libxrender-dev, etc.
    # Untuk kemudahan, pertimbangkan base image yang sudah ada OpenCV atau gunakan Debian base untuk PHP.
    # Untuk Alpine, instalasi OpenCV dari source atau wheel yang kompatibel bisa sulit.
    # Jika OpenCV sulit di Alpine, ganti base image PHP ke php:8.2-fpm (Debian based).
    # Jika menggunakan Debian base:
    # apt-get update && apt-get install -y --no-install-recommends \
    #   git unzip zip libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
    #   libmagickwand-dev python3 python3-pip python3-opencv \
    #   && rm -rf /var/lib/apt/lists/* \
    #   && pip3 install --no-cache-dir numpy # Jika python3-opencv tidak otomatis instal numpy

    # Untuk Alpine (jika ingin tetap dengan Alpine, instalasi OpenCV lebih manual):
    # Ini contoh, mungkin perlu penyesuaian besar untuk OpenCV di Alpine
    build-base python3-dev py3-numpy-dev py3-pillow \
    && pip3 install --no-cache-dir numpy opencv-python \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd zip pdo pdo_mysql bcmath exif pcntl sockets intl \
    && pecl install imagick redis \
    && docker-php-ext-enable imagick redis \
    && apk del $PHPIZE_DEPS # Hapus build dependencies

# Copy composer files dan instal dependensi PHP
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts --no-plugins

# Copy file aplikasi (termasuk skrip Python Anda di scripts/)
COPY . .

# Copy requirements.txt dan instal dependensi Python (jika belum di atas)
COPY scripts/requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir -r /tmp/requirements.txt

# Jalankan build aset frontend
RUN npm install && npm run build

# Atur permission untuk Laravel
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Expose port PHP-FPM (Koyeb akan menggunakan ini)
EXPOSE 9000
CMD ["php-fpm"]
