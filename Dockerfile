FROM alpine:3.24

WORKDIR /var/www/html

# Install packages and remove default server definition
RUN apk add --no-cache \
  curl \
  nginx \
  php84 \
  php84-curl \
  php84-dom \
  php84-fpm \
  php84-mbstring \
  php84-opcache \
  php84-pdo \
  php84-pdo_sqlite \
  php84-openssl \
  php84-xml \
  yt-dlp \
  supervisor

RUN ln -s /usr/bin/php84 /usr/bin/php

# Configure nginx - http
COPY config/nginx.conf /etc/nginx/nginx.conf
# Configure nginx - default server
COPY config/conf.d /etc/nginx/conf.d/

# Configure PHP-FPM
ENV PHP_INI_DIR /etc/php84
COPY config/fpm-pool.conf ${PHP_INI_DIR}/php-fpm.d/www.conf
COPY config/php.ini ${PHP_INI_DIR}/conf.d/custom.ini

# Configure supervisord
COPY config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Make sure files/folders needed by the processes are accessable when they run under the nobody user
RUN mkdir -p /var/lib/proxatore \
  && chown -R nobody:nobody /var/www/html /run /var/lib/nginx /var/log/nginx /var/lib/proxatore

# History DB + media cache; bind-mount a host dir owned by uid 65534 (nobody) here to persist it
VOLUME /var/lib/proxatore

# Switch to use a non-root user from here on
USER nobody

# Add application
COPY --chown=nobody src/ /var/www/html/

# Expose the port nginx is reachable on
EXPOSE 8080

# Let supervisord start nginx & php-fpm
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Configure a healthcheck to validate that everything is up&running
HEALTHCHECK --timeout=10s CMD curl --silent --fail http://127.0.0.1:8080/fpm-ping || exit 1
