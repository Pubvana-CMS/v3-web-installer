<?php

namespace PubvanaInstaller\Source;

use PubvanaInstaller\Result;

/**
 * Shared zip extraction and directory normalization logic for sources that
 * download a zip archive and need to extract it into a target directory.
 */
trait ZipExtractorTrait
{
    /**
     * Extract a zip archive to the given directory.
     *
     * Tries three methods in order:
     *  1. ZipArchive (PHP extension)
     *  2. exec('unzip …')   (system binary)
     *  3. PharData          (Phar extension)
     *
     * Returns Result::fail() if every method is unavailable or fails.
     */
    private function extractZip(string $zipPath, string $extractDir): Result
    {
        // --- 1. ZipArchive ---
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $openResult = $zip->open($zipPath);

            if ($openResult === true) {
                if (!is_dir($extractDir)) {
                    mkdir($extractDir, 0755, true);
                }

                $extracted = $zip->extractTo($extractDir);
                $zip->close();

                if ($extracted) {
                    return Result::ok();
                }

                return Result::fail('ZipArchive::extractTo() returned false for: ' . $zipPath);
            }

            // ZipArchive is available but could not open the file; fall through to next method.
        }

        // --- 2. exec('unzip …') ---
        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            if (!is_dir($extractDir)) {
                mkdir($extractDir, 0755, true);
            }

            $safeZip    = escapeshellarg($zipPath);
            $safeTarget = escapeshellarg($extractDir);
            $output     = [];
            $exitCode   = 0;

            exec("unzip -o {$safeZip} -d {$safeTarget} 2>&1", $output, $exitCode);

            if ($exitCode === 0) {
                return Result::ok();
            }

            // unzip failed; fall through to PharData.
        }

        // --- 3. PharData ---
        if (extension_loaded('phar')) {
            try {
                if (!is_dir($extractDir)) {
                    mkdir($extractDir, 0755, true);
                }

                $phar = new \PharData($zipPath);
                $phar->extractTo($extractDir, null, true);

                return Result::ok();
            } catch (\Exception $e) {
                return Result::fail('PharData extraction failed: ' . $e->getMessage());
            }
        }

        return Result::fail(
            'No zip extraction method is available. '
            . 'Install the zip PHP extension, the unzip binary, or the phar extension.'
        );
    }

    /**
     * If the extraction produced a single nested application directory, move its
     * contents up one level so $dir becomes the root of the project.
     *
     * GitHub-style archives create: $dir/reponame-v1.0.0/. This method flattens
     * that when there is exactly one top-level entry, OR when exactly one entry
     * looks like the application root (contains app + public markers).
     * Hidden entries and non-app directories (e.g. a coexisting installer
     * wrapper) are ignored when picking a candidate.
     */
    private function normalizeDirectory(string $dir): void
    {
        $entries = array_values(array_filter(
            (array) scandir($dir),
            static fn(string $e): bool => $e !== '.' && $e !== '..'
        ));

        // When there is exactly one top-level entry that is a directory, flatten it.
        if (count($entries) === 1 && is_dir($dir . DIRECTORY_SEPARATOR . $entries[0])) {
            $this->flattenSubdirectory($dir, $dir . DIRECTORY_SEPARATOR . $entries[0]);
            return;
        }

        // Otherwise look for exactly one app-root-looking directory to flatten.
        $candidates = array_values(array_filter(
            $entries,
            fn(string $e): bool => str_starts_with($e, '.') === false
                && is_dir($dir . DIRECTORY_SEPARATOR . $e)
                && $this->looksLikeAppRoot($dir . DIRECTORY_SEPARATOR . $e)
        ));

        if (count($candidates) === 1) {
            $this->flattenSubdirectory($dir, $dir . DIRECTORY_SEPARATOR . $candidates[0]);
        }
    }

    /**
     * Move all contents of $subDir up into $dir and remove the now-empty $subDir.
     */
    private function flattenSubdirectory(string $dir, string $subDir): void
    {
        $children = array_filter(
            (array) scandir($subDir),
            static fn(string $e): bool => $e !== '.' && $e !== '..'
        );

        foreach ($children as $child) {
            @rename(
                $subDir . DIRECTORY_SEPARATOR . $child,
                $dir . DIRECTORY_SEPARATOR . $child
            );
        }

        @rmdir($subDir);
    }

    /**
     * Heuristic: a directory that looks like the root of a Pubvana CMS v3 app.
     */
    private function looksLikeAppRoot(string $dir): bool
    {
        return is_dir($dir . DIRECTORY_SEPARATOR . 'app')
            && is_dir($dir . DIRECTORY_SEPARATOR . 'public')
            && file_exists($dir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
    }
}
