FROM php:8.4-fpm AS base

# 1. 필수 시스템 도구 설치 (git, unzip 등)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. PHP 확장 설치 도구 가져오기 (이게 에러 방지 치트키야!)
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

# 3. 라라벨에 필요한 PHP 확장만 골라서 설치 (이름 틀릴 걱정 없음)
RUN install-php-extensions gd pdo_sqlite bcmath zip intl opcache exif pcntl

# 4. Composer 설치
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# 의존성 설치 (에러 방지를 위해 잠시 --no-dev 제외하고 설치해볼게)
RUN composer install --optimize-autoloader

# ---- Node.js 빌드 단계 (Vite용) ----
FROM node:18-alpine AS node_modules_go_brrr
WORKDIR /app
COPY . .
RUN npm install && npm run build

# ---- 최종 이미지 합치기 ----
FROM base
COPY --from=node_modules_go_brrr /app/public /var/www/html/public

RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p database storage bootstrap/cache \
    && touch database/database.sqlite \
    && chown -R www-data:www-data database storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
