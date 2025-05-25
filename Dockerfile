# --- Stage 1: Base PHP with Dependencies ---
FROM php:8.2-fpm AS base

# Set working directory
WORKDIR /var/www

# Install system dependencies
# - Git, curl, zip, unzip: Common tools for development and composer.
# - libpng-dev, libxml2-dev, libzip-dev: For PHP extensions.
# - libmagickwand-dev: For Imagick PHP extension.
# - python3, python3-pip: To run Python scripts.
# - libgl1-mesa-glx: Runtime dependency for OpenCV.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libmagickwand-dev --no-install-recommends \
    python3 \
    python3-pip \
    libgl1-mesa-glx \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
# - pdo_mysql: For database connection (assuming MySQL/MariaDB). Ganti jika Anda pakai DB lain.
# - mbstring, exif, bcmath, gd, zip: Common Laravel/PHP extensions.
# - fileinfo: Required by composer.json.
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
# --no-interaction: Prevents asking questions.
# --optimize-autoloader: Creates an optimized autoloader.
# --no-dev: Skips development dependencies.
RUN composer install --no-interaction --optimize-autoloader --no-dev

# --- Stage 3: Build Python Dependencies ---
FROM base AS python_deps

# Copy Python requirements file
COPY requirements.txt ./

# Install Python dependencies
RUN pip3 install --no-cache-dir -r requirements.txt

# --- Stage 4: Final Application Image ---
FROM php:8.2-fpm

WORKDIR /var/www

# Install essential system dependencies (needed at runtime)
RUN apt-get update && apt-get install -y \
    libmagickwand-dev \
    python3 \
    libgl1-mesa-glx \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (same as base, needed at runtime)
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip fileinfo
RUN pecl install imagick && docker-php-ext-enable imagick

# Copy application code
COPY . .

# Copy installed vendor dependencies from the 'vendor' stage
COPY --from=vendor /var/www/vendor ./vendor

# Copy installed Python dependencies from the 'python_deps' stage
# We need to find where pip installs packages and copy them.
# Usually /usr/local/lib/python3.x/site-packages/
# Let's assume Python 3.10 or higher (common with PHP 8.2 base images)
# You might need to adjust this path based on the actual Python version in the base image.
COPY --from=python_deps /usr/local/lib/python3.11/site-packages /usr/local/lib/python3.11/site-packages

# Copy composer binary (optional, but can be useful)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set permissions for Laravel storage and cache
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
RUN chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Run Laravel post-install scripts (if any)
# RUN composer run-script post-autoload-dump # Uncomment if needed
# RUN php artisan key:generate --force # Be careful with this in production
RUN php artisan config:cache
RUN php artisan route:cache
RUN php artisan view:cache

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]
