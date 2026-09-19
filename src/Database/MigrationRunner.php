<?php

namespace PubvanaInstaller\Database;

use PubvanaInstaller\Result;
use Throwable;

/**
 * Triggers the installed Pubvana application's own first-run setup.
 *
 * v3 self-installs: with a valid .env and no `.migrations_installed` marker,
 * the application's first HTTP request runs every migration tier (foundation,
 * core, enabled plugins) plus their seeds during bootstrap
 * (PluginLoader::loadPlugins()). This runner makes that first request, polls
 * for the marker file the app writes, then does a final render check.
 *
 * No exec() is used anywhere: the migrations run inside the application's own
 * web request, so shared-hosting hosts that disable shell access are fine.
 */
class MigrationRunner
{
    private const MAX_ATTEMPTS   = 6;
    private const RETRY_DELAY_S  = 2;
    private const BOOT_TIMEOUT   = 120;
    private const RENDER_TIMEOUT = 30;

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
     * Boots the application until it has installed itself, then verifies it
     * renders. Returns ok() once the marker file exists and the site responds.
     */
    public function runMigrations(): Result
    {
        $marker = $this->appRoot . DIRECTORY_SEPARATOR . '.migrations_installed';

        // The app runs migrations on public requests only while the marker is
        // missing, so keep booting (each request resumes the work) until either
        // the marker appears or we run out of attempts.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if (file_exists($marker)) {
                break;
            }

            $this->request($this->siteUrl, self::BOOT_TIMEOUT);

            if (file_exists($marker)) {
                break;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                sleep(self::RETRY_DELAY_S);
            }
        }

        if (! file_exists($marker)) {
            return Result::fail(
                'The application did not complete its first-run setup. '
                . 'Visit ' . $this->siteUrl . ' in your browser once to trigger it, '
                . 'then retry this step. If it still fails, check the database '
                . 'credentials from the earlier steps and the application error log.'
            );
        }

        // Render check: a second request with a normal HTTP status confirms the
        // site is usable after the migration tiers ran. A 5xx means the app is
        // booting but something in schema/style failed.
        $status = $this->request($this->siteUrl, self::RENDER_TIMEOUT);

        if ($status >= 500) {
            return Result::fail(
                'The database was set up, but the application is returning an '
                . 'error response. Check the application error log and the '
                . 'database credentials, then visit ' . $this->siteUrl
                . ' in your browser and retry this step.'
            );
        }

        return Result::ok('Migrations and seeds were applied by the application on its first boot.');
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
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
                'verify_peer'      => true,
                'verify_peer_name' => true,
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