FROM php:8.3-fpm-alpine

# Instalar Nginx e extensoes PHP necessarias
RUN apk add --no-cache nginx postgresql-dev supervisor \
    && docker-php-ext-install pdo pdo_pgsql

# Configurar PHP-FPM
RUN sed -i 's/listen = 127.0.0.1:9000/listen = \/run\/php-fpm.sock/' /usr/local/etc/php-fpm.d/www.conf \
    && echo "listen.owner = nginx" >> /usr/local/etc/php-fpm.d/www.conf \
    && echo "listen.group = nginx" >> /usr/local/etc/php-fpm.d/www.conf \
    && echo "listen.mode = 0660" >> /usr/local/etc/php-fpm.d/www.conf

# PHP production settings
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && echo "expose_php = Off" >> /usr/local/etc/php/php.ini \
    && echo "session.cookie_httponly = 1" >> /usr/local/etc/php/php.ini \
    && echo "session.cookie_secure = 1" >> /usr/local/etc/php/php.ini \
    && echo "session.cookie_samesite = Strict" >> /usr/local/etc/php/php.ini \
    && echo "session.use_strict_mode = 1" >> /usr/local/etc/php/php.ini \
    && echo "session.use_only_cookies = 1" >> /usr/local/etc/php/php.ini

# Copiar configuracao Nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copiar configuracao Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copiar aplicacao
WORKDIR /var/www/html
COPY . .

# Permissoes
RUN chown -R nginx:nginx /var/www/html \
    && mkdir -p /run/nginx /run/php-fpm /var/log/supervisor

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
