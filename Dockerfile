FROM mlocati/php-extension-installer:latest AS installer
FROM php:8.1.33-cli-alpine3.21

COPY --from=installer /usr/bin/install-php-extensions /usr/bin/

RUN apk add --no-cache bash curl && \
    rm -rf /var/cache/apk/*

# Install PHP extensions
RUN install-php-extensions ldap

# INSTALL COMPOSER
SHELL ["/bin/ash", "-eo", "pipefail", "-c"]
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /app
COPY . .

RUN composer install

CMD ["update-ca-certificates", "&&", "php", "bin/console", "ldap:sync"]
