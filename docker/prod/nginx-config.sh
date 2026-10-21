#!/bin/bash
# Writes the parts of the nginx configuration that depend on the environment.
# The static parts are docker/prod/nginx.conf and docker/prod/nginx-common.conf.
#
#   BLOG_PROXY_HOST  forward /blog to this outside host
#   SITE_HOST        the host of the public site; with a host that differs from
#                    the host of DEFAULT_URI it gets a server block of its own
#   DOCS_ROOT        the stamped docs; /docs/ is served when the folder holds
#                    index.html and SITE_HOST is set
set -euo pipefail

snippets=${NGINX_SNIPPET_DIR:-/etc/nginx/snippets}
http_dir=${NGINX_HTTP_DIR:-/etc/nginx/http.d}
docs_root=${DOCS_ROOT:-/var/www/docs}
resolv_conf=${RESOLV_CONF:-/etc/resolv.conf}

mkdir -p "$snippets"
rm -f "$snippets"/app-*.conf "$snippets"/site-*.conf "$http_dir/site.conf"

host_of() {
    local url=${1,,}
    url=${url#*://}
    url=${url%%[/?#]*}
    url=${url%%:*}
    printf '%s' "$url"
}

site_host=${SITE_HOST:-}
site_host=${site_host,,}
if [ -n "$site_host" ] && [[ ! "$site_host" =~ ^[a-z0-9.-]+$ ]]; then
    echo "SITE_HOST must be a bare host name, got: $SITE_HOST" >&2
    exit 1
fi
app_host=$(host_of "${DEFAULT_URI:-}")

# Where the blog forward and the docs go: the site server when the site has its
# own host, else the one server.
scope=app
split=false
if [ -n "$site_host" ] && [ "$site_host" != "$app_host" ]; then
    scope=site
    split=true
fi

if [ -n "${BLOG_PROXY_HOST:-}" ]; then
    if [[ ! "$BLOG_PROXY_HOST" =~ ^[A-Za-z0-9.-]+$ ]]; then
        echo "BLOG_PROXY_HOST must be a bare host name, got: $BLOG_PROXY_HOST" >&2
        exit 1
    fi
    # The resolver lets nginx look the host up per request, so a DNS failure at
    # boot breaks /blog alone instead of the whole web container. The empty
    # Cookie header keeps the session cookie away from the outside host.
    resolver=$(awk '/^nameserver/ { print $2; exit }' "$resolv_conf")
    [[ "$resolver" == *:* ]] && resolver="[$resolver]"
    cat > "$snippets/$scope-blog.conf" <<EOF
resolver $resolver valid=300s ipv6=off;

location = /blog {
    set \$blog_host $BLOG_PROXY_HOST;
    rewrite ^ / break;
    proxy_pass https://\$blog_host;
    proxy_set_header Host \$blog_host;
    proxy_set_header Cookie "";
    proxy_set_header X-Forwarded-Host \$host;
    proxy_set_header X-Forwarded-Proto \$http_x_forwarded_proto;
    proxy_ssl_server_name on;
}

location ^~ /blog/ {
    set \$blog_host $BLOG_PROXY_HOST;
    rewrite ^/blog/(.*)\$ /\$1 break;
    proxy_pass https://\$blog_host;
    proxy_set_header Host \$blog_host;
    proxy_set_header Cookie "";
    proxy_set_header X-Forwarded-Host \$host;
    proxy_set_header X-Forwarded-Proto \$http_x_forwarded_proto;
    proxy_ssl_server_name on;
}
EOF
fi

if [ -n "$site_host" ] && [ -f "$docs_root/index.html" ]; then
    cat > "$snippets/$scope-docs.conf" <<EOF
location = /docs {
    absolute_redirect off;
    return 301 /docs/;
}

location ^~ /docs/_astro/ {
    alias $docs_root/_astro/;
    add_header Cache-Control "public, immutable";
    add_header Strict-Transport-Security \$hsts_header always;
    access_log off;
}

location ^~ /docs/ {
    alias $docs_root/;
    index index.html;
    absolute_redirect off;
    try_files \$uri \$uri/ =404;
    add_header Cache-Control "public, max-age=300";
    add_header Strict-Transport-Security \$hsts_header always;
}
EOF
fi

if [ "$split" = true ]; then
    cat > "$http_dir/site.conf" <<EOF
# The public site. Every path but the docs, the assets and the blog goes to PHP
# with no file lookup, so the site guard of the app answers it.
server {
    listen 80;
    server_name $site_host;
    include /etc/nginx/snippets/common.conf;

    location = /landing/explainer.html {
    }

    include /etc/nginx/snippets/site-*.conf;

    location / {
        rewrite ^ /index.php last;
    }
}
EOF
fi
