# Use official PHP image with Apache
FROM php:8.2-apache

# Enable Apache rewrite module
RUN a2enmod rewrite

# Install required extensions
RUN docker-php-ext-install pdo pdo_mysql

# Copy project files
COPY . /var/www/html

# Set working directory
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Expose port 80 (Render maps automatically)
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
