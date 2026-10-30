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
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    zip \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Configuração e instalação de extensões
# GD (spec 065): sem ela a logo não sai no cupom — o escpos-php precisa de GD ou Imagick
# para ler a imagem. Imagick seria bem mais pesada.
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql mbstring xml zip gd

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
COPY bin/ ./bin/

# O classmap otimizado só inclui App\ depois que src/ foi copiado (spec 066).
RUN composer dump-autoload --optimize --no-dev --no-scripts

# Dados que sobrevivem à troca de imagem (spec 066): a logo enviada e o log ficam em
# volumes montados aqui no compose de produção. Criados com dono www-data para que o
# volume novo já nasça gravável pelo Apache e pelo print-worker; app.log já existe para
# que um `docker compose exec` como root nunca o crie com dono root.
RUN mkdir -p public/uploads logs \
    && touch logs/app.log \
    && chown -R www-data:www-data /var/www/html

# Permissões (spec 066): antes `chmod -R 755` marcava todo arquivo como executável.
# vendor/ fica como o Composer instalou.
RUN find public src common bin logs -type d -exec chmod 755 {} + \
    && find public src common logs -type f -exec chmod 644 {} + \
    && chmod 755 bin/*

# Timezone do servidor: America/Sao_Paulo (UTC-3)
ENV TZ=America/Sao_Paulo
RUN ln -sfn /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime \
    && echo 'date.timezone=America/Sao_Paulo' > /usr/local/etc/php/conf.d/timezone.ini

# Defesa em profundidade contra o CVE-2026-109619 (spec 059): o GastroFlow não usa
# sessões PHP (auth é JWT), então o upload progress de sessão só aumenta a superfície.
RUN echo 'session.upload_progress.enabled=0' > /usr/local/etc/php/conf.d/zz-security.ini

# Versão servida em /version (spec 066): a imagem de produção não tem .git, então a
# versão de que ela foi construída fica gravada aqui. Por último, para não invalidar o
# cache das camadas acima a cada versão.
ARG GASTROFLOW_VERSION=""
RUN printf '%s' "$GASTROFLOW_VERSION" > VERSION

EXPOSE 80