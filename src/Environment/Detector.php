<?php

namespace PubvanaInstaller\Environment;

use Throwable;

/**
 * Detects the server environment by actually attempting operations.
 *
 * Philosophy: "try it and see". Validate by performing real operations
 * rather than relying on configuration values that may be inaccurate.
 */
class Detector
{
    /** Minimum PHP version required by Pubvana CMS v3 */
    private const PHP_MIN_VERSION = '8.2.0';

    /** Extensions checked by default */
    private const DEFAULT_REQUIRED_EXTENSIONS = [
        'curl',
        'intl',
        'json',
        'mbstring',
        'mysqlnd',
        'xml',
        'zip',
    ];

    /** DB-related extensions to probe (v3 supports MySQL/MariaDB only) */
    private const DB_EXTENSIONS = [
        'mysqli',
    ];

    public function __construct(
        private readonly string $targetDir,
        private readonly array $requiredExtensions = [],
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run all detection checks and return a fully-populated ServerEnvironment.
     */
    public function detect(): ServerEnvironment
    {
        $env = new ServerEnvironment();

        $env->isWindows = $this->detectWindows();

        // Probe exactly the extensions the requirements section cares about
        // (falls back to the built-in default set when none are configured).
        $required = $this->requiredExtensions !== [] ? $this->requiredExtensions : self::DEFAULT_REQUIRED_EXTENSIONS;

        $this->detectPhp($env);
        $this->detectExtensions($env, $required);
        $this->detectDisabledFunctions($env);
        $this->detectPhpLimits($env);
        $this->detectServer($env);
        $this->detectHttps($env);
        $this->detectModRewrite($env);
        $this->detectExec($env);
        $this->detectShellExec($env);
        $this->detectDbDrivers($env);
        $this->detectTargetDirWritable($env);
        $this->detectFilesystemMethod($env);
        $this->detectOutboundHttp($env);

        return $env;
    }

    // -------------------------------------------------------------------------
    // Private detection methods
    // -------------------------------------------------------------------------

    /**
     * Detect the current PHP version and whether it meets the minimum.
     */
    private function detectPhp(ServerEnvironment $env): void
    {
        $env->phpVersion = phpversion();
        $env->phpVersionOk = version_compare($env->phpVersion, self::PHP_MIN_VERSION, '>=')
            ? 'passed'
            : 'failed';
    }

    /**
     * Check each required extension via extension_loaded().
     *
     * @param string[] $required
     */
    private function detectExtensions(ServerEnvironment $env, array $required): void
    {
        foreach ($required as $ext) {
            $env->extensions[$ext] = extension_loaded($ext) ? 'passed' : 'failed';
        }
    }

    /**
     * Parse the disable_functions INI value into an array of function names.
     */
    private function detectDisabledFunctions(ServerEnvironment $env): void
    {
        $raw = ini_get('disable_functions');
        if ($raw === false || $raw === '') {
            $env->disabledFunctions = [];
            return;
        }

        $env->disabledFunctions = array_values(
            array_filter(
                array_map('trim', explode(',', $raw)),
                static fn(string $f) => $f !== '',
            ),
        );
    }

    /**
     * Read memory_limit, max_execution_time, and upload_max_filesize from php.ini.
     */
    private function detectPhpLimits(ServerEnvironment $env): void
    {
        $memLimit = ini_get('memory_limit');
        $env->memoryLimit = ($memLimit !== false) ? $memLimit : '';

        $maxExec = ini_get('max_execution_time');
        $env->maxExecutionTime = ($maxExec !== false) ? (int) $maxExec : 0;

        $uploadMax = ini_get('upload_max_filesize');
        $env->uploadMaxFilesize = ($uploadMax !== false) ? $uploadMax : '';
    }

    /**
     * Identify the web server software from $_SERVER['SERVER_SOFTWARE'].
     */
    private function detectServer(ServerEnvironment $env): void
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';
        $env->documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if ($software === '') {
            $env->serverSoftware = 'unknown';
            return;
        }

        $lower = strtolower($software);

        if (str_contains($lower, 'litespeed')) {
            $env->serverSoftware = 'LiteSpeed';
        } elseif (str_contains($lower, 'apache')) {
            $env->serverSoftware = 'Apache';
        } elseif (str_contains($lower, 'nginx')) {
            $env->serverSoftware = 'Nginx';
        } elseif (str_contains($lower, 'microsoft-iis') || str_contains($lower, 'iis')) {
            $env->serverSoftware = 'IIS';
        } else {
            $env->serverSoftware = 'unknown';
        }
    }

    /**
     * Determine whether the current request is served over HTTPS.
     */
    private function detectHttps(ServerEnvironment $env): void
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $port  = (int) ($_SERVER['SERVER_PORT'] ?? 0);

