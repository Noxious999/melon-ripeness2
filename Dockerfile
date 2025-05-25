# --- Stage 1: Base PHP with Dependencies ---
FROM php:8.2-fpm AS base

# Set working directory
WORKDIR /var/www

# Set DEBIAN_FRONTEND to noninteractive to prevent prompts during apt-get install
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies
# Menghapus libgtk-3-dev karena menggunakan opencv-python-headless
# Memastikan wget dan ca-certificates ada untuk get-pip.py
RUN apt-get update -y && \
    apt-get install -y --no-install-recommends \
    # Essential tools
    git \
    curl \
    wget \
    ca-certificates \
    gnupg \
    # PHP Core Extensions Deps
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    # Imagick Deps
    libmagickwand-dev \
    # Python Core & Tools
    python3 \
    python3-pip \
    python3-dev \
    python3-venv \
    # Common Build Tools
    build-essential \
    cmake \
    pkg-config \
    # OpenCV (headless) & NumPy Dependencies
    libjpeg-dev \
    libpng-dev \
    libtiff-dev \
    libavcodec-dev \
    libavformat-dev \
    libswscale-dev \
    libv4l-dev \
    libxvidcore-dev \
    libx264-dev \
    # libgtk-3-dev DIHAPUS karena tidak diperlukan untuk headless dan menghemat memori
    libatlas-base-dev \
    gfortran \
    # Runtime lib untuk OpenCV
    libgl1-mesa-glx \
    libglib2.0-0 \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Create a symlink for python -> python3
RUN ln -sf /usr/bin/python3 /usr/bin/python

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

# Set Composer memory limit to unlimited (Docker Engine/Railway limit still applies)
ENV COMPOSER_MEMORY_LIMIT=-1

# Install Composer dependencies (only production)
# PENTING: Dengan batas 512MB di Railway, ini adalah titik yang SANGAT RAWAN GAGAL karena OOM (exit code 137).
# Jika terus gagal, Anda mungkin perlu mempertimbangkan platform lain dengan resource lebih besar
# atau memecah layanan/mengoptimasi dependensi Composer secara drastis.
RUN echo "Attempting Composer install..." && \
    composer install --no-interaction --optimize-autoloader --no-dev --prefer-dist --verbose && \
    echo "Composer install finished."

# --- Stage 3: Build Python Dependencies ---
FROM base AS python_deps

WORKDIR /var/www

# Copy Python requirements file
COPY requirements.txt ./

# Download and install/upgrade pip using get-pip.py for robustness
RUN echo "Downloading get-pip.py..." && \
    wget https://bootstrap.pypa.io/get-pip.py -O /tmp/get-pip.py && \
    echo "Downloaded get-pip.py. Running it..." && \
    python3 /tmp/get-pip.py && \
    echo "Finished running get-pip.py."

# Upgrade pip to the latest version (yang baru diinstal oleh get-pip.py)
# PENTING: Jika perintah INI gagal, salin SEMUA output dari perintah ini.
RUN echo "Attempting to upgrade pip..." && \
    pip3 install -vvv --upgrade pip && \
    echo "Pip upgrade finished."

# Install Python dependencies dengan logging sangat verbose
# PENTING: Jika ini gagal dengan exit code 1, SALIN SEMUA OUTPUT DARI PERINTAH INI.
RUN echo "Attempting to install Python requirements..." && \
    pip3 install -vvv --no-cache-dir -r requirements.txt && \
    echo "Python requirements installation finished."

# --- Stage 4: Final Application Image ---
# Gunakan 'base' sebagai dasar, sehingga tidak perlu install ulang dependensi & ekstensi
FROM base AS final

WORKDIR /var/www

# Copy application code (setelah vendor dan python_deps agar cache lebih baik)
COPY . .

# Copy installed vendor dependencies from the 'vendor' stage
COPY --from=vendor /var/www/vendor ./vendor

# Copy installed Python dependencies from the 'python_deps' stage
# Untuk php:8.2-fpm (Debian Bookworm), python3.11 adalah default.
# Pastikan path ini sesuai dengan tempat pip menginstal paket.
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
