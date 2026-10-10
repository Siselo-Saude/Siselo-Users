
FROM php:8.1-apache

ENV FRONTEND_ORIGIN=http://localhost:3000
ENV COMPOSER_ALLOW_SUPERUSER=1

# Instala bibliotecas necessárias para o PHP e o Dompdf
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 \
        mysqli \
        pdo \
        pdo_mysql \
        gd \
        mbstring \
        dom \
    && rm -rf /var/lib/apt/lists/*

# Habilita reescrita de URLs do Apache
RUN a2enmod rewrite

# Copia o Composer da imagem oficial
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Define o diretório do SIS ELO
WORKDIR /var/www/html

# Copia os arquivos do projeto
COPY . /var/www/html/

# Instala as dependências PHP, incluindo Dompdf
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Configura o Apache para servir a pasta public
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/>|<Directory /var/www/html/public>|g' /etc/apache2/apache2.conf \
    && sed -i '/<Directory \/var\/www\/html\/public>/,/<\/Directory>/ s|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Evita mostrar detalhes internos de erros PHP no navegador
RUN printf 'display_errors=Off\nlog_errors=On\nexpose_php=Off\n' \
    > /usr/local/etc/php/conf.d/zz-siselo.ini

# Ajusta permissões dos arquivos
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80
