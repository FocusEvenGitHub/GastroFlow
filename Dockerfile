# Versão de patch fixada de propósito (spec 059): 8.2.35 corrige o PHP2Shell
# (CVE-2026-109618 / CVE-2026-109619), e mod_php — este setup — é o cenário de maior
# risco. Com só `8.2-apache`, uma imagem antiga em cache continuaria sendo usada.
# Para atualizar: `docker compose build --pull web print-worker` e recriar os containers.
FROM php:8.2.35-apache

# 1. Adicionado 'git' na lista de dependências
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    libzip-dev \
    zlib1g-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Configuração e instalação de extensões
RUN docker-php-ext-install pdo pdo_mysql mbstring xml zip

RUN a2enmod rewrite

# Ajuste do DocumentRoot para a pasta public do Laravel/Framework
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf \
    && sed -i '/<Directory \/var\/www\/html\/public>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Instalação do Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 3. Copiar composer.json E composer.lock (evita conflitos de versão)
COPY composer.json composer.lock* ./

# Rodar o install (agora com git e unzip instalados, não deve falhar)
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

# Copiar o restante dos arquivos
COPY public/ ./public/
COPY src/ ./src/
COPY common/ ./common/
COPY legacy/ ./legacy/
COPY bin/ ./bin/

# Ajustar permissões finais
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Timezone do servidor: America/Sao_Paulo (UTC-3)
ENV TZ=America/Sao_Paulo
RUN ln -sfn /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime \
    && echo 'date.timezone=America/Sao_Paulo' > /usr/local/etc/php/conf.d/timezone.ini

# Defesa em profundidade contra o CVE-2026-109619 (spec 059): o GastroFlow não usa
# sessões PHP (auth é JWT), então o upload progress de sessão só aumenta a superfície.
RUN echo 'session.upload_progress.enabled=0' > /usr/local/etc/php/conf.d/zz-security.ini

EXPOSE 80