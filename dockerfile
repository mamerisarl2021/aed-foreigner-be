FROM php:8.1-fpm

# Set working directory
WORKDIR /var/www

# Add docker php ext repo
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install php extensions
RUN chmod +x /usr/local/bin/install-php-extensions && sync && \
    install-php-extensions mbstring pdo_mysql zip exif pcntl gd memcached

# Install dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    locales \
    zip \
    jpegoptim optipng pngquant gifsicle \
    unzip \
    git \
    curl \
    lua-zlib-dev \
    libmemcached-dev \
    nginx

# Install supervisor
RUN apt-get install -y supervisor

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Add user for laravel application
RUN groupadd -g 1000 www
RUN useradd -u 1000 -ms /bin/bash -g www www

# Copy code to a writable directory
COPY --chown=www:www-data . /tmp/app

# Move the files to the desired directory
RUN mv /tmp/app/* /var/www && \
    mv /tmp/app/.* /var/www || true && \
    rm -rf /tmp/app

# add root to www group
RUN chmod -R ug+w /var/www/storage


# Copy nginx/php/supervisor configs
RUN cp docker/supervisor.conf /etc/supervisord.conf
RUN cp docker/php.ini /usr/local/etc/php/conf.d/app.ini
RUN cp docker/nginx.conf /etc/nginx/sites-enabled/default
RUN cp .env.prod .env 
RUN cp docker/keys/aed-private.key /var/www/storage/aed-private.key
RUN cp docker/keys/aed-public.key /var/www/storage/aed-public.key

# PHP Error Log Files
RUN mkdir /var/log/php
RUN touch /var/log/php/errors.log && chmod 777 /var/log/php/errors.log

RUN docker-php-ext-install bcmath

# Deployment steps
RUN composer update --optimize-autoloader --no-dev
RUN chmod +x /var/www/docker/run.sh
RUN chmod 777 storage/ -R
# RUN echo "81.91.230.86 keycloak.mameribj.org" >> /etc/hosts
# RUN echo "31.207.38.163 of-collab.qcdigitalhub.com" >> /etc/hosts
# RUN echo "31.207.38.163 ws-collab.qcdigitalhub.com" >> /etc/hosts
# RUN mkdir /var/www/storage/app/docs /var/www/storage/app/logos /var/www/storage/app/stamps /var/www/storage/app/stamps/SIGNATURE /var/www/storage/app/stamps/VISA /var/www/storage/app/proofs  
RUN ls /var/www/storage/app

EXPOSE 80
ENTRYPOINT ["/var/www/docker/run.sh"]