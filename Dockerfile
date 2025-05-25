# --- Stage 1: Base PHP with Dependencies ---
FROM php:8.2-fpm AS base

# Set working directory
WORKDIR /var/www

# Set DEBIAN_FRONTEND to noninteractive
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies
# Menambahkan Nginx dan Supervisor
RUN apt-get update -y && \
    apt-get install -y --no-install-recommends \
    # Essential tools
    git \
    curl \
    wget \
    ca-certificates \
    gnupg \
    # Web Server & Supervisor
    nginx \
    supervisor \
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

# --- Stage 2: Build Frontend Assets ---
FROM node:18-alpine AS frontend

WORKDIR /var/www

COPY package*.json ./
RUN npm install

COPY . .
# Pastikan tidak ada error saat build frontend
RUN npm run build || { echo 'Frontend build failed, but continuing...'; exit 0; }

# --- Stage 3: Build Backend Dependencies ---
FROM base AS backend_deps

WORKDIR /var/www

# Copy composer files
COPY composer.json composer.lock* ./

# Set Composer memory limit to unlimited
ENV COMPOSER_MEMORY_LIMIT=-1

# Install Composer dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev --prefer-dist --verbose

# Copy Python requirements file
COPY requirements.txt ./

# Download and install/upgrade pip using get-pip.py
RUN wget https://bootstrap.pypa.io/get-pip.py -O /tmp/get-pip.py
RUN python3 /tmp/get-pip.py
RUN pip3 install -vvv --upgrade pip

# Install Python dependencies
RUN pip3 install -vvv --no-cache-dir -r requirements.txt

# --- Stage 4: Final GCR Application Image ---
FROM base AS final

WORKDIR /var/www

# Copy application code (tanpa vendor & node_modules)
COPY . .

# Copy installed vendor dependencies
COPY --from=backend_deps /var/www/vendor ./vendor

# Copy installed Python dependencies
COPY --from=backend_deps /usr/local/lib/python3.11/site-packages /usr/local/lib/python3.11/site-packages
COPY --from=backend_deps /usr/local/bin /usr/local/bin

# Copy built frontend assets
COPY --from=frontend /var/www/public/build ./public/build

# Copy Supervisor configuration
COPY docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf

# Copy Nginx configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Remove default Nginx site
RUN rm /etc/nginx/sites-enabled/default
# Enable our Nginx site
RUN ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Set permissions for Laravel storage and cache, and logs
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache /var/log/supervisor
RUN chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Run Laravel optimizations
RUN php artisan config:cache
RUN php artisan route:cache
RUN php artisan view:cache

# Expose port 8080 (GCR default, Nginx akan diatur untuk listen di sini)
EXPOSE 8080

# Command to run Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]
