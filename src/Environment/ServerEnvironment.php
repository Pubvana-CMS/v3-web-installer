<?php

namespace PubvanaInstaller\Environment;

/**
 * Value object holding all detected server capabilities.
 *
 * Each status property uses three states: 'passed', 'failed', 'unknown'.
 */
class ServerEnvironment
{
    /** Actual PHP version string e.g. '8.3.1' */
    public string $phpVersion = '';

    /** 'passed' | 'failed' | 'unknown' */
    public string $phpVersionOk = 'unknown';

    /** extension name => 'passed' | 'failed' */
    public array $extensions = [];

    /** List of function names that are disabled via disable_functions */
    public array $disabledFunctions = [];

    /** Raw value from php.ini e.g. '128M' */
    public string $memoryLimit = '';

    /** Max execution time in seconds */
    public int $maxExecutionTime = 0;

    /** Raw value from php.ini e.g. '20M' */
    public string $uploadMaxFilesize = '';

    /** 'Apache' | 'Nginx' | 'LiteSpeed' | 'IIS' | 'unknown' */
    public string $serverSoftware = 'unknown';

    /** Document root path */
    public string $documentRoot = '';

    /** 'passed' | 'failed' | 'unknown' */
    public string $https = 'unknown';

    /** 'passed' | 'failed' | 'unknown' */
    public string $modRewrite = 'unknown';

    /** Why the mod_rewrite probe was inconclusive: '' | 'auth' | 'unreachable' */
    public string $modRewriteReason = '';

    /** 'passed' | 'failed' | 'unknown' */
    public string $execAvailable = 'unknown';

    /** 'passed' | 'failed' | 'unknown' */
    public string $shellExecAvailable = 'unknown';

    /** 'direct' | 'ftp' | 'ftps' | 'ssh2' | 'unknown' */
    public string $filesystemMethod = 'unknown';

    /** DB driver name => 'passed' | 'failed' */
    public array $dbDrivers = [];

    /** 'passed' | 'failed' | 'unknown' */
    public string $targetDirWritable = 'unknown';

    /** 'passed' | 'failed' | 'unknown' */
    public string $outboundHttp = 'unknown';

    /** Whether the server OS is Windows */
    public bool $isWindows = false;

    public function __construct()
    {
        // All properties already initialised to safe defaults above.
    }
}
