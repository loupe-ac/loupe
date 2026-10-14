#!/bin/bash
set -euo pipefail

echo "Starting prod"

COMPOSER_ALLOW_SUPERUSER=1 composer dump-env prod

# /blog proxies to an external blog host when BLOG_PROXY_HOST is set. The
# resolver lets nginx look the host up per request, so a DNS failure at boot
# breaks /blog alone instead of the whole web container.
blog_conf=/etc/nginx/snippets/blog-proxy.conf
rm -f "$blog_conf"
if [ -n "${BLOG_PROXY_HOST:-}" ]; then
    if [[ ! "$BLOG_PROXY_HOST" =~ ^[A-Za-z0-9.-]+$ ]]; then
        echo "BLOG_PROXY_HOST must be a bare host name, got: $BLOG_PROXY_HOST" >&2
        exit 1
    fi
    resolver=$(awk '/^nameserver/ { print $2; exit }' /etc/resolv.conf)
    [[ "$resolver" == *:* ]] && resolver="[$resolver]"
    mkdir -p /etc/nginx/snippets
    cat > "$blog_conf" <<EOF
resolver $resolver valid=300s ipv6=off;

location = /blog {
    set \$blog_host $BLOG_PROXY_HOST;
    rewrite ^ / break;
    proxy_pass https://\$blog_host;
    proxy_set_header Host \$blog_host;
    proxy_set_header X-Forwarded-Host \$host;
    proxy_set_header X-Forwarded-Proto \$http_x_forwarded_proto;
    proxy_ssl_server_name on;
}

location ^~ /blog/ {
    set \$blog_host $BLOG_PROXY_HOST;
    rewrite ^/blog/(.*)\$ /\$1 break;
    proxy_pass https://\$blog_host;
    proxy_set_header Host \$blog_host;
    proxy_set_header X-Forwarded-Host \$host;
    proxy_set_header X-Forwarded-Proto \$http_x_forwarded_proto;
    proxy_ssl_server_name on;
}
EOF
fi

# Migrations are NOT run here — with several replicas, per-container
# migrations race against the same database. Run docker/prod/release.sh
# once per deploy instead.

exec "$@"
