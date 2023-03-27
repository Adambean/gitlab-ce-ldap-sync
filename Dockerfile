FROM mlocati/php-extension-installer:latest AS installer
FROM php:7.4.9-cli-alpine3.12
# USER root

COPY --from=installer /usr/bin/install-php-extensions /usr/bin/

RUN apk add --no-cache bash curl tini\
    && rm -rf /var/cache/apk/* \
    && install-php-extensions ldap \
    && mkdir -p /app
    # && chown -R www-data:www-data /app
# Install PHP extensions
# RUN install-php-extensions ldap

WORKDIR /app
COPY . .
# USER www-data

# INSTALL COMPOSER
#     && git clone git@github.com:Adambean/gitlab-ce-ldap-sync.git /app \
SHELL ["/bin/ash", "-eo", "pipefail", "-c"]
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer \
    && composer install

ADD ./docker /tmp/docker
RUN cp /tmp/docker/entrypoint.sh /entrypoint.sh \
    && chmod +x /entrypoint.sh \
    && cp /tmp/docker/healthcheck.sh /healthcheck.sh \
    && chmod +x /healthcheck.sh \
    && cp /tmp/docker/cron_task.sh /cron_task.sh \
    && chmod +x /cron_task.sh \
    && cp /tmp/docker/example_config.yml /app/example_config.yml \
    && rm -rf /tmp/docker


ENTRYPOINT ["tini", "--", "/entrypoint.sh"]

HEALTHCHECK --timeout=5s CMD ["/healthcheck.sh"]

# CMD ["update-ca-certificates", "&&", "php", "bin/console", "ldap:sync"]
