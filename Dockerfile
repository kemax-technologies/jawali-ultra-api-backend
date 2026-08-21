FROM php:8.2-apache

# ── تثبيت الإضافات المطلوبة (PDO + PostgreSQL + JSON + mbstring) ────────────
RUN apt-get update && apt-get install -y \
        libpq-dev \
        unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && docker-php-ext-enable pdo_pgsql \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# تمرير ترويسة Authorization إلى PHP (ضروري لـ JWT) + تفعيل .htaccess (AllowOverride)
RUN { \
        echo '<Directory /var/www/html>'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/jawali-overrides.conf \
    && a2enconf jawali-overrides

# تمرير ترويسة Authorization لبيئة PHP (mod_php لا يمررها تلقائياً كـ CGI)
RUN echo 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' >> /etc/apache2/conf-available/jawali-overrides.conf

WORKDIR /var/www/html
COPY . /var/www/html

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "sed -i \"s/Listen .*/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:.*>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
