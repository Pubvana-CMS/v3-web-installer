<?php

namespace PubvanaInstaller\Environment;

/**
 * Compares a ServerEnvironment against the developer's installer-config.php
 * and produces human-readable check results.
 */
class Requirements
{
    /** Memory threshold below which a warning is issued (bytes) */
    private const MEMORY_WARN_BYTES = 64 * 1024 * 1024; // 64M

    /** Max execution time below which a warning is issued (seconds) */
    private const MAX_EXEC_WARN_SECONDS = 30;

    public function __construct(
        private readonly ServerEnvironment $env,
        private readonly array $config,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run all requirement checks and return an array of result rows.
     *
     * Each row:
     * [
     *   'label'  => string,   // Human-readable description
     *   'status' => string,   // 'passed' | 'failed' | 'warning'
     *   'detail' => string,   // Additional context
     * ]
     *
     * @return array<int, array{label: string, status: string, detail: string}>
     */
    public function check(): array
    {
        $results = [];

        $this->checkPhpVersion($results);
        $this->checkRequiredExtensions($results);
        $this->checkDbDrivers($results);
        $this->checkMemoryLimit($results);
        $this->checkMaxExecutionTime($results);
        $this->checkOutboundHttp($results);
        $this->checkTargetDirWritable($results);
        $this->checkHttps($results);
        $this->checkModRewrite($results);
        $this->checkFilesystem($results);
        $this->checkDisabledFunctions($results);

        return $results;
    }

    /**
     * Returns true if any check result has status 'failed'.
     */
    public function hasBlockers(): bool
    {
        foreach ($this->check() as $result) {
            if ($result['status'] === 'failed') {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Individual check methods
    // -------------------------------------------------------------------------

    /**
     * Check whether the installed PHP version meets the configured minimum.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkPhpVersion(array &$results): void
    {
        $minVersion = $this->config['requirements']['php'] ?? '8.2.0';
        $label      = "PHP version >= {$minVersion}";
        $actual     = $this->env->phpVersion;

        if ($this->env->phpVersionOk === 'passed' && version_compare($actual, $minVersion, '>=')) {
            $results[] = [
                'label'  => $label,
                'status' => 'passed',
                'detail' => "PHP {$actual}",
            ];
        } else {
            $results[] = [
                'label'  => $label,
                'status' => 'failed',
                'detail' => $actual !== ''
                    ? "PHP {$actual} is below {$minVersion}"
                    : "PHP version unknown.",
            ];
        }
    }

    /**
     * Check each extension listed in the config's requirements.extensions array.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkRequiredExtensions(array &$results): void
    {
        $required = $this->config['requirements']['extensions'] ?? [];

        if (empty($required)) {
            return;
        }

        foreach ($required as $ext) {
            $ext    = (string) $ext;
            $status = $this->env->extensions[$ext] ?? (extension_loaded($ext) ? 'passed' : 'failed');

            $results[] = [
                'label'  => "Extension: {$ext}",
                'status' => $status === 'passed' ? 'passed' : 'failed',
                'detail' => $status === 'passed' ? "Loaded" : "Not installed",
            ];
        }
    }

    /**
     * Check DB driver availability against config's requirements.databases.
     *
     * At least one of the required drivers must be present; otherwise it's a
     * blocker. Individual drivers get a 'warning' when available but not
     * the only/primary option.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkDbDrivers(array &$results): void
    {
        $requiredDbs = $this->config['requirements']['databases'] ?? [];

        if (empty($requiredDbs)) {
            // Nothing is configured, so just report what is available
            foreach ($this->env->dbDrivers as $driver => $driverStatus) {
                $results[] = [
                    'label'  => "DB driver: {$driver}",
                    'status' => $driverStatus === 'passed' ? 'passed' : 'failed',
                    'detail' => $driverStatus === 'passed' ? 'Available' : 'Not installed',
                ];
            }
            return;
        }

        $availableRequired = [];
        $missingRequired   = [];

        foreach ($requiredDbs as $driver) {
            $driver       = (string) $driver;
            $driverStatus = $this->env->dbDrivers[$driver] ?? (extension_loaded($driver) ? 'passed' : 'failed');

            if ($driverStatus === 'passed') {
                $availableRequired[] = $driver;
            } else {
                $missingRequired[] = $driver;
            }
        }

        $atLeastOneAvailable = count($availableRequired) > 0;

        foreach ($requiredDbs as $driver) {
            $driver       = (string) $driver;
            $driverStatus = $this->env->dbDrivers[$driver] ?? (extension_loaded($driver) ? 'passed' : 'failed');
            $isAvailable  = $driverStatus === 'passed';

            if (! $atLeastOneAvailable) {
                // No usable driver at all → every missing one is a blocker
                $results[] = [
                    'label'  => "DB support: {$driver}",
                    'status' => 'failed',
                    'detail' => 'Not installed. No usable driver.',
                ];
            } elseif ($isAvailable && count($availableRequired) === 1) {
                // This is the only available required driver
                $results[] = [
                    'label'  => "DB support: {$driver}",
                    'status' => 'passed',
                    'detail' => 'Available',
                ];
            } elseif ($isAvailable) {
                // Available but not the sole option, so warn instead of full pass
                $results[] = [
                    'label'  => "DB support: {$driver}",
                    'status' => 'warning',
                    'detail' => 'Available but not the preferred driver',
                ];
            } else {
                // Missing, but at least one other required driver is present
                $results[] = [
                    'label'  => "DB support: {$driver}",
                    'status' => 'warning',
                    'detail' => 'Not installed. Another driver is available.',
                ];
            }
        }
    }

    /**
     * Warn when PHP's memory_limit is below 64M.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkMemoryLimit(array &$results): void
    {
        $raw   = $this->env->memoryLimit;
        $bytes = $this->parseIniBytes($raw);

        // -1 means unlimited
        if ($bytes === -1) {
            $results[] = [
                'label'  => 'Memory limit',
                'status' => 'passed',
                'detail' => 'Unlimited',
            ];
            return;
        }

        if ($bytes > 0 && $bytes < self::MEMORY_WARN_BYTES) {
            $results[] = [
                'label'  => 'Memory limit',
                'status' => 'warning',
                'detail' => "Current: {$raw}. 64M or more recommended.",
            ];
        } else {
            $results[] = [
                'label'  => 'Memory limit',
                'status' => 'passed',
                'detail' => $raw !== '' ? $raw : 'Unknown',
            ];
        }
    }

    /**
     * Warn when max_execution_time is below 30 seconds.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkMaxExecutionTime(array &$results): void
    {
        $seconds = $this->env->maxExecutionTime;

        // 0 means unlimited
        if ($seconds === 0) {
            $results[] = [
                'label'  => 'Max execution time',
                'status' => 'passed',
                'detail' => 'Unlimited',
            ];
            return;
        }

        if ($seconds < self::MAX_EXEC_WARN_SECONDS) {
            $results[] = [
                'label'  => 'Max execution time',
                'status' => 'warning',
                'detail' => "Current: {$seconds}s. " . self::MAX_EXEC_WARN_SECONDS . 's or more recommended.',
            ];
        } else {
            $results[] = [
                'label'  => 'Max execution time',
                'status' => 'passed',
                'detail' => "{$seconds}s",
            ];
        }
    }

    /**
     * Warn when outbound HTTP is unavailable (manual download may be needed).
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkOutboundHttp(array &$results): void
    {
        $status = $this->env->outboundHttp;

        if ($status === 'passed') {
            $results[] = [
                'label'  => 'Outbound HTTP',
                'status' => 'passed',
                'detail' => 'Reachable',
            ];
        } elseif ($status === 'failed') {
            $results[] = [
                'label'  => 'Outbound HTTP',
                'status' => 'warning',
                'detail' => 'Unreachable. Manual download needed.',
            ];
        } else {
            $results[] = [
                'label'  => 'Outbound HTTP',
                'status' => 'warning',
                'detail' => 'Unknown. Manual download needed.',
            ];
        }
    }

    /**
     * Report whether the target installation directory is writable.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkTargetDirWritable(array &$results): void
    {
        $status = $this->env->targetDirWritable;

        if ($status === 'passed') {
            $results[] = [
                'label'  => 'Target directory writable',
                'status' => 'passed',
                'detail' => 'Writable',
            ];
        } elseif ($status === 'failed') {
            $results[] = [
                'label'  => 'Target directory writable',
                'status' => 'failed',
                'detail' => 'Not writable. Check permissions.',
            ];
        } else {
            $results[] = [
                'label'  => 'Target directory writable',
                'status' => 'warning',
                'detail' => 'Unknown.',
            ];
        }
    }

    /**
     * Report HTTPS status as informational (warning when not detected).
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkHttps(array &$results): void
    {
        $status = $this->env->https;

        if ($status === 'passed') {
            $results[] = [
                'label'  => 'HTTPS',
                'status' => 'passed',
                'detail' => 'Active',
            ];
        } else {
            // Not a hard blocker, but worth noting
            $results[] = [
                'label'  => 'HTTPS',
                'status' => 'warning',
                'detail' => 'Not detected. Credentials travel unencrypted.',
            ];
        }
    }

    /**
     * Report mod_rewrite status for Apache servers.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkModRewrite(array &$results): void
    {
        $status = $this->env->modRewrite;

        if ($status === 'unknown') {
            // Only relevant for Apache; skip if not applicable
            if ($this->env->serverSoftware === 'Apache') {
                $detail = 'Not verified. Clean URLs may not work.';

                if ($this->env->modRewriteReason === 'auth') {
                    $detail = 'Blocked by HTTP authentication. Remove directory protection to test.';
                } elseif ($this->env->modRewriteReason === 'unreachable') {
                    $detail = 'Server cannot reach itself. Common on shared hosting.';
                }

                $results[] = [
                    'label'  => 'Apache mod_rewrite',
                    'status' => 'warning',
                    'detail' => $detail,
                ];
            }
            return;
        }

        if ($status === 'passed') {
            $results[] = [
                'label'  => 'Apache mod_rewrite',
                'status' => 'passed',
                'detail' => 'Enabled',
            ];
        } else {
            $results[] = [
                'label'  => 'Apache mod_rewrite',
                'status' => 'failed',
                'detail' => 'Disabled. Clean URLs will not work.',
            ];
        }
    }

    /**
     * Report filesystem access method.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkFilesystem(array &$results): void
    {
        $method = $this->env->filesystemMethod;

        if ($method === 'direct') {
            $results[] = [
                'label'  => 'Filesystem access',
                'status' => 'passed',
                'detail' => 'Direct (files writable by the web server)',
            ];
        } elseif ($method === 'unknown') {
            $results[] = [
                'label'  => 'Filesystem access',
                'status' => 'warning',
                'detail' => 'Unknown.',
            ];
        } else {
            // ftp, ftps and ssh2 are not necessarily blockers but are worth noting
            $results[] = [
                'label'  => 'Filesystem access',
                'status' => 'warning',
                'detail' => "Method: {$method}. Web server cannot write files. Use FTP or SSH credentials.",
            ];
        }
    }

    /**
     * Warn about any critical functions that are disabled.
     *
     * @param array<int, array{label: string, status: string, detail: string}> $results
     */
    private function checkDisabledFunctions(array &$results): void
    {
        if (empty($this->env->disabledFunctions)) {
            return;
        }

        $criticalDisabled = array_intersect(
            $this->env->disabledFunctions,
            ['exec', 'shell_exec', 'proc_open', 'popen', 'system', 'passthru'],
        );

        if (! empty($criticalDisabled)) {
            $results[] = [
                'label'  => 'Disabled functions',
                'status' => 'warning',
                'detail' => 'Disabled: ' . implode(', ', $criticalDisabled)
                    . '. Some features unavailable.',
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a PHP ini size string (e.g. '128M', '1G', '512K') to bytes.
     * Returns -1 for unlimited ('-1'), and 0 when the value cannot be parsed.
     */
    private function parseIniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '0') {
            return 0;
        }

        if ($value === '-1') {
            return -1;
        }

        $last  = strtolower($value[strlen($value) - 1]);
        $bytes = (int) $value;

        switch ($last) {
            case 'g':
                $bytes *= 1024;
                // fall through
            case 'm':
                $bytes *= 1024;
                // fall through
            case 'k':
                $bytes *= 1024;
                break;
        }

        return $bytes;
    }
}
