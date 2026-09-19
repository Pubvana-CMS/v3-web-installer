<?php

namespace PubvanaInstaller\Source;

use PubvanaInstaller\Environment\ServerEnvironment;

/**
 * Selects the best available download source based on environment capabilities
 * and the caller-supplied configuration.
 *
 * Priority order:
 *  1. CurlSource, used when source.zip is set and the curl extension is loaded.
 *  2. WgetSource, used when source.zip is set and exec() + wget are available.
 *  3. StreamSource, used when source.zip is set and allow_url_fopen is on.
 *  4. ManualSource. Always works (last resort).
 */
class SourceFactory
{
    /**
     * Return the highest-priority source that can execute given the current
     * environment.
     *
     * @param array                 $sourceConfig Keys: 'zip'
     * @param ServerEnvironment|null $env         Optional pre-detected environment;
     *                                            when provided its flags are used
     *                                            instead of re-detecting.
     */
    public static function getBestSource(
        array $sourceConfig,
        ?ServerEnvironment $env = null,
    ): SourceInterface {
        // --- 1. cURL ---
        if (!empty($sourceConfig['zip'])) {
            $source = new CurlSource($sourceConfig['zip']);

            $curlOk = $env !== null
                ? ($env->extensions['curl'] ?? '') === 'passed'
                : $source->canExecute();

            if ($curlOk) {
                return $source;
            }

            // --- 2. wget (command line) ---
            $wgetSource = new WgetSource($sourceConfig['zip']);

            $wgetOk = $env !== null
                ? $this->wgetOk($env)
                : $wgetSource->canExecute();

            if ($wgetOk) {
                return $wgetSource;
            }

            // --- 3. Stream (file_get_contents) ---
            $streamSource = new StreamSource($sourceConfig['zip']);

            $streamOk = $env !== null
                ? ($env->outboundHttp === 'passed' && (bool) ini_get('allow_url_fopen'))
                : $streamSource->canExecute();

            if ($streamOk) {
                return $streamSource;
            }
        }

        // --- 4. Manual ---
        $zipUrl = $sourceConfig['zip'] ?? '';
        return new ManualSource($zipUrl);
    }

    /**
     * Return all source instances for display purposes (e.g., capability matrix).
     *
     * @param array $sourceConfig Keys: 'zip'
     * @return SourceInterface[]
     */
    public static function getAllSources(array $sourceConfig): array
    {
        $sources = [];

        if (!empty($sourceConfig['zip'])) {
            $sources[] = new CurlSource($sourceConfig['zip']);
            $sources[] = new WgetSource($sourceConfig['zip']);
            $sources[] = new StreamSource($sourceConfig['zip']);
        }

        $sources[] = new ManualSource($sourceConfig['zip'] ?? '');

        return $sources;
    }

    /**
     * Determine whether wget is usable given a pre-detected environment.
     */
    private static function wgetOk(?ServerEnvironment $env): bool
    {
        $execOk = $env->execAvailable === 'passed';

        if (! $execOk) {
            return false;
        }

        // Probe the wget binary once (cheap) rather than trusting detection.
        $output = [];
        @exec('wget --version 2>&1', $output);

        foreach ($output as $line) {
            if (stripos($line, 'GNU Wget') !== false) {
                return true;
            }
        }

        return false;
    }
}
