<?php

namespace PubvanaInstaller\Source;

use PubvanaInstaller\Result;
use Throwable;

/**
 * Downloads a zip archive via the `wget` command-line utility, then extracts it.
 *
 * Fallback when the cURL extension is unavailable but exec() and wget exist
 * on the server. All work happens inside the target directory to avoid
 * cross-device rename failures.
 */
class WgetSource implements SourceInterface
{
    use ZipExtractorTrait;

    /** Timeout in seconds for the wget transfer. */
    private const TIMEOUT = 300;

    public function __construct(
        private readonly string $zipUrl,
    ) {}

    public function getLabel(): string
    {
        return 'Command-line Download (wget)';
    }

    public function canExecute(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (in_array('exec', $disabled, true)) {
            return false;
        }

        try {
            $output = [];
            @exec('wget --version 2>&1', $output);
        } catch (Throwable) {
            return false;
        }

        foreach ($output as $line) {
            if (stripos($line, 'GNU Wget') !== false) {
                return true;
            }
        }

        return false;
    }

    public function download(string $targetDir): Result
    {
        if (! $this->canExecute()) {
            return Result::fail('The wget command-line utility is not available.');
        }

        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // --- 1. Download zip into target dir ---
        $zipPath = $targetDir . DIRECTORY_SEPARATOR . '_download.zip';

        $output = [];
        $code   = 0;
        $urlArg = escapeshellarg($this->zipUrl);
        $zipArg = escapeshellarg($zipPath);

        try {
            @exec(
                'wget -q --tries=1 --timeout=' . (int) self::TIMEOUT . ' -O ' . $zipArg . ' ' . $urlArg . ' 2>&1',
                $output,
                $code,
            );
        } catch (Throwable $e) {
            return Result::fail('wget failed to start: ' . $e->getMessage());
        }

        if ($code !== 0 || ! is_file($zipPath)) {
            @unlink($zipPath);
            $error = implode("\n", $output);
            return Result::fail(
                'wget download failed (exit ' . $code . '): ' . ($error !== '' ? $error : $this->zipUrl)
            );
        }

        // --- 2. Verify zip magic bytes (PK) ---
        $fh = fopen($zipPath, 'rb');
        if ($fh === false) {
            @unlink($zipPath);
            return Result::fail('Cannot open downloaded file for verification.');
        }
        $magic = fread($fh, 2);
        fclose($fh);

        if ($magic !== 'PK') {
            @unlink($zipPath);
            return Result::fail(
                'Downloaded file does not appear to be a valid zip archive '
                . '(missing PK magic bytes). The server may have returned an error page.'
            );
        }

        // --- 3. Extract into target dir ---
        $extractResult = $this->extractZip($zipPath, $targetDir);
        @unlink($zipPath);

        if (! $extractResult->success) {
            return $extractResult;
        }

        // --- 4. Normalize (flatten single top-level dir) ---
        $this->normalizeDirectory($targetDir);

        return Result::ok();
    }
}