<?php

namespace PubvanaInstaller\Database;

use PubvanaInstaller\Result;
use Throwable;

/**
 * Triggers the application's first-run setup.
 *
 * v3 self-installs: with a valid .env and no `.migrations_installed` marker,
 * the application's first HTTP request runs every migration tier (foundation,
 * core, enabled plugins) plus their seeds during bootstrap
 * (PluginLoader::loadPlugins()). This runner makes that first request, then
 * asks for an admin URL because admin requests check for pending migrations
 * even after the marker exists.
 *
 * Each call makes short requests. An unfinished run is retryable and resumes
 * from the tracking table.
 *
 * No exec() call. Migrations run inside the application's web request, so
 * shared hosting without shell access works.
 */
class MigrationRunner
{
    /** Timeout for a request that may be running migrations. */
    private const PROBE_TIMEOUT = 15;

    /** Timeout for the render check after migrations. */
    private const RENDER_TIMEOUT = 10;

    /** Absolute path to the root of the installed v3 application. */
    private string $appRoot;

    /** Base URL the application is served at (trailing slash). */
    private string $siteUrl;

    public function __construct(string $appRoot, string $siteUrl)
    {
        $this->appRoot = rtrim($appRoot, '/\\');
        $this->siteUrl = rtrim($siteUrl, '/') . '/';
    }

    /**
     * Runs one round of the application's setup.
     *
     * Returns ok() when the marker exists and the site renders, retry() when
     * work is unfinished, and fail() on a server error.
     */
    public function runMigrations(): Result
    {
        $marker = $this->appRoot . DIRECTORY_SEPARATOR . '.migrations_installed';

        // Run the app's first boot. A long run continues on the next call.
        $this->request($this->siteUrl, self::PROBE_TIMEOUT);

        if (! file_exists($marker)) {
            return Result::retry('Setting up the database. This can take a few minutes.');
        }

        // The app writes the marker before migrations finish. Admin requests
        // check pending migrations, which finishes an interrupted run.
        $this->request($this->adminUrl(), self::PROBE_TIMEOUT);

        $status = $this->request($this->siteUrl, self::RENDER_TIMEOUT);

        if ($status === 0) {
            return Result::retry('Site did not respond. Retrying.');
        }

        if ($status >= 500) {
            return Result::fail(
                'Site returns HTTP ' . $status . '. Check the error log and '
                . 'database credentials, then retry.'
            );
        }

        return Result::ok('Migrations and seeds applied.');
    }

    /**
     * URL whose requests always check for pending migrations.
     */
    private function adminUrl(): string
    {
        return $this->siteUrl . 'admin/';
    }

    /**
     * Perform a single HTTP GET and return the HTTP status code.
     *
     * @return int HTTP status code, or 0 when the request could not be completed.
     */
    private function request(string $url, int $timeout): int
    {
        if (extension_loaded('curl')) {
            return $this->curlRequest($url, $timeout);
        }

        return $this->streamRequest($url, $timeout);
    }

    /**
     * cURL-backed request helper.
     *
     * Skips certificate checks. The request carries no credentials.
     */
    private function curlRequest(string $url, int $timeout): int
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return 0;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Pubvana-Installer/1.0',
        ]);

        try {
            $ok     = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $ok !== false ? $status : 0;
        } catch (Throwable) {
            curl_close($ch);

            return 0;
        }
    }

    /**
     * file_get_contents-based request helper.
     */
    private function streamRequest(string $url, int $timeout): int
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $timeout,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'ignore_errors'   => true,
                'user_agent'      => 'Pubvana-Installer/1.0',
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        try {
            @file_get_contents($url, false, $context);
        } catch (Throwable) {
            return 0;
        }

        if (! isset($http_response_header) || ! is_array($http_response_header) || $http_response_header === []) {
            return 0;
        }

        if (preg_match('#HTTP/\d(?:\.\d)? (\d{3})#', $http_response_header[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
