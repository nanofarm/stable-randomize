FROM php:8.4-fpm

# Системные зависимости
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP upload/memory limits
RUN echo "upload_max_filesize = 10M\npost_max_size = 12M\nmemory_limit = 256M" > /usr/local/etc/php/conf.d/uploads.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Сначала копируем composer-файлы для кэширования слоёв
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist 2>/dev/null || true

# Копируем весь код
COPY . .

# Чистим старый кэш провайдеров (может содержать ссылки на dev-пакеты)
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php

# Если это свежий проект — устанавливаем всё
RUN composer install --no-dev --optimize-autoloader --prefer-dist

# Права
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
