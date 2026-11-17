---
title: "Database connections"
description: "How many database connections the web and the worker keep open, and how to count them against your database plan."
---

Each web process keeps its database connections open between requests. A new connection to a managed Postgres costs about 60 to 100 ms, so keeping it saves that time on every request.

## What stays open

The web container runs PHP-FPM with up to five processes. Each process keeps up to two connections:

- One for the application, named `loupe-web`.
- One for the session, named `loupe-session`.

One web container therefore holds at most 10 connections. The worker container holds 3, one for each consumer. The console, the migration job and the tests open a normal connection and close it when they end.

## Check the connection limit

Add up the connections of every instance that uses the database:

1. 10 for each web container.
2. 3 for the worker container.
3. 1 for the migration job that runs during a deploy.
4. The connections of any other app on the same cluster.

The sum must stay below the connection limit of your database plan. DigitalOcean allows 22 connections on the 1 GB plan, 47 on the 2 GB plan and 97 on the 4 GB plan. Two web containers need 23 connections, which is one too many for the 1 GB plan.

## See the connections

Run this query to count the connections by name:

```sql
select application_name, count(*) from pg_stat_activity group by 1;
```

Expect up to five `loupe-web` rows and up to five `loupe-session` rows for each web container.

## Clean state

When a request first uses a kept connection, the application resets it with `DISCARD ALL` and checks that it is alive. When the database closed the connection, for example after a restart, the application connects again once. A request that never uses the database leaves the kept connection as it is.
