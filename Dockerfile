FROM php:8.4-apache

# Dependencias del sistema + extensiones PHP
RUN apt-get update && apt-get install -y \
        libsqlite3-dev \
        libicu-dev \
        libonig-dev \
        git \
        unzip \
    && docker-php-ext-install pdo pdo_sqlite mbstring intl \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite para el front-controller
RUN a2enmod rewrite

# Apuntar DocumentRoot a /var/www/html/public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

# Permitir .htaccess en el directorio público
RUN echo '<Directory "${APACHE_DOCUMENT_ROOT}">\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/app.conf && a2enconf app

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar todo el código
COPY . .

# Instalar dependencias PHP (sin dev)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Directorio de datos persistente (montar volumen en Easypanel → /data)
RUN mkdir -p /data && chown www-data:www-data /data

# Permisos del código
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/database

# Script de entrada: inicializa la BD y arranca Apache
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/docker-entrypoint.sh"]
