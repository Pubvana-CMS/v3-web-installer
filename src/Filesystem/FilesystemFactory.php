<?php

namespace PubvanaInstaller\Filesystem;

class FilesystemFactory
{
    /**
     * Auto-detect the best available filesystem driver for the given target directory.
     *
     * Detection order:
     *   1. Write a temp file into $targetDir.
     *   2. Compare fileowner() of the temp file with getmyuid().
     *   3. If they match → DirectDriver (we are the owner, direct access works).
     *   4. If mismatch → DirectDriver is not safe/reliable; fall through.
     *   5. Owner mismatch: check ftp_ssl_connect → FtpsDriver placeholder hint.
     *   6. Check ftp extension → FtpDriver placeholder hint.
     *   7. Check ssh2 extension → Ssh2Driver placeholder hint.
     *   8. Nothing works → return null.
     *
     * Note: For FTP/SFTP drivers, credentials must be collected from the user
     * before instantiation. This method returns null in those cases so the
     * calling wizard can prompt for credentials and use the createFtp / createSsh2
     * factory methods.
     *
     * @param  string $targetDir  Absolute path of the directory we want to install into.
     * @return FilesystemInterface|null  DirectDriver when ownership matches, null otherwise.
     */
    public static function detect(string $targetDir): ?FilesystemInterface
    {
        if (! is_dir($targetDir)) {
            return null;
        }

        // Real write test. This is the only signal that matters. If the web server can
        // create and delete a file here, direct access works regardless of
        // who owns the directory (group-writable dirs, setgid setups, etc.).
        $tempFile = rtrim($targetDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . '.pv3_write_test_' . uniqid('', true);

        $written = @file_put_contents($tempFile, 'pv3-installer-write-check');

        if ($written === false) {
            // Can't write at all; we'll need credentials from the user.
            return null;
        }

        @unlink($tempFile);

        // We can write directly. FTP/SSH credentials are not needed.
        return new DirectDriver();
    }

    /**
     * Create a plain FTP driver with the given credentials.
     */
    public static function createFtp(
        string $host,
        string $user,
        string $pass,
        int    $port = 21,
    ): FtpDriver {
        return new FtpDriver($host, $user, $pass, $port);
    }

    /**
     * Create an FTPS (FTP over SSL) driver with the given credentials.
     */
    public static function createFtps(
        string $host,
        string $user,
        string $pass,
        int    $port = 21,
    ): FtpsDriver {
        return new FtpsDriver($host, $user, $pass, $port);
    }

    /**
     * Create an SSH2/SFTP driver with the given credentials.
     */
    public static function createSsh2(
        string $host,
        string $user,
        string $pass,
        int    $port = 22,
    ): Ssh2Driver {
        return new Ssh2Driver($host, $user, $pass, $port);
    }
}
