#!/bin/sh

cd /var/www

# php artisan queue:table
# php artisan migrate:fresh --seed --force
# php artisan passport:install --force
php artisan cache:clear
php artisan route:cache

/usr/bin/supervisord -c /etc/supervisord.conf