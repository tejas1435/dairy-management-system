FROM tangramor/nginx-php8-fpm:php8.4.16_node25.2.1

COPY . /var/www/html

WORKDIR /var/www/html

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

RUN npm ci
RUN npm run build

RUN chown -R nginx:nginx /var/www/html/storage /var/www/html/bootstrap/cache || true

ENV WEBROOT=/var/www/html/public
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

EXPOSE 80