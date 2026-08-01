<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

// Shared Redis host + TLS stream context for the 'default', 'cache', and
// 'queue' connections below — computed once here rather than duplicated
// three times. AWS ElastiCache (and most managed Redis providers) present
// a certificate that PHP's default SSL context cannot always verify
// without an explicit `cafile` + `peer_name`, even when a valid CA bundle
// is present on disk — hence the explicit context below rather than
// relying on PHP's implicit defaults. Only built when REDIS_HOST actually
// uses the tls:// scheme, so local/non-TLS development is unaffected.
$redisHost = env('REDIS_HOST', '127.0.0.1');

$redisTlsContext = null;

if (str_starts_with($redisHost, 'tls://')) {
    $redisPeerName = env('REDIS_TLS_PEER_NAME')
        ?: parse_url($redisHost, PHP_URL_HOST)
        ?: Str::after($redisHost, 'tls://');

    $redisCaFile = env('REDIS_TLS_CA_FILE')
        ?: env('SSL_CERT_FILE')
        ?: '/etc/ssl/certs/ca-certificates.crt';

    $redisTlsContext = [
        'stream' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'cafile' => $redisCaFile,
            'peer_name' => $redisPeerName,
        ],
    ];
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
         * Checkpoint 9 addition — a structural duplicate of 'pgsql' above,
         * pointing at the exact same physical database via the identical
         * env() references (never a hardcoded/duplicated value, so the two
         * connections can never drift apart). Its only purpose is to give
         * TimelineEventRecorder::record() a genuinely separate PDO
         * session/connection to write on when
         * $independentOfAmbientTransaction is true — a transaction opened
         * on THIS connection commits independently of whatever the
         * ambient 'pgsql' connection's transaction is doing, which is the
         * only correct way to make a single write durable even when the
         * enclosing request/job transaction later rolls back (Postgres
         * transactions are all-or-nothing per session; a SAVEPOINT cannot
         * escape its own session's rollback). See
         * app/Services/TimelineEventRecorder.php for the write path that
         * uses this connection.
         */
        'pgsql_audit' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        // 'serializer' forced to Redis::SERIALIZER_NONE (0) explicitly —
        // FIRMSVAULT STAGING ADMIN STABILIZATION fix for the Platform Admin
        // dashboard's HTTP 500 (PlatformSecurityDashboardService::
        // recentSecurityEvents(): "Return value must be of type
        // Illuminate\Support\Collection, __PHP_Incomplete_Class returned").
        // Root cause, reproduced directly against live staging Redis:
        // without this option, Laravel's own PhpRedisConnector never sets
        // Redis::OPT_SERIALIZER at all (it only calls setOption() when this
        // 'serializer' config key is present — see
        // Illuminate\Redis\Connectors\PhpRedisConnector), so the phpredis
        // EXTENSION's own compiled-in/php.ini-level default serializer
        // (non-zero in this image's build, undocumented anywhere in this
        // repo's own php.ini files) was silently active underneath
        // Laravel's Redis cache store — which ALSO does its own PHP-level
        // serialize()/unserialize() around every cached value. That
        // double-serialization round-trips correctly for simple/shallow
        // values (which is why most of the app's caching appeared to work
        // fine) but corrupts on read-back for a Collection containing
        // nested objects (e.g. Carbon `created_at` values), because
        // phpredis's own C-extension-level unserialize() does not
        // reliably trigger Composer's autoloader for the outer wrapper
        // class before Laravel's userland unserialize() ever runs —
        // confirmed via a minimal, isolated Cache::remember() reproduction
        // against the real staging ElastiCache instance. Laravel's own
        // documentation is explicit that phpredis's serializer must stay
        // off when using phpredis as the Redis client: forcing it here
        // (rather than depending on an untracked php.ini default) makes
        // this correct regardless of what any future base-image change
        // ships as phpredis's own default.
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
            'serializer' => Redis::SERIALIZER_NONE,
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => $redisHost,
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'context' => $redisTlsContext,
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => $redisHost,
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'context' => $redisTlsContext,
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        // Dedicated queue connection/database index — kept separate from
        // 'default' (distributed locks/general use) and 'cache' so queue
        // traffic (potentially high-volume, latency-sensitive) is isolated
        // from cache eviction/lock contention on the same Redis logical
        // database. config/queue.php's redis connection defaults to
        // REDIS_QUEUE_CONNECTION=default for backward compatibility; set
        // REDIS_QUEUE_CONNECTION=queue in ECS task environments to use this
        // isolated connection instead. See docs/ecs/queue-and-redis-architecture.md.
        'queue' => [
            'url' => env('REDIS_URL'),
            'host' => $redisHost,
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '2'),
            'context' => $redisTlsContext,
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
