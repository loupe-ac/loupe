#!/bin/bash
set -euo pipefail

echo "Starting prod"

COMPOSER_ALLOW_SUPERUSER=1 composer dump-env prod

# The web container puts the site bar and footer of this instance into the built
# docs. A failed stamp stops the start, so nginx never serves a page that still
# holds a marker or a link to a wrong host. The worker and the release job share
# this entrypoint and serve no docs, so they skip the stamp.
if [ -n "${SITE_HOST:-}" ] && [ -d /var/www/docs-build ] && [[ "$*" == *" /etc/supervisord.conf" ]]; then
    su-exec www-data bin/console app:site:stamp-docs
fi

# nginx-config.sh writes the blog forward, the docs location and, when the site
# has a host of its own, the site server.
"$(dirname "$0")/nginx-config.sh"

# Migrations are NOT run here — with several replicas, per-container
# migrations race against the same database. Run docker/prod/release.sh
# once per deploy instead.

exec "$@"
