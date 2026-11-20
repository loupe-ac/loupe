#!/usr/bin/env php
<?php

declare(strict_types=1);

// Prints the database sessions that run a statement or wait for a lock, with
// how long each has sat in its state. A hung test run shows its blocker here.

$url = parse_url((string) getenv('DATABASE_URL'));
if (!\is_array($url) || !isset($url['host'])) {
    fwrite(STDERR, "coverage-activity: DATABASE_URL is not set\n");
    exit(1);
}

$pdo = new PDO(
    \sprintf('pgsql:host=%s;port=%d;dbname=postgres', $url['host'], $url['port'] ?? 5432),
    rawurldecode($url['user'] ?? ''),
    rawurldecode($url['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$rows = $pdo->query(
    "SELECT pid, datname, state, wait_event_type, wait_event, pg_blocking_pids(pid) AS blocked_by,
            date_trunc('second', now() - state_change) AS in_state, left(query, 140) AS query
     FROM pg_stat_activity
     WHERE pid <> pg_backend_pid() AND datname IS NOT NULL
       AND (state <> 'idle' OR wait_event_type = 'Lock')
     ORDER BY state_change",
);
\assert(false !== $rows);
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, \JSON_UNESCAPED_SLASHES), "\n";
}
