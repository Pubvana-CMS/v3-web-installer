<?php

namespace PubvanaInstaller\Auth;

use PubvanaInstaller\Result;
use PDO;
use Throwable;

/**
 * Auth adapter for flight-shield (v3).
 *
 * Creates the first admin user through \Enlivenapp\FlightShield\Services\
 * UserManagement, the exact same service the application uses, so hashing,
 * password validation, duplicate-email rejection, and group wiring can never
 * drift from the installed app. This needs no framework boot: UserManagement
 * is plain PDO + models, and only addGroup() touches the Flight facade, so we
 * register the PDO on a minimal Flight engine instance first.
 *
 * When the app's vendor/autoload.php or the service class is unavailable, it
 * falls back to direct SQL inserts that mirror UserManagement::create():
 *   - a users row (active)
 *   - an email_password identity (secret = email, secret2 = password hash)
 *   - an auth_groups_users membership row
 *
 * Expected $dbCredentials keys:
 *   hostname, port, database, username, password
 *
 * @see https://github.com/enlivenapp/flight-shield
 */
class ShieldAdapter implements AuthAdapterInterface
{
    /** Whether the app's composer autoloader has been required already. */
    private bool $autoloadAttempted = false;

    public function __construct(
        private readonly string $appRoot,
        private readonly string $group = 'superadmin',
        private readonly array  $dbCredentials = [],
    ) {}

    public function canHandle(): bool
    {
        try {
            $conn = $this->connect();
        } catch (Throwable $e) {
            return false;
        }

        try {
            $hasUsers      = $this->tableExists($conn, 'users');
            $hasIdentities = $this->tableExists($conn, 'auth_identities');
            $hasGroups     = $this->tableExists($conn, 'auth_groups');
            return $hasUsers && $hasIdentities && $hasGroups;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getFields(): array
    {
        return [
            [
                'name'     => 'email',
                'type'     => 'email',
                'label'    => 'Email',
                'required' => true,
            ],
            [
                'name'      => 'password',
                'type'      => 'password',
                'label'     => 'Password',
                'required'  => true,
                'minlength' => 8,
            ],
        ];
    }

    public function createAdmin(array $data): Result
    {
        try {
            $conn = $this->connect();
        } catch (Throwable $e) {
            return Result::fail('DB connection failed: ' . $e->getMessage());
        }

        $username = trim((string) ($data['username'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return Result::fail('Email and password required.');
        }

        if (strlen($password) < 8) {
            return Result::fail('Password must be at least 8 characters.');
        }

        // Preferred path: the app's own UserManagement service.
        if ($this->canUseUserManagement()) {
            $result = $this->createViaUserManagement($conn, $username, $email, $password);

            if ($result !== null) {
                return $result;
            }
            // $result === null means the service threw (unexpected schema or
            // broken Flight facade instance); fall back to the SQL mirror.
        }

        return $this->createViaSql($conn, $username, $email, $password);
    }

    public function hasAdmin(): bool
    {
        try {
            $conn = $this->connect();

            if (! $this->tableExists($conn, 'users')) {
                return false;
            }

            return (int) $conn->query('SELECT COUNT(*) FROM `users`')->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // UserManagement (primary) path
    // -------------------------------------------------------------------------

    /**
     * Determine whether the app's flight-shield service is loadable.
     */
    private function canUseUserManagement(): bool
    {
        $autoload = $this->appRoot . '/vendor/autoload.php';

        if (! is_file($autoload)) {
            return false;
        }

        if (! $this->autoloadAttempted) {
            require_once $autoload;
            $this->autoloadAttempted = true;
        }

        return class_exists(\Enlivenapp\FlightShield\Services\UserManagement::class);
    }

    /**
     * Register the PDO on the Flight facade so addGroup() works without
     * booting the application's services.php (no middleware, no plugins).
     *
     * Mirrors app/config/services.php: Flight::db() resolves through the
     * dispatcher, so the connection must be mapped as a callable, not just
     * stored as a variable.
     */
    private function prepareFlightDb(PDO $conn): void
    {
        if (! class_exists('Flight')) {
            return;
        }

        $engine = \Flight::app();
        $engine->set('db', $conn);
        $engine->map('db', static fn (): PDO => $conn);
    }

    /**
     * Create the admin via the application's own service.
     *
     * Returns null when the service is unusable (throws part-way), signalling
     * the caller to fall back to the SQL mirror.
     */
    private function createViaUserManagement(
        PDO    $conn,
        string $username,
        string $email,
        string $password,
    ): ?Result {
        try {
            $this->prepareFlightDb($conn);

            $service = new \Enlivenapp\FlightShield\Services\UserManagement($conn);
            $umResult = $service->create($username, $email, $password, $this->group);

            if (! $umResult->isOK()) {
                return Result::fail($umResult->reason() ?? 'Administrator creation failed.');
            }

            $extra = $umResult->extraInfo();
            $userId = (is_object($extra) && property_exists($extra, 'id'))
                ? (int) $extra->id
                : 0;

            return Result::ok(['user_id' => $userId]);
        } catch (Throwable $e) {
            // The shield schema is older/newer than expected or the Flight
            // facade cannot be prepared, so fall back to the SQL mirror.
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Direct SQL (fallback) path
    // -------------------------------------------------------------------------

    /**
     * Creates the admin user with direct SQL, mirroring
     * \Enlivenapp\FlightShield\Services\UserManagement::create() as closely as
     * possible when the service cannot be loaded.
     */
    private function createViaSql(
        PDO    $conn,
        string $username,
        string $email,
        string $password,
    ): Result {
        try {
            $now = date('Y-m-d H:i:s');

            if ($username === '') {
                $username = $this->deriveUsername($conn, $email);
            }

            // Ensure the target group exists (seeded by flight-shield normally,
            // but never assume the seed ran before the admin substep).
            $this->ensureGroup($conn, $this->group, $now);

            $userId = $this->insertAndGetId(
                $conn,
                'users',
                ['username', 'active', 'created_at', 'updated_at'],
                [$username, 1, $now, $now],
            );

            $this->insertRow(
                $conn,
                'auth_identities',
                ['user_id', 'type', 'secret', 'secret2', 'created_at', 'updated_at'],
                [
                    $userId,
                    'email_password',
                    $email,
                    password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]),
                    $now,
                    $now,
                ],
            );

            $this->insertRow(
                $conn,
                'auth_groups_users',
                ['user_id', 'group_alias', 'created_at'],
                [$userId, $this->group, $now],
            );

            return Result::ok(['user_id' => $userId]);
        } catch (Throwable $e) {
            return Result::fail('Shield createAdmin error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Database connection helpers
    // -------------------------------------------------------------------------

    /**
     * Open a raw PDO connection (MySQL/MariaDB).
     *
     * @throws \RuntimeException on failure
     */
    private function connect(): PDO
    {
        if (! extension_loaded('pdo')) {
            throw new \RuntimeException('The PDO extension is not loaded.');
        }

        $host     = $this->dbCredentials['hostname'] ?? '127.0.0.1';
        $port     = isset($this->dbCredentials['port']) ? (int) $this->dbCredentials['port'] : 3306;
        $database = $this->dbCredentials['database'] ?? '';
        $username = $this->dbCredentials['username'] ?? '';
        $password = $this->dbCredentials['password'] ?? '';

        if ($database === '') {
            throw new \RuntimeException('ShieldAdapter: database name is empty.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database,
        );

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (Throwable $e) {
            throw new \RuntimeException('PDO connect error: ' . $e->getMessage());
        }

        return $pdo;
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a unique username from the email local part.
     */
    private function deriveUsername(PDO $conn, string $email): string
    {
        $base = strtolower((string) strstr($email, '@', true));
        $base = preg_replace('/[^a-z0-9._-]+/', '', $base) ?: 'admin';
        $base = substr($base, 0, 25);

        if (strlen($base) < 3) {
            $base = 'admin';
        }

        $candidate = $base;
        $check     = $conn->prepare('SELECT COUNT(*) FROM `users` WHERE `username` = ?');

        for ($suffix = 2; $suffix <= 20; $suffix++) {
            $check->execute([$candidate]);

            if ((int) $check->fetchColumn() === 0) {
                return $candidate;
            }

            $candidate = substr($base, 0, 27) . '-' . $suffix;
        }

        return substr($base, 0, 24) . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Check whether a table exists in the connected database.
     */
    private function tableExists(PDO $conn, string $table): bool
    {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Insert the target group row if it is missing.
     */
    private function ensureGroup(PDO $conn, string $alias, string $now): void
    {
        if (! $this->tableExists($conn, 'auth_groups')) {
            throw new \RuntimeException('auth_groups table missing. Run migrations first.');
        }

        $stmt = $conn->prepare('SELECT COUNT(*) FROM auth_groups WHERE alias = ?');
        $stmt->execute([$alias]);

        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $this->insertRow(
            $conn,
            'auth_groups',
            ['alias', 'title', 'description', 'created_at', 'updated_at'],
            [$alias, ucfirst($alias), 'Full system access', $now, $now],
        );
    }

    /**
     * Insert a row and return the auto-increment ID.
     *
     * @throws \RuntimeException on failure.
     */
    private function insertAndGetId(
        PDO    $conn,
        string $table,
        array  $columns,
        array  $values,
    ): int {
        $this->insertRow($conn, $table, $columns, $values);

        return (int) $conn->lastInsertId();
    }

    /**
     * Execute a parameterised INSERT.
     *
     * @throws \RuntimeException on failure.
     */
    private function insertRow(
        PDO    $conn,
        string $table,
        array  $columns,
        array  $values,
    ): void {
        $quotedCols = array_map(
            static fn (string $col): string => '`' . trim($col, '`') . '`',
            $columns,
        );

        $colList      = implode(', ', $quotedCols);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql          = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            $colList,
            $placeholders,
        );

        $stmt = $conn->prepare($sql);
        $stmt->execute($values);
    }
}