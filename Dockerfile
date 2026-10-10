# CloudDesk Web — Docker image for Render deployment.
# Uses PHP's official Apache image; connects to Render's managed PostgreSQL
# via the DATABASE_URL environment variable (see config/db.php).

FROM php:8.2-apache

# PDO drivers for both Postgres (Render) and MySQL (kept in case you ever
# point this image at a MySQL database instead).
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache needs mod_rewrite off/on isn't required here since this app uses
# plain .php URLs, but enabling it is harmless if you add pretty URLs later.
RUN a2enmod rewrite

COPY . /var/www/html/

# The uploads folder needs to be writable by the web server.
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads

# Render assigns a dynamic port via the $PORT env var and expects the
# container to listen on it — Apache's default config hardcodes port 80,
# so this entrypoint rewrites the config at container startup.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
