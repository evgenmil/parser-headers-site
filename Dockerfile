FROM composer:latest

WORKDIR /usr/src/app

COPY composer.json composer.lock ./

RUN composer install --no-interaction --no-plugins --no-scripts --prefer-dist

COPY index.php .
COPY .env .

CMD ["mkdir", "./positions"]
CMD ["php", "./index.php"]