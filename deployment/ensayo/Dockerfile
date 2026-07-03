# Ensayo local del deploy cPanel — NO es artefacto de producción.
#
# Imita el runtime del hosting: Apache + PHP 8.4 + mod_rewrite + .htaccess
# habilitados, docroot plano tipo public_html/ donde se monta el ZIP de deploy
# extraído (build/ensayo-docroot/limpieza/...). Ver docs/deploy-cpanel.md.
FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
