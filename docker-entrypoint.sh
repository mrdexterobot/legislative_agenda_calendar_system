#!/bin/sh
set -e

# Default to 80 if PORT environment variable is not set by the hosting platform
PORT="${PORT:-80}"

# Dynamically update Apache configuration to listen on $PORT
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

if [ "$#" -eq 0 ]; then
    exec docker-php-entrypoint apache2-foreground
fi

exec docker-php-entrypoint "$@"
