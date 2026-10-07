FROM php:8.2-apache

# Install official lightweight PHP extension installer (avoids compilation race conditions & high RAM)
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install required extensions cleanly
RUN install-php-extensions mysqli pdo_mysql gd zip

# Enable Apache mod_rewrite & headers
RUN a2enmod rewrite headers

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

# Copy runtime entrypoint script to dynamically configure $PORT
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
