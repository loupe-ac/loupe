FROM php:8.5-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev libzip-dev libxslt-dev bash icu-data-full linux-headers git

RUN docker-php-ext-install -j"$(nproc)" bcmath pdo pdo_pgsql zip intl xsl

# Built from the xdebug.org tarball without pecl, so the build never contacts
# pecl.php.net. `pecl install <url>` still fetches that host's channel.xml, and a
# 504 there failed a CI image build on 2026-09-12.
RUN mkdir -p /usr/src/xdebug \
    && curl -fsSL https://xdebug.org/files/xdebug-3.5.3.tgz | tar -xz -C /usr/src/xdebug --strip-components=1 \
    && docker-php-ext-install -j"$(nproc)" /usr/src/xdebug

# The design system export bundles its components with esbuild. The version
# matches the esbuild dev dependency in package.json.
RUN apk add --no-cache nodejs npm \
    && npm install --global esbuild@0.28.2 \
    && npm cache clean --force

# bin/rotate-secrets reads tfvars with terraform console and escapes values with
# jq, and its PHPUnit test runs both. The zip is checked against a pinned SHA-256.
ARG TARGETARCH
ARG TERRAFORM_VERSION=1.15.6
ARG TERRAFORM_SHA256_AMD64=a7150d3b0e1b5c466ad42e8c499954a3c54645f8b56b385fa025d34f7e88faa9
ARG TERRAFORM_SHA256_ARM64=404c9cfa43728d31005f6e7a848b8a7cc701320067d9d87d8850031a5beb37b0
RUN apk add --no-cache jq unzip \
    && case "$TARGETARCH" in \
        amd64) sha="$TERRAFORM_SHA256_AMD64" ;; \
        arm64) sha="$TERRAFORM_SHA256_ARM64" ;; \
        *) echo "unsupported TARGETARCH '$TARGETARCH'" >&2; exit 1 ;; \
    esac \
    && curl -fsSL -o /tmp/terraform.zip "https://releases.hashicorp.com/terraform/${TERRAFORM_VERSION}/terraform_${TERRAFORM_VERSION}_linux_${TARGETARCH}.zip" \
    && echo "$sha  /tmp/terraform.zip" | sha256sum -c - \
    && unzip -q /tmp/terraform.zip terraform -d /usr/local/bin \
    && rm /tmp/terraform.zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY docker/dev/php-fpm/zz-pm.conf /usr/local/etc/php-fpm.d/zz-pm.conf
COPY docker/dev/php-fpm/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/dev/php-fpm/memory.ini /usr/local/etc/php/conf.d/zz-memory.ini
COPY docker/dev/php-fpm/upload.ini /usr/local/etc/php/conf.d/zz-upload.ini
