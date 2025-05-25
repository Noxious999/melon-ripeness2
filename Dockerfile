# --- Stage 1: Base PHP with Dependencies ---
FROM php:8.2-fpm AS base

# Set working directory
WORKDIR /var/www

# Install system dependencies
# Menambahkan build-essential, cmake, python3-dev, dan library lain untuk kompilasi paket Python (khususnya OpenCV)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libmagickwand-dev \
    python3 \
    python3-pip \
    libgl1-mesa-glx \
    build-essential \
    cmake \
    python3-dev \
    # Tambahan dependensi untuk OpenCV dan NumPy
    libjpeg-dev \
    libtiff-dev \
    libavcodec-dev \
    libavformat-dev \
    libswscale-dev \
    libatlas-base-dev \
    gfortran \
    # libgtk-3-dev \ # Jika masih gagal, coba uncomment ini, tapi mungkin menambah ukuran image
    --no-install-recommends \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip fileinfo

# Install Imagick PHP extension
RUN pecl install imagick && docker-php-ext-enable imagick

# Install Composer (PHP package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# --- Stage 2: Build PHP Dependencies ---
FROM base AS vendor

# Copy composer files
COPY composer.json composer.lock* ./

# Install Composer dependencies (only production)
# Coba tambahkan --prefer-dist untuk mengurangi penggunaan memori jika memungkinkan
# PENTING: Jika ini masih gagal karena memori (exit code 137),
# Anda SANGAT PERLU menambah alokasi memori untuk Docker Engine Anda.
RUN composer install --no-interaction --optimize-autoloader --no-dev --prefer-dist

# --- Stage 3: Build Python Dependencies ---
FROM base AS python_deps

# Copy Python requirements file
COPY requirements.txt ./

# Install Python dependencies
# Jika ini gagal dengan exit code 1, periksa log untuk detail error spesifik.
# Kemungkinan masih ada library sistem yang kurang.
RUN pip3 install --no-cache-dir -r requirements.txt

# --- Stage 4: Final Application Image ---
# Gunakan 'base' sebagai dasar, sehingga tidak perlu install ulang dependensi & ekstensi
FROM base AS final

WORKDIR /var/www

# Copy application code
COPY . .

# Copy installed vendor dependencies from the 'vendor' stage
COPY --from=vendor /var/www/vendor ./vendor

# Copy installed Python dependencies from the 'python_deps' stage
# Sesuaikan jalur Python jika diperlukan (cek versi Python di image 'base')
# Untuk php:8.2-fpm, versi python3 biasanya python3.11 (Debian Bookworm)
COPY --from=python_deps /usr/local/lib/python3.11/site-packages /usr/local/lib/python3.11/site-packages
COPY --from=python_deps /usr/local/bin /usr/local/bin

# Set permissions for Laravel storage and cache
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
RUN chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Run Laravel post-install scripts & optimizations
RUN php artisan config:cache
RUN php artisan route:cache
RUN php artisan view:cache

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]
