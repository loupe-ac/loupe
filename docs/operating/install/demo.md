---
title: "Demo"
description: "One container holding the app, worker and Postgres. For evaluating Loupe, not for running it."
---


```bash
docker run --rm -p 127.0.0.1:8080:80 ghcr.io/loupe-ac/loupe:demo
```

Then open <http://localhost:8080> and log in with `admin@example.com` and the
password that the start message prints in the container log. The container makes
that password when it creates the administrator, and prints it once. That is the whole setup — app, background worker and Postgres in
a single container, with an administrator already created. No clone, no `just`,
no database to provision, no reverse proxy. Built for `linux/amd64` and
`linux/arm64`.

Bind to `127.0.0.1` as above. The demo has no TLS and discards mail, so a demo
bound to every interface is not safe to leave on a shared network.

Each container makes its own `APP_SECRET` and its own admin password at boot. Pass
`-e APP_SECRET=…` or `-e DEMO_ADMIN_PASSWORD=…` to set your own. With a volume,
the secret stays beside the database. A volume made by an older image keeps the
old password `loupe-admin`, so change it in the app.

If you lose the password, make a second administrator:

```bash
docker exec <container> bin/console app:admin:create <another-email>
```

The image is for evaluating Loupe, **not for running it**. Nothing terminates TLS
in front of it, and mail is discarded — so registration and password reset do not work, and the seeded
administrator is the only way in. The database goes with the container unless
you keep it:

```bash
docker run --rm -p 127.0.0.1:8080:80 \
  -v loupe-demo:/var/lib/postgresql/data ghcr.io/loupe-ac/loupe:demo
```

Serving it on another port needs `DEFAULT_URI` to agree, or the links it
generates point at the wrong one:

```bash
docker run --rm -p 127.0.0.1:9000:80 \
  -e DEFAULT_URI=http://localhost:9000 ghcr.io/loupe-ac/loupe:demo
```

From a clone, `just demo` builds the image and runs it with all of that already
set. To run Loupe for real, see [Single-host Docker Compose](docker-compose.md) — `docker/compose/prod.yaml`
is a complete single-host stack.
