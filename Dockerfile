FROM php:8.2-apache

# Install system dependencies & PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libgmp-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli pdo_mysql gd gmp zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite & headers
RUN a2enmod rewrite headers

# Configure Apache to listen on dynamic $PORT provided by Render (defaults to 80)
RUN sed -i 's/Listen 80/Listen ${PORT:-80}/g' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:${PORT:-80}>/g' /etc/apache2/sites-available/000-default.conf

# Set Apache DocumentRoot configuration for clean URL rewriting and security
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/atsede.conf \
    && a2enconf atsede

# Copy source code
COPY . /var/www/html/

# Permissions & ensure runtime directories exist
RUN mkdir -p /var/www/html/uploads/covers /var/www/html/uploads/avatars /var/www/html/logs /var/www/html/tmp \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads /var/www/html/logs /var/www/html/tmp

EXPOSE 80
CMD ["apache2-foreground"]
