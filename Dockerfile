FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4 libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && apt-get purge -y --auto-remove libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

WORKDIR /var/www/html

COPY . /var/www/html/

# A fresh clone does not contain the gitignored runtime config. Use the
# environment-aware sample in that case; a local config.php still wins.
RUN if [ ! -f /var/www/html/includes/config.php ]; then \
        cp /var/www/html/includes/config.sample.php /var/www/html/includes/config.php; \
    fi

# PHP must be able to write uploaded evidence and generated debug files.
RUN mkdir -p /var/www/html/uploads/evidence \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/includes \
    && chmod -R ug+rwX /var/www/html/uploads /var/www/html/includes

# Allow .htaccess overrides and configure entrypoint
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && cp /var/www/html/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
