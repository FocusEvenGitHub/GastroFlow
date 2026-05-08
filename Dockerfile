FROM php:8.2-apache

# Dependências e extensões
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev zlib1g-dev libonig-dev libxml2-dev zip unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure zip --with-zip
RUN docker-php-ext-install pdo pdo_mysql mbstring xml zip

RUN a2enmod rewrite
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf \
    && sed -i '/<Directory \/var\/www\/html\/public>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar apenas o composer.json primeiro para instalar dependências
COPY composer.json ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Copiar cada pasta importante explicitamente
COPY public/ ./public/
COPY src/ ./src/
COPY common/ ./common/
COPY legacy/ ./legacy/
COPY .env ./

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html/public

EXPOSE 80