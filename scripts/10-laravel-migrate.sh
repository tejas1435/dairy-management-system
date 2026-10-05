#!/bin/bash

echo "Running Laravel migrations..."

/usr/local/bin/php /var/www/html/artisan config:clear
/usr/local/bin/php /var/www/html/artisan migrate --force