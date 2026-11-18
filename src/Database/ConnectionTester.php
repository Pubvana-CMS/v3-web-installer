<?php

namespace PubvanaInstaller\Database;

use PubvanaInstaller\Result;

/**
 * Tests MySQL/MariaDB connections for Pubvana CMS v3.
 *
 * Each test() call attempts a real connection and returns a Result with
 * a specific, actionable error message on failure. Never a generic one.
 */
class ConnectionTester
{
    // ---------------------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------------------

    private const RATE_LIMIT_MAX      = 10;
    private const RATE_LIMIT_WINDOW   = 300; // 5 minutes in seconds
    private const RATE_LIMIT_KEY      = 'pubvana_installer_db_attempts';

    /**
     * Checks whether the caller has exceeded the connection-attempt rate limit.
     *
     * Attempts are stored in $_SESSION as a list of Unix timestamps.
     * Any timestamps older than the window are pruned before the check.
     *
     * @return bool TRUE if the request is within limits and may proceed.
     */
    private function checkRateLimit(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $now      = time();
        $attempts = $_SESSION[self::RATE_LIMIT_KEY] ?? [];

        // Drop entries outside the rolling window.
        $attempts = array_values(array_filter(
            $attempts,
            static fn(int $ts): bool => ($now - $ts) < self::RATE_LIMIT_WINDOW,
        ));

        if (count($attempts) >= self::RATE_LIMIT_MAX) {
            $_SESSION[self::RATE_LIMIT_KEY] = $attempts;
            return false;
        }

        $attempts[] = $now;
        $_SESSION[self::RATE_LIMIT_KEY] = $attempts;

        return true;
    }

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Tests a MySQL/MariaDB connection.
     *
     * @param string $driver      Must be 'MySQLi'.
     * @param array  $credentials Keys: hostname, port, database, username, password.
     */
    public function test(string $driver, array $credentials): Result
    {
        if (! $this->checkRateLimit()) {
            return Result::fail(
                'Too many attempts. Wait a few minutes.'
            );
        }

        return match ($driver) {
            'MySQLi'  => $this->testMySQLi($credentials),
            default   => Result::fail("Unknown driver '{$driver}'. v3 supports MySQLi only."),
        };
    }

    /**
     * Creates the target database.
     *
     * Connects without selecting a database, then issues CREATE DATABASE.
     *
     * @param string $driver      Must be 'MySQLi'.
     * @param array  $credentials Same shape as test().
     */
    public function createDatabase(string $driver, array $credentials): Result
    {
        if (! $this->checkRateLimit()) {
            return Result::fail(
                'Too many attempts. Wait a few minutes.'
            );
        }

        return match ($driver) {
            'MySQLi'  => $this->createMySQLi($credentials),
            default   => Result::fail("Unknown driver '{$driver}'. v3 supports MySQLi only."),
        };
    }

    // ---------------------------------------------------------------------------
    // MySQLi test.
    // ---------------------------------------------------------------------------

    private function testMySQLi(array $creds): Result
    {
        $host = $creds['hostname'] ?? '127.0.0.1';
        $user = $creds['username'] ?? '';
        $pass = $creds['password'] ?? '';
        $db   = $creds['database'] ?? '';
        $port = isset($creds['port']) ? (int) $creds['port'] : 3306;

        // Suppress warnings because we inspect the error ourselves.
        mysqli_report(MYSQLI_REPORT_OFF);

        $conn = @mysqli_connect($host, $user, $pass, '', $port);

        if ($conn === false) {
            $errno = mysqli_connect_errno();
            $error = mysqli_connect_error() ?? '';

            // 1045 = Access denied (bad credentials)
            // 2002 = Can't connect to server (unreachable)
            // 2003 = Can't connect to MySQL server (wrong port / firewall)
            if ($errno === 1045) {
                return Result::fail("Authentication failed for user '{$user}' on {$host}:{$port}.");
            }

            if (in_array($errno, [2002, 2003, 2005], true)) {
                return Result::fail("Server unreachable at {$host}:{$port}. ({$error})");
            }

            return Result::fail("MySQLi connection error ({$errno}): {$error}");
        }

        // Connected, so now try to select the database.
        if ($db !== '') {
            $selected = @mysqli_select_db($conn, $db);
            mysqli_close($conn);

            if (! $selected) {
                // errno 1044 = access denied to db, 1049 = unknown database
                $errno = mysqli_errno($conn) ?: 0;

                // At this point $conn is closed; use the last known error string.
                // Re-open briefly just to get the error number.
                $conn2 = @mysqli_connect($host, $user, $pass, '', $port);
                if ($conn2 !== false) {
                    @mysqli_select_db($conn2, $db);
                    $errno = mysqli_errno($conn2);
                    mysqli_close($conn2);
                }

                if ($errno === 1044) {
                    return Result::fail("Authentication failed: user '{$user}' is not permitted to access database '{$db}'.");
                }

                // For 1049 or anything else, the database simply doesn't exist.
                return Result::fail("Database '{$db}' does not exist on {$host}:{$port}.");
            }
        } else {
            mysqli_close($conn);
        }

        return Result::ok();
    }

    // ---------------------------------------------------------------------------
    // MySQLi createDatabase.
    // ---------------------------------------------------------------------------

    private function createMySQLi(array $creds): Result
    {
        $host = $creds['hostname'] ?? '127.0.0.1';
        $user = $creds['username'] ?? '';
        $pass = $creds['password'] ?? '';
        $db   = $creds['database'] ?? '';
        $port = isset($creds['port']) ? (int) $creds['port'] : 3306;

        mysqli_report(MYSQLI_REPORT_OFF);

        $conn = @mysqli_connect($host, $user, $pass, '', $port);

        if ($conn === false) {
            $errno = mysqli_connect_errno();
            $error = mysqli_connect_error() ?? '';

            if ($errno === 1045) {
                return Result::fail("Authentication failed for user '{$user}' on {$host}:{$port}.");
            }

            if (in_array($errno, [2002, 2003, 2005], true)) {
                return Result::fail("Server unreachable at {$host}:{$port}. ({$error})");
            }

            return Result::fail("MySQLi connection error ({$errno}): {$error}");
        }

        $escapedDb = mysqli_real_escape_string($conn, $db);
        $sql       = "CREATE DATABASE `{$escapedDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";

        if (! mysqli_query($conn, $sql)) {
            $error = mysqli_error($conn);
            $errno = mysqli_errno($conn);
            mysqli_close($conn);

            if ($errno === 1007) {
                return Result::fail("Database '{$db}' already exists.");
            }

            if ($errno === 1044 || $errno === 1227) {
                return Result::fail("User '{$user}' does not have CREATE DATABASE privileges on {$host}:{$port}.");
            }

            return Result::fail("Failed to create database '{$db}': ({$errno}) {$error}");
        }

        mysqli_close($conn);

        return Result::ok("Database '{$db}' created.");
    }
}
