# --- Stage 1: Base PHP with Dependencies ---
FROM php:8.2-fpm AS base

# Set working directory
WORKDIR /var/www

# Set DEBIAN_FRONTEND to noninteractive to prevent prompts during apt-get install
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies
# Menambahkan lebih banyak library untuk kompilasi paket Python (khususnya OpenCV)
# dan build tools.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    wget \
    # PHP Core Extensions Deps
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    # Imagick Deps
    libmagickwand-dev \
    # Python Core
    python3 \
    python3-pip \
    python3-dev \
    # Common Build Tools
    build-essential \
    cmake \
    pkg-config \
    # OpenCV & NumPy Dependencies (lebih komprehensif)
    libjpeg-dev \
    libpng-dev \
    libtiff-dev \
    libavcodec-dev \
    libavformat-dev \
    libswscale-dev \
    libv4l-dev \
    libxvidcore-dev \
    libx264-dev \
    libgtk-3-dev \
    libatlas-base-dev \
    gfortran \
    # Runtime lib untuk OpenCV
    libgl1-mesa-glx \
    libglib2.0-0 \
    --no-install-recommends \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip fileinfo

# Install Imagick PHP extension
RUN pecl install imagick && docker-php-ext-enable imagick

# Install Composer (PHP package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# --- Stage 2: Build PHP Dependencies (Vendor) ---
FROM base AS vendor

WORKDIR /var/www

# Copy composer files
COPY composer.json composer.lock* ./

# Set Composer memory limit to unlimited
ENV COMPOSER_MEMORY_LIMIT=-1

# Install Composer dependencies (only production)
# PENTING: Jika ini masih gagal karena memori (exit code 137),
# Anda SANGAT PERLU menambah alokasi memori untuk Docker Engine Anda.
RUN composer install --no-interaction --optimize-autoloader --no-dev --prefer-dist

# --- Stage 3: Build Python Dependencies ---
FROM base AS python_deps

WORKDIR /var/www

# Copy Python requirements file
COPY requirements.txt ./

# Upgrade pip
RUN python3 -m pip install --upgrade pip

# Install Python dependencies dengan logging sangat verbose
# PENTING: Jika ini gagal dengan exit code 1, SALIN SEMUA OUTPUT DARI PERINTAH INI.
RUN pip3 install -vvv --no-cache-dir -r requirements.txt

# --- Stage 4: Final Application Image ---
# Gunakan 'base' sebagai dasar, sehingga tidak perlu install ulang dependensi & ekstensi
FROM base AS final

WORKDIR /var/www

# Copy application code (setelah vendor dan python_deps agar cache lebih baik)
COPY . .

# Copy installed vendor dependencies from the 'vendor' stage
COPY --from=vendor /var/www/vendor ./vendor

# Copy installed Python dependencies from the 'python_deps' stage
# Sesuaikan jalur Python jika diperlukan (cek versi Python di image 'base')
# Untuk php:8.2-fpm (Debian Bookworm), python3.11 adalah default.
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
