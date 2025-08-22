FROM php:8.2-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    autoconf \
    build-base \
    linux-headers \
    git \
    unzip

# Install PHP extensions
RUN docker-php-ext-install pcntl

# Install Redis extension
RUN pecl install redis igbinary \
    && docker-php-ext-enable redis igbinary

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer.json ./

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy application code
COPY . .

CMD ["php", "bin/benchmark.php"]
