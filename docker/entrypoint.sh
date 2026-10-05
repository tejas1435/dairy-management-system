#!/bin/sh

echo "Running Laravel migrations..."

php artisan config:clear
php artisan migrate --force

echo "Starting application..."

exec /start.sh