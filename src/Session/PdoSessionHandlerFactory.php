<?php

declare(strict_types=1);

namespace App\Session;

use App\Observability\RequestTimeline;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Builds the session handler: a ReadOnlyAwareSessionHandler over one locking
 * and one non-locking PdoSessionHandler. Both connect lazily, so a request
 * opens one connection. That connection differs from DATABASE_URL in three
 * ways that each fail quietly if you undo them.
 *
 * The database name comes from Doctrine's connection parameters, not the URL
 * (and not Connection::getDatabase(), which runs a query): `dbname_suffix` appends
 * WORKTREE_DB_SUFFIX and _test<TEST_TOKEN>, so the raw URL names the main `app`
 * database — where `sessions` also exists, so a worktree shares main's logins
 * rather than erroring.
 *
 * A DSN, not a URL: PdoSessionHandler's URL parser drops the query string for
 * pgsql, so `sslmode` never reaches the connection.
 *
 * Its own connection, not Doctrine's PDO: it takes a transactional lock while
 * reading a session, which would nest inside the request's transaction. Under
 * PHP-FPM the connection is persistent. Its `application_name` differs from
 * Doctrine's, so PHP never hands both handlers one kept connection.
 */
final readonly class PdoSessionHandlerFactory
{
    /**
     * libpq keys worth carrying from DATABASE_URL onto the session connection.
     * Doctrine-only parameters (`serverVersion`, `charset`) are not libpq
     * keywords and would make the connection fail.
     *
     * @var list<string>
     */
    private const array FORWARDED_PARAMETERS = [
        'sslmode',
        'sslcert',
        'sslkey',
        'sslrootcert',
        'sslcrl',
        'connect_timeout',
    ];

    public const string APPLICATION_NAME = 'loupe-session';

    public function __construct(
        private Connection $connection,

        #[Autowire(env: 'DATABASE_URL')]
        private string $databaseUrl,
        private RequestStack $requestStack,
        private RequestTimeline $timeline,
        private string $sapi = \PHP_SAPI,
    ) {
    }

    public function __invoke(): ReadOnlyAwareSessionHandler
    {
        return new ReadOnlyAwareSessionHandler(
            $this->create(PdoSessionHandler::LOCK_TRANSACTIONAL),
            $this->create(PdoSessionHandler::LOCK_NONE),
            $this->requestStack,
            $this->timeline,
        );
    }

    /**
     * @param PdoSessionHandler::LOCK_* $lockMode
     */
    public function create(int $lockMode): PdoSessionHandler
    {
        $parts = $this->urlParts();

        $persistent = 'fpm-fcgi' === $this->sapi;
        $options = [
            'db_username' => rawurldecode($parts['user'] ?? ''),
            'db_password' => rawurldecode($parts['pass'] ?? ''),
            'lock_mode' => $lockMode,
        ];

        if (!$persistent) {
            return new SkipLockedPdoSessionHandler($this->sessionDsn(), $options);
        }

        $options['db_connection_options'] = [\PDO::ATTR_PERSISTENT => true];

        return new PersistentPdoSessionHandler($this->sessionDsn(), $options);
    }

    /**
     * A pgsql PDO DSN for the database Doctrine connected to.
     */
    public function sessionDsn(): string
    {
        $parts = $this->urlParts();

        $dsn = 'pgsql:';

        if (isset($parts['host']) && '' !== $parts['host']) {
            $dsn .= 'host='.$parts['host'].';';
        }

        if (isset($parts['port'])) {
            $dsn .= 'port='.$parts['port'].';';
        }

        $database = $this->connection->getParams()['dbname'] ?? '';
        $dsn .= 'dbname='.(is_string($database) ? $database : '').';';

        parse_str($parts['query'] ?? '', $query);

        foreach (self::FORWARDED_PARAMETERS as $name) {
            $value = $query[$name] ?? null;

            if (is_string($value) && '' !== $value) {
                $dsn .= $name.'='.$value.';';
            }
        }

        return $dsn.'application_name='.self::APPLICATION_NAME;
    }

    /**
     * @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, query?: string}
     */
    private function urlParts(): array
    {
        $parts = parse_url($this->databaseUrl);

        if (false === $parts || !isset($parts['scheme'])) {
            throw new \LogicException('DATABASE_URL is not a URL the session handler can read.');
        }

        // Postgres is a hard dependency of this application and the DSN above is
        // libpq-shaped, so fail loudly rather than emit a pgsql DSN for
        // something else.
        if (!in_array($parts['scheme'], ['postgresql', 'postgres', 'pdo-pgsql', 'pdo_pgsql'], true)) {
            throw new \LogicException(sprintf('The session handler expects a Postgres DATABASE_URL, got scheme "%s".', $parts['scheme']));
        }

        return $parts;
    }
}