        if (
            ($https !== '' && strtolower($https) !== 'off')
            || strtolower($proto) === 'https'
            || $port === 443
        ) {
            $env->https = 'passed';
        } else {
            $env->https = 'failed';
        }
    }

    /**
     * Test Apache mod_rewrite by writing a .htaccess + sentinel file in a temp
     * directory, issuing a self-request via cURL or file_get_contents, then
     * verifying the rewrite worked. Cleans up temp files afterwards.
     *
     * For non-Apache servers the check is skipped (left as 'unknown').
     */
    private function detectModRewrite(ServerEnvironment $env): void
    {
        // Only meaningful for Apache
        if ($env->serverSoftware !== 'Apache') {
            $env->modRewrite = 'unknown';
            return;
        }

        // We need a document root to build a URL
        $docRoot = $env->documentRoot;
        if ($docRoot === '' || ! is_dir($docRoot)) {
            $env->modRewrite = 'unknown';
            return;
        }

        // Build temp directory inside document root so web server can serve it
        $token   = 'pv3_rw_' . bin2hex(random_bytes(8));
        $testDir = rtrim($docRoot, '/\\') . DIRECTORY_SEPARATOR . $token;

        if (! @mkdir($testDir, 0755, true)) {
            $env->modRewrite = 'unknown';
            return;
        }

        try {
            // The sentinel file that we want to be rewritten to
            $sentinelContent = 'MOD_REWRITE_OK';
            file_put_contents($testDir . DIRECTORY_SEPARATOR . 'sentinel.txt', $sentinelContent);

            // .htaccess that rewrites /token/check → /token/sentinel.txt
            $htaccess = "Options +FollowSymLinks\n"
                . "RewriteEngine On\n"
                . "RewriteRule ^check$ sentinel.txt [L]\n";
            file_put_contents($testDir . DIRECTORY_SEPARATOR . '.htaccess', $htaccess);

            // Build the URL to the rewritten path
            $scheme  = ($env->https === 'passed') ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            $testUrl = "{$scheme}://{$host}/{$token}/check";

            $probe = $this->httpGetWithStatus($testUrl, 5);

            // 401 or 403 means HTTP authentication blocked the probe.
            // Report unknown.
            if (in_array($probe['status'], [401, 403], true)) {
                $env->modRewrite       = 'unknown';
                $env->modRewriteReason = 'auth';
                return;
            }

            // No response means the server cannot reach itself. Report unknown.
            if ($probe['status'] === 0) {
                $env->modRewrite       = 'unknown';
                $env->modRewriteReason = 'unreachable';
                return;
            }

            $env->modRewrite = str_contains($probe['body'], $sentinelContent)
                ? 'passed'
                : 'failed';
        } catch (Throwable) {
            $env->modRewrite = 'unknown';
        } finally {
            // Clean up by removing the test files
            @unlink($testDir . DIRECTORY_SEPARATOR . '.htaccess');
            @unlink($testDir . DIRECTORY_SEPARATOR . 'sentinel.txt');
            @rmdir($testDir);
        }
    }

    /**
     * Verify exec() is callable: function must exist, must not be disabled,
     * and must actually produce the expected output when called.
     */
    private function detectExec(ServerEnvironment $env): void
    {
        if (! function_exists('exec')) {
            $env->execAvailable = 'failed';
            return;
        }

        if (in_array('exec', $env->disabledFunctions, true)) {
            $env->execAvailable = 'failed';
            return;
        }

        try {
            $output = [];
            @exec('echo PV3_TEST', $output);
            $env->execAvailable = (isset($output[0]) && str_contains($output[0], 'PV3_TEST'))
                ? 'passed'
                : 'failed';
        } catch (Throwable) {
            $env->execAvailable = 'failed';
        }
    }

    /**
     * Verify shell_exec() is callable using the same three-part pattern.
     */
    private function detectShellExec(ServerEnvironment $env): void
    {
        if (! function_exists('shell_exec')) {
            $env->shellExecAvailable = 'failed';
            return;
        }

        if (in_array('shell_exec', $env->disabledFunctions, true)) {
            $env->shellExecAvailable = 'failed';
            return;
        }

        try {
            $result = @shell_exec('echo PV3_TEST');
            $env->shellExecAvailable = ($result !== null && str_contains($result, 'PV3_TEST'))
                ? 'passed'
                : 'failed';
        } catch (Throwable) {
            $env->shellExecAvailable = 'failed';
        }
    }

    /**
     * Determine the filesystem method by actually writing a file in the target
     * directory. If the real write succeeds, direct access works; ownership is
     * not a reliable signal (group-writable/setgid dirs, ACLs, etc.). Falls back
     * to 'ftp' only when the write test fails.
     */
    private function detectFilesystemMethod(ServerEnvironment $env): void
    {
        $env->filesystemMethod = $env->targetDirWritable === 'passed' ? 'direct' : 'ftp';
    }

    /**
     * Check the MySQL driver extension (mysqli).
     */
    private function detectDbDrivers(ServerEnvironment $env): void
    {
        foreach (self::DB_EXTENSIONS as $ext) {
            $env->dbDrivers[$ext] = extension_loaded($ext) ? 'passed' : 'failed';
        }
    }

    /**
     * Verify the target installation directory is writable by actually writing
     * a temp file and deleting it.
     */
    private function detectTargetDirWritable(ServerEnvironment $env): void
    {
        $dir = $this->targetDir;

        // If the target dir doesn't exist yet, test the nearest existing parent
        while ($dir !== '' && $dir !== DIRECTORY_SEPARATOR && ! is_dir($dir)) {
            $dir = dirname($dir);
        }

        if (! is_dir($dir)) {
            $env->targetDirWritable = 'failed';
            return;
        }

        try {
            $tmpFile = $dir . DIRECTORY_SEPARATOR . '.pv3_write_test_' . uniqid('', true);
            $result  = @file_put_contents($tmpFile, 'pv3_writable_test');

            if ($result === false) {
                $env->targetDirWritable = 'failed';
                return;
            }

            @unlink($tmpFile);
            $env->targetDirWritable = 'passed';
        } catch (Throwable) {
            $env->targetDirWritable = 'failed';
        }
    }

    /**
     * Test outbound HTTP connectivity with a lightweight HEAD request.
     * Uses a well-known stable URL.
     */
    private function detectOutboundHttp(ServerEnvironment $env): void
    {
        // Use a stable, lightweight endpoint
        $testUrl = 'https://github.com/Pubvana-CMS/pubvana/releases?' . time();

        try {
            $response = $this->httpHead($testUrl, 5);
            $env->outboundHttp = ($response !== false) ? 'passed' : 'failed';
        } catch (Throwable) {
            $env->outboundHttp = 'failed';
        }
    }

    /**
     * Detect whether the server OS is Windows.
     */
    private function detectWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * GET a URL and return the status code and body.
     *
     * @return array{status: int, body: string}
     */
    private function httpGetWithStatus(string $url, int $timeoutSeconds = 10): array
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);

            if ($ch === false) {
                return ['status' => 0, 'body' => ''];
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Pubvana-Installer/1.0',
            ]);

            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'status' => $status,
                'body'   => is_string($body) ? $body : '',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        try {
            $body = @file_get_contents($url, false, $context);
        } catch (Throwable) {
            return ['status' => 0, 'body' => ''];
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return [
            'status' => $status,
            'body'   => is_string($body) ? $body : '',
        ];
    }

    /**
     * Perform a GET request and return the response body, or false on failure.
     *
     * Tries cURL first, then falls back to file_get_contents with a stream context.
     *
     * @return string|false
     */
    private function httpGet(string $url, int $timeoutSeconds = 10): string|false
    {
        if (extension_loaded('curl')) {
            return $this->curlRequest($url, 'GET', $timeoutSeconds);
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $timeoutSeconds,
                'ignore_errors'   => true,
            ],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        try {
            $result = @file_get_contents($url, false, $context);
            return $result;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Perform a HEAD request. Returns the status line on success, or false.
     *
     * @return string|false
     */
    private function httpHead(string $url, int $timeoutSeconds = 10): string|false
    {
        if (extension_loaded('curl')) {
            return $this->curlRequest($url, 'HEAD', $timeoutSeconds);
        }

        // Fallback: use GET with file_get_contents and check $http_response_header
        $context = stream_context_create([
            'http' => [
                'method'        => 'HEAD',
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        try {
            @file_get_contents($url, false, $context);
            // $http_response_header is populated by file_get_contents
            if (isset($http_response_header) && is_array($http_response_header) && count($http_response_header) > 0) {
                $statusLine = $http_response_header[0];
                // Consider any 2xx or 3xx a success
                if (preg_match('#HTTP/\d+(?:\.\d+)? ([23]\d{2})#', $statusLine)) {
                    return $statusLine;
                }
            }
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Shared cURL helper for GET and HEAD requests.
     *
     * @return string|false
     */
    private function curlRequest(string $url, string $method, int $timeoutSeconds): string|false
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Pubvana-Installer/1.0',
        ]);

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        try {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                return false;
            }

            // For HEAD requests, any 2xx/3xx is acceptable
            if ($method === 'HEAD') {
                return ($httpCode >= 200 && $httpCode < 400) ? (string) $httpCode : false;
            }

            return ($httpCode >= 200 && $httpCode < 400) ? (string) $response : false;
        } catch (Throwable) {
            curl_close($ch);
            return false;
        }
    }
}
