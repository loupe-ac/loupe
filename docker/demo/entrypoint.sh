#!/bin/bash
set -euo pipefail

# Boots the whole instance: Postgres, schema, an administrator to log in as,
# then Supervisor. Supervisor has no dependency ordering, so the database is
# started here, used, and stopped again before it hands over the long-lived one.
#
# Running migrations at start-up is what docker/prod/entrypoint.sh refuses to
# do, because concurrent replicas race each other against one database. This
# image is one container that is its own database, so there is nothing to race.

SOCKET_DIR=/run/postgresql
install -d -o postgres -g postgres -m 0775 "$SOCKET_DIR"

# In the entrypoint rather than the Dockerfile: a fresh named volume mounted
# over PGDATA arrives root-owned, and initdb refuses to run on that.
install -d -o postgres -g postgres -m 0700 "$PGDATA"

if [ ! -s "$PGDATA/PG_VERSION" ]; then
	echo "Initialising the database…"
	su-exec postgres initdb --username=loupe --auth=trust --encoding=UTF8 -D "$PGDATA" >/dev/null
	# Loopback only. Nothing outside the container can reach it, which is why
	# trust authentication above is not a hole and the password in DATABASE_URL
	# is never checked.
	echo "listen_addresses = '127.0.0.1'" >>"$PGDATA/postgresql.conf"
	echo "host all all 127.0.0.1/32 trust" >>"$PGDATA/pg_hba.conf"
fi

su-exec postgres pg_ctl -D "$PGDATA" -w -o "-c listen_addresses=127.0.0.1" start >/dev/null
su-exec postgres psql -h 127.0.0.1 -U loupe -d postgres -tc \
	"SELECT 1 FROM pg_database WHERE datname = 'loupe'" | grep -q 1 ||
	su-exec postgres createdb -h 127.0.0.1 -U loupe loupe

# APP_SECRET: a value passed with `docker run -e` wins. Otherwise the secret
# lives beside the database, so it lasts as long as the data it signs. It is
# written to a temporary file and renamed, so a stopped boot never leaves a
# partial secret behind.
secret_file="$PGDATA/loupe-demo-app-secret"
if [ -z "${APP_SECRET:-}" ]; then
	if [ -f "$secret_file" ] && [[ "$(cat "$secret_file")" =~ ^[0-9a-f]{32}$ ]]; then
		APP_SECRET="$(cat "$secret_file")"
	else
		[ -e "$secret_file" ] && echo "Stored APP_SECRET is not valid, replacing it."
		APP_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')"
		(
			umask 077
			printf '%s' "$APP_SECRET" >"$secret_file.tmp"
		)
		[[ "$(cat "$secret_file.tmp")" =~ ^[0-9a-f]{32}$ ]]
		mv "$secret_file.tmp" "$secret_file"
	fi
fi
export APP_SECRET

# Before every console call: .env.local.php holds only the values of the .env
# files, and the runtime environment (APP_SECRET above) overrides it.
COMPOSER_ALLOW_SUPERUSER=1 composer dump-env prod >/dev/null

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# The sanctioned first account — verified without sending mail, which matters
# because MAILER_DSN is null here. Creating it also closes the install wizard,
# so an unattended instance is never left minting admins for whoever finds it.
# Idempotent, so a restart over a persisted volume changes nothing. The password
# is a candidate: an existing account keeps its own.
admin_password="${DEMO_ADMIN_PASSWORD:-$(php -r 'echo bin2hex(random_bytes(12));')}"
admin_output="$(php bin/console app:admin:create "$DEMO_ADMIN_EMAIL" \
	--password="$admin_password" --full-name="Loupe Admin" --no-interaction)"
echo "$admin_output"
admin_created=false
if [[ "$admin_output" == *"Created administrator"* ]]; then
	admin_created=true
fi

su-exec postgres pg_ctl -D "$PGDATA" -w -m fast stop >/dev/null

# Only the boot that created the account knows its password. Nothing stores it,
# so a later boot cannot show it.
if [ "$admin_created" = true ]; then
	credentials="${DEMO_ADMIN_EMAIL} / ${admin_password}"
else
	credentials="${DEMO_ADMIN_EMAIL}, with the password printed when the account was created.
  Lost it? Run: docker exec <container> bin/console app:admin:create <another-email>"
fi

cat <<BANNER

  Loupe is starting at ${DEFAULT_URI}
  Log in with ${credentials}

  Demo image: mail is discarded, so registration and password reset do
  not work, and the database is lost with the container unless you mounted a
  volume at ${PGDATA}. See docs/operating/install/docker-compose.md before running this for real.

BANNER

exec "$@"
