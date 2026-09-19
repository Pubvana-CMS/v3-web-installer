<?php

namespace PubvanaInstaller;

use PubvanaInstaller\Auth\AuthAdapterFactory;
use PubvanaInstaller\Config\ConfigValidator;
use PubvanaInstaller\Config\EnvWriter;
use PubvanaInstaller\Database\ConnectionTester;
use PubvanaInstaller\Database\MigrationRunner;
use PubvanaInstaller\Environment\Detector;
use PubvanaInstaller\Environment\Requirements;
use PubvanaInstaller\Environment\ServerEnvironment;
use PubvanaInstaller\Filesystem\FilesystemFactory;
use PubvanaInstaller\Filesystem\FilesystemInterface;
use PubvanaInstaller\Source\SourceFactory;
use PubvanaInstaller\UI\WizardRenderer;
use Throwable;

/**
 * Main orchestrator for the Pubvana Installer.
 *
 * Instantiated and executed by install.php:
 *   (new Installer(__DIR__))->run();
 *
 * Responsibilities:
 *   - Session management (start, read, write, clear)
 *   - CSRF protection on every POST
 *   - Step routing (GET/POST → handler method)
 *   - Orchestration of all subsystems
 *   - AJAX substep dispatch and JSON responses
 *   - Cleanup on completion
 */
class Installer
{
    /** Where install.php and installer-config.php live (the wizard's own folder). */
    private string $scriptDir;

    /** Where the application source gets downloaded, extracted and installed to. */
    private string $targetDir;

    /** How many directory levels the install target sits above the wizard folder. */
    private int $targetUpSteps = 0;

    /**
     * Where this Installer.php file was extracted to (the temp dir).
     * Since this file is at {extractedDir}/Installer.php, we derive it from __DIR__.
     */
    private string $extractedDir;

    /** Loaded from installer-config.php and populated with defaults. */
    private array $config;

    /** Template engine for rendering wizard steps. */
    private WizardRenderer $renderer;

    /** Populated after environment detection (cached in session). */
    private ?ServerEnvironment $env = null;

    /** Filesystem driver (cached after first use). */
    private ?FilesystemInterface $fs = null;

    // -------------------------------------------------------------------------
    // Session keys
    // -------------------------------------------------------------------------

    private const SESS_ENV           = 'pubvana_installer_env';
    private const SESS_FS_METHOD     = 'pubvana_installer_fs_method';
    private const SESS_FS_CREDS      = 'pubvana_installer_fs_creds';
    private const SESS_DB_DRIVER     = 'pubvana_installer_db_driver';
    private const SESS_DB_CREDS      = 'pubvana_installer_db_creds';
    private const SESS_CONFIGURATION = 'pubvana_installer_configuration';
    private const SESS_APP_SETTINGS  = 'pubvana_installer_app_settings';
    private const SESS_ADMIN         = 'pubvana_installer_admin';
    private const SESS_SUBSTEPS      = 'pubvana_installer_substeps';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param string      $scriptDir  Absolute path of the folder holding install.php.
     * @param string|null $targetDir  Absolute install target. When null it is read
     *                                from config 'install.target_dir' (default '../',
     *                                i.e. one level above the wizard folder, the
     *                                typical served document root).
     */
    public function __construct(string $scriptDir, ?string $targetDir = null)
    {
        $this->scriptDir    = rtrim($scriptDir, '/\\');
        $this->extractedDir = rtrim(__DIR__, '/\\');

        // Load developer configuration.
        $configFile = $this->scriptDir . '/installer-config.php';

        if (! file_exists($configFile)) {
            $this->die(
                'installer-config.php not found',
                "Expected at: <code>{$configFile}</code><br>"
                . "Create installer-config.php in the same directory as install.php."
            );
        }

        $config = require $configFile;

        if (! is_array($config)) {
            $this->die(
                'installer-config.php must return an array',
                "The file was loaded but did not return a PHP array. "
                . "Ensure it ends with <code>return [...];</code>"
            );
        }

        // Validate before applying defaults (so we catch developer mistakes).
        $errors = ConfigValidator::validate($config);

        if ($errors !== []) {
            $list = implode('</li><li>', array_map('htmlspecialchars', $errors));
            $this->die(
                'installer-config.php has configuration errors',
                "<ul><li>{$list}</li></ul>"
            );
        }

        $this->config = ConfigValidator::withDefaults($config);

        // Resolve the install target (where the app is written to).
        $this->targetDir = $this->resolveTargetDir($targetDir);
        $this->targetUpSteps = $this->countTargetUpSteps();

        // Determine template directory (shipped with installer source).
        $templateDir = $this->extractedDir . '/UI/templates';

        $this->renderer = new WizardRenderer($templateDir, $this->config);
    }

    // -------------------------------------------------------------------------
    // Target directory resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the absolute install target from an explicit path or config.
     *
     * Absolute path → used as-is. Empty string → the wizard's own folder.
     * Relative path → resolved against the wizard folder (so '../' = parent).
     */
    private function resolveTargetDir(?string $explicit): string
    {
        $target = $explicit;

        if ($target === null) {
            $target = (string) ($this->config['install']['target_dir'] ?? '..');
        }

        $target = trim($target);

        if ($target === '') {
            return $this->scriptDir;
        }

        if (str_starts_with($target, '/')) {
            return rtrim($target, '/\\');
        }

        return $this->resolveRelativePath($this->scriptDir, $target);
    }

    /**
     * Resolve a relative path (with ./.. segments) against a base directory.
     */
    private function resolveRelativePath(string $base, string $relative): string
    {
        $path = rtrim($base, '/\\');
        $rel  = str_replace('\\', '/', $relative);

        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                $path = rtrim(dirname($path), '/\\');
                continue;
            }

            $path .= '/' . $segment;
        }

        return $path;
    }

    /**
     * Count how many directory levels the install target sits above the
     * wizard folder. Used to map the wizard's URL to the app's URL.
     */
    private function countTargetUpSteps(): int
    {
        $steps = 0;
        $path  = $this->scriptDir;
        $target = rtrim($this->targetDir, '/\\');

        while ($path !== '' && $path !== '/' && $path !== $target) {
            $path = rtrim(dirname($path), '/\\');
            $steps++;
        }

        return $path === $target ? $steps : 0;
    }

    // -------------------------------------------------------------------------
    // Main entry point
    // -------------------------------------------------------------------------

    /**
     * Handle the current HTTP request and output the response.
     */
    public function run(): void
    {
        $this->startSession();

        $step = $this->resolveStep();

        // CSRF check on every POST.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            if (! $this->renderer->validateCsrf($token)) {
                // For AJAX substep requests return JSON; otherwise show error page.
                if ($this->isAjaxSubstepRequest()) {
                    $this->jsonResponse(['success' => false, 'message' => 'CSRF token invalid. Please refresh and try again.']);
                    return;
                }
                $this->die('Security Error', 'CSRF token invalid or missing. Please go back and try again.');
            }
        }

        // Handle AJAX actions that aren't tied to a specific step.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'generate_key') {
            $this->jsonResponse(['key' => bin2hex(random_bytes(32))]);
            return;
        }

        // Route to the appropriate handler.
        $html = match ($step) {
            'welcome'       => $this->handleWelcome(),
            'system-check'  => $this->handleSystemCheck(),
            'filesystem'    => $this->handleFilesystem(),
            'database'      => $this->handleDatabase(),
            'configuration' => $this->handleConfiguration(),
            'app-settings'  => $this->handleAppSettings(),
            'admin'         => $this->handleAdmin(),
            'install'       => $this->handleInstall(),
            'complete'      => $this->handleComplete(),
            default         => $this->handleWelcome(),
        };

        // handleInstall() may have already output JSON and returned null.
        if ($html !== null) {
            echo $html;
        }
    }

    // -------------------------------------------------------------------------
    // Session bootstrap
    // -------------------------------------------------------------------------

    /**
     * Start the PHP session with secure settings.
     * Respects HTTPS environment for the Secure cookie flag.
     */
    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            // Session already active (e.g. WizardRenderer started it).
            return;
        }

        $isHttps = $this->detectHttps();

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        @session_start();
    }

    // -------------------------------------------------------------------------
    // Step handlers
    // -------------------------------------------------------------------------

    /**
     * Handles the welcome step. Displays branding info and intro copy. Nothing is collected yet.
     */
    private function handleWelcome(): string
    {
        return $this->renderer->render('welcome', [
            'branding' => $this->config['branding'],
        ]);
    }

    /**
     * Handles the system check step. Runs environment detection and the requirements check.
     */
    private function handleSystemCheck(): string
    {
        $env = $this->getServerEnvironment();

        $requirements = new Requirements($env, $this->config);
        $checks       = $requirements->check();
        $hasBlockers  = $requirements->hasBlockers();

        return $this->renderer->render('system-check', [
            'env'          => $env,
            'requirements' => $checks,
            'hasBlockers'  => $hasBlockers,
        ]);
    }

    /**
     * Handles the filesystem step. Detects the available write method.
     *
     * If DirectDriver is detected, auto-advance by storing the method in session.
     * If credentials are POSTed for FTP/SSH, create and test the driver.
     */
    private function handleFilesystem(): string
    {
        $directDriver = FilesystemFactory::detect($this->targetDir);

        if ($directDriver !== null) {
            // Direct access is available, so store the method and skip credential collection.
            $this->saveToSession(self::SESS_FS_METHOD, 'direct');
            $this->saveToSession(self::SESS_FS_CREDS, []);
        }

        $method = $this->getFromSession(self::SESS_FS_METHOD);
        $error  = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $directDriver === null) {
            $fsMethod = $_POST['fs_type']       ?? '';
            $host     = trim($_POST['fs_hostname'] ?? '');
            $user     = trim($_POST['fs_username'] ?? '');
            $pass     = $_POST['fs_password']       ?? '';
            $port     = (int) ($_POST['fs_port']   ?? 0);

            try {
                $driver = match ($fsMethod) {
                    'ftps' => FilesystemFactory::createFtps($host, $user, $pass, $port > 0 ? $port : 21),
                    'ftp'  => FilesystemFactory::createFtp($host, $user, $pass, $port > 0 ? $port : 21),
                    'ssh2' => FilesystemFactory::createSsh2($host, $user, $pass, $port > 0 ? $port : 22),
                    default => null,
                };

                if ($driver === null) {
                    $error = 'Unknown filesystem method selected.';
                } else {
                    // Test the driver by checking if the target dir exists.
                    if ($driver->exists($this->targetDir)) {
                        $this->saveToSession(self::SESS_FS_METHOD, $fsMethod);
                        $this->saveToSession(self::SESS_FS_CREDS, [
                            'host' => $host,
                            'user' => $user,
                            'pass' => $pass,
                            'port' => $port,
                        ]);
                        $this->fs = $driver;
                        $this->redirect('database');
                    } else {
                        $error = "Connection succeeded but target directory is not accessible: {$this->targetDir}";
                    }
                }
            } catch (Throwable $e) {
                $error = 'Connection failed: ' . $e->getMessage();
            }
        }

        return $this->renderer->render('filesystem', [
            'detectedMethod' => $directDriver !== null ? 'direct' : null,
            'method'         => $method,
            'error'          => $error,
        ]);
    }

    /**
     * Handles the database step. Collects and tests database credentials.
     */
    private function handleDatabase(): ?string
    {
        $env     = $this->getServerEnvironment();
        $error   = null;
        $success = null;
        $driver  = $this->getFromSession(self::SESS_DB_DRIVER, '');
        $creds   = $this->getFromSession(self::SESS_DB_CREDS, []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_driver'])) {
            $action   = $_POST['action'] ?? 'test';
            $driver   = trim($_POST['db_driver']   ?? '');
            $hostname = trim($_POST['db_hostname']  ?? '127.0.0.1');
            $port     = trim($_POST['db_port']      ?? '');
            $database = trim($_POST['db_database']  ?? '');
            $username = trim($_POST['db_username']  ?? '');
            $password = $_POST['db_password']        ?? '';
            $path     = trim($_POST['db_path']      ?? '');

            $creds = [
                'hostname' => $hostname,
                'port'     => $port !== '' ? (int) $port : null,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'path'     => $path,
            ];

            // Remove null port so the tester uses its own default.
            if ($creds['port'] === null) {
                unset($creds['port']);
            }

            $tester = new ConnectionTester();

            if ($action === 'create_database') {
                $result = $tester->createDatabase($driver, $creds);
                if ($result->success) {
                    $success = $result->content ?: "Database created successfully.";
                    // Now test the connection for real.
                    $testResult = $tester->test($driver, $creds);
                    if ($testResult->success) {
                        $this->saveToSession(self::SESS_DB_DRIVER, $driver);
                        $this->saveToSession(self::SESS_DB_CREDS, $creds);
                    } else {
                        $error = $testResult->errorMessage;
                    }
                } else {
                    $error = $result->errorMessage;
                }
            } else {
                // Standard connection test.
                $result = $tester->test($driver, $creds);
                if ($result->success) {
                    $this->saveToSession(self::SESS_DB_DRIVER, $driver);
                    $this->saveToSession(self::SESS_DB_CREDS, $creds);

                    // AJAX test: return JSON. Form submit: redirect to next step.
                    if ($action === 'test_database') {
                        $this->jsonResponse(['success' => true, 'message' => 'Connection successful.']);
                        return null;
                    }
                    $this->redirect('configuration');
                } else {
                    if ($action === 'test_database') {
                        $this->jsonResponse(['success' => false, 'message' => $result->errorMessage]);
                        return null;
                    }
                    $error = $result->errorMessage;
                }
            }
        }

        // Build available drivers list (only those present on the server).
        $availableDrivers = [];
        foreach ($env->dbDrivers as $driverName => $status) {
            if ($status === 'passed') {
                $driverLabel = $this->mapDbExtToDriver($driverName);
                if ($driverLabel !== null) {
                    $availableDrivers[$driverLabel] = $driverName;
                }
            }
        }

        return $this->renderer->render('database', [
            'availableDrivers' => array_keys($availableDrivers),
            'driver'           => $driver,
            'creds'            => $creds,
            'error'            => $error,
            'success'          => $success,
            'savedDriver'      => $this->getFromSession(self::SESS_DB_DRIVER, ''),
        ]);
    }

    /**
     * Handles the configuration step. Collects the site URL, APP_ENV and session encryption key.
     */
    private function handleConfiguration(): string
    {
        $error   = null;
        $saved   = $this->getFromSession(self::SESS_CONFIGURATION, []);

        // Defaults / auto-detected values.
        $defaults = [
            'base_url'       => $this->detectBaseUrl(),
            'environment'    => 'production',
            'encryption_key' => $this->generateEncryptionKey(),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $baseUrl       = trim($_POST['base_url']        ?? '');
            $environment   = trim($_POST['environment']     ?? 'production');
            $encryptionKey = trim($_POST['encrypt_key']  ?? '');

            // Basic validation.
            if ($baseUrl === '') {
                $error = 'Base URL is required.';
            } elseif (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                $error = 'Base URL does not appear to be a valid URL.';
            } elseif (! in_array($environment, ['production', 'development'], true)) {
                $error = 'Environment must be one of: production, development.';
            } elseif (! preg_match('/^[0-9a-fA-F]{64}$/', $encryptionKey)) {
                $error = 'Session encryption key must be 64 hexadecimal characters.';
            }

            if ($error === null) {
                $saved = [
                    'base_url'       => rtrim($baseUrl, '/') . '/',
                    'environment'    => $environment,
                    'encryption_key' => $encryptionKey,
                ];
                $this->saveToSession(self::SESS_CONFIGURATION, $saved);
                $this->redirect('app-settings');
            }
        }

        $values = $saved + $defaults;

        return $this->renderer->render('configuration', [
            'values'   => $values,
            'defaults' => $defaults,
            'error'    => $error,
        ]);
    }

    /**
     * Handles the app settings step. Renders the developer-defined env_vars from the config.
     * If no env_vars in config, skip to the next step.
     */
    private function handleAppSettings(): string
    {
        $envVars = $this->config['env_vars'] ?? [];

        if (empty($envVars)) {
            // Nothing to collect, so skip the step.
            $this->redirect('admin');
            return '';
        }

        $error  = null;
        $saved  = $this->getFromSession(self::SESS_APP_SETTINGS, []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $envPost = (array) ($_POST['env'] ?? []);
            $values  = [];
            $missingRequired = [];

            foreach ($envVars as $varDef) {
                $key      = $varDef['key']      ?? '';
                $required = $varDef['required']  ?? false;
                $type     = $varDef['type']      ?? 'text';

                if ($key === '') {
                    continue;
                }

                if ($type === 'boolean') {
                    $values[$key] = isset($envPost[$key]) ? 'true' : 'false';
                } else {
                    $value = trim($envPost[$key] ?? '');

                    if ($required && $value === '') {
                        $missingRequired[] = $varDef['label'] ?? $key;
                    }

                    // Pattern validation.
                    if ($value !== '' && isset($varDef['pattern'])) {
                        $pattern = $varDef['pattern'];
                        if (@preg_match($pattern, $value) === 0) {
                            $label = $varDef['label'] ?? $key;
                            $error = "Value for '{$label}' does not match the required format.";
                            break;
                        }
                    }

                    $values[$key] = $value !== '' ? $value : ($varDef['default'] ?? '');
                }
            }

            if ($error === null && ! empty($missingRequired)) {
                $error = 'The following fields are required: ' . implode(', ', $missingRequired);
            }

            if ($error === null) {
                $this->saveToSession(self::SESS_APP_SETTINGS, $values);
                $saved = $values;
                $this->redirect('admin');
            }
        }

        // Group env_vars for rendering.
        $grouped = $this->groupEnvVars($envVars);

        return $this->renderer->render('app-settings', [
            'envVars' => $envVars,
            'grouped' => $grouped,
            'saved'   => $saved,
            'error'   => $error,
        ]);
    }

    /**
     * Handles the admin account step. Collects admin credentials via the auth adapter.
     * If auth.system is 'none', skip.
     */
    private function handleAdmin(): string
    {
        $authSystem = $this->config['auth']['system'] ?? 'none';

        if ($authSystem === 'none') {
            $this->redirect('install');
            return '';
        }

        $dbCreds = $this->getFromSession(self::SESS_DB_CREDS, []);
        $adapter = AuthAdapterFactory::create($this->config, $this->targetDir, $dbCreds);
        $fields  = $adapter->getFields();
        $error   = null;
        $saved   = $this->getFromSession(self::SESS_ADMIN, []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin'])) {
            $adminPost = (array) $_POST['admin'];
            $values    = [];
            $missing   = [];

            foreach ($fields as $field) {
                $key      = $field['name']     ?? '';
                $required = $field['required']  ?? true;
                $value    = trim($adminPost[$key] ?? '');

                if ($key === '') {
                    continue;
                }

                if ($required && $value === '') {
                    $missing[] = $field['label'] ?? $key;
                    continue;
                }

                $minLength = (int) ($field['minlength'] ?? 0);
                $maxLength = (int) ($field['maxlength'] ?? 0);
                $label     = $field['label'] ?? $key;

                if ($value !== '' && $minLength > 0 && mb_strlen($value) < $minLength) {
                    $error = "{$label} must be at least {$minLength} characters.";
                    break;
                }

                if ($value !== '' && $maxLength > 0 && mb_strlen($value) > $maxLength) {
                    $error = "{$label} must be at most {$maxLength} characters.";
                    break;
                }

                $values[$key] = $value;
            }

            // Password confirmation check.
            $password        = $values['password']                ?? '';
            $passwordConfirm = trim($adminPost['password_confirm'] ?? '');

            if ($password !== '' && $passwordConfirm !== '' && $password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
            }

            if ($error === null && ! empty($missing)) {
                $error = 'The following fields are required: ' . implode(', ', $missing);
            }

            if ($error === null) {
                $this->saveToSession(self::SESS_ADMIN, $values);
                $saved = $values;
                $this->redirect('install');
            }
        }

        return $this->renderer->render('admin', [
            'authSystem' => $authSystem,
            'fields'     => $fields,
            'saved'      => $saved,
            'error'      => $error,
        ]);
    }

    /**
     * Handles the install step. Shows progress UI or dispatches AJAX substeps.
     *
     * GET (no substep): render the installation progress page.
     * POST/GET with substep: execute the substep and return JSON.
     */
    private function handleInstall(): ?string
    {
        // Handle manual zip upload.
        if (! empty($_FILES['manual_zip']['tmp_name'])) {
            $this->handleManualUpload();
            return null;
        }

        $substep = $_POST['substep'] ?? $_GET['substep'] ?? null;

        if ($substep !== null) {
            // AJAX substep execution.
            @set_time_limit(300);

            // Buffer output. The runway CLI can echo stray text that would
            // corrupt the JSON response.
            ob_start();

            try {
                $result = match ((string) $substep) {
                    'download'    => $this->substepDownload(),
                    'extract'     => $this->substepExtract(),
                    'validate'    => $this->substepValidate(),
                    'public_dir'  => $this->substepPublicDir(),
                    'write_env'   => $this->substepWriteEnv(),
                    'permissions' => $this->substepPermissions(),
                    'migrations'  => $this->substepMigrations(),
                    'admin'       => $this->substepAdmin(),
                    default       => ['success' => false, 'message' => "Unknown substep: {$substep}"],
                };
            } catch (Throwable $e) {
                $result = [
                    'success' => false,
                    'message' => 'Unexpected error: ' . $e->getMessage(),
                ];
            }

            $strayOutput = ob_get_clean();

            // Append any captured output to failures so the real error is visible.
            if ($strayOutput !== '' && $strayOutput !== false && empty($result['success'])) {
                $result['message'] = ($result['message'] ?? 'Unknown error.') . "\n\n" . $strayOutput;
            }

            // Mark completion in session so the UI can track status.
            if (! empty($result['success'])) {
                $completed = $this->getFromSession(self::SESS_SUBSTEPS, []);
                $completed[$substep] = true;
                $this->saveToSession(self::SESS_SUBSTEPS, $completed);
            }

            $this->jsonResponse($result);
            return null;
        }

        // Render the install progress page.
        return $this->renderer->render('install', [
            'substeps'   => $this->buildSubstepList(),
            'csrfToken'  => $this->renderer->getCsrfToken(),
        ]);
    }

    /**
     * Handles the complete step. Self-deletes installer files, cleans up and clears the session.
     */
    private function handleComplete(): string
    {
        $fs = $this->getFilesystem();

        // Attempt to delete all installer files. The wizard's own files live in
        // the script dir; the uploaded distribution zip may sit beside the
        // wizard (script dir) or beside the installed app (target dir).
        $scriptBase     = basename($_SERVER['SCRIPT_NAME'] ?? 'install.php');
        $scriptBaseZip  = pathinfo($scriptBase, PATHINFO_FILENAME) . '.zip';
        $installerFiles = [
            $this->scriptDir . '/' . $scriptBase,
            $this->scriptDir . '/' . $scriptBaseZip,
            $this->scriptDir . '/installer-config.php',
            $this->targetDir . '/' . $scriptBaseZip,
        ];

        $deleted = true;

        foreach ($installerFiles as $file) {
            if (file_exists($file)) {
                $result = $fs->delete($file);
                if (! $result->success) {
                    $deleted = false;
                }
            }
        }

        // Fallback: create install.lock if any deletes failed.
        if (! $deleted) {
            $lockFile = $this->scriptDir . '/install.lock';
            @file_put_contents($lockFile, date('Y-m-d H:i:s') . ' -- Installation complete. Delete this file to re-run the installer.');
        }

        // Build the post-install URL from the base URL before clearing session.
        $configuration  = $this->getFromSession(self::SESS_CONFIGURATION, []);
        $baseUrl        = rtrim($configuration['base_url'] ?? $this->detectBaseUrl(), '/');
        $postInstallUrl = $baseUrl . '/' . ltrim($this->config['post_install_url'] ?? '/', '/');

        // Clear session data.
        $this->clearInstallSession();

        // Render before cleanup because the templates live inside the extracted directory.
        $html = $this->renderer->render('complete', [
            'installerRemoved' => $deleted,
            'postInstallUrl'   => $postInstallUrl,
            'branding'         => $this->config['branding'],
        ]);

        // Remove the temp extraction directory after rendering.
        $this->cleanupExtractedDir();

        // Remove the (now empty) wizard folder when the app installed elsewhere.
        if ($this->scriptDir !== $this->targetDir) {
            @rmdir($this->scriptDir);
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Substep handlers
    // -------------------------------------------------------------------------

    /**
     * 4a. Download the application source.
     */
    private function substepDownload(): array
    {
        // State recovery: if key files already present, skip.
        if (file_exists($this->targetDir . '/runway') && is_dir($this->targetDir . '/app')) {
            return ['success' => true, 'message' => 'Skipped. The step is already complete.'];
        }

        $env     = $this->getServerEnvironment();
        $sources = SourceFactory::getAllSources($this->config['source'] ?? []);

        // Try each source in priority order until one succeeds.
        $result = null;
        $errors = [];

        foreach ($sources as $source) {
            if ($source instanceof \PubvanaInstaller\Source\ManualSource) {
                continue;
            }

            if (! $source->canExecute()) {
                continue;
            }

            $result = $source->download($this->targetDir);

            if ($result->success) {
                break;
            }

            $errors[] = $source->getLabel() . ': ' . $result->errorMessage;
        }

        if ($result === null || ! $result->success) {
            $message = ! empty($errors)
                ? implode("\n", $errors)
                : 'No download sources available.';
            $response = ['success' => false, 'message' => $message];

            // If all automated sources failed, offer manual upload.
            $zipUrl = $this->config['source']['zip'] ?? '';
            if ($zipUrl !== '') {
                $response['manual_upload'] = true;
                $response['zip_url']       = $zipUrl;
            }

            return $response;
        }

        return ['success' => true, 'message' => 'Download complete.'];
    }

    /**
     * 4b. Extract and normalize the directory structure.
     */
    private function substepExtract(): array
    {
        $installDir = $this->targetDir;

        if (! is_dir($installDir)) {
            return ['success' => false, 'message' => 'No downloaded source found. Please re-run the download step.'];
        }

        // State recovery: if key app files already exist, extraction is done.
        if (file_exists($installDir . '/runway') && is_dir($installDir . '/app')) {
            // Normalize nested directory if needed.
            $this->normalizeDirectory($installDir);
            return ['success' => true, 'message' => 'Skipped. The step is already complete.'];
        }

        // The source's download() may return a zip path; in that case we need to extract.
        // Check if the directory contains a zip file that needs extracting.
        $zipFile = $this->findZipFile($installDir);

        if ($zipFile !== null) {
            $extractResult = $this->extractZip($zipFile, $installDir);

            if (! $extractResult['success']) {
                return $extractResult;
            }

            @unlink($zipFile);
        }

        // Normalize nested directory structure (GitHub-style archives create a subdirectory).
        $normalized = $this->normalizeDirectory($installDir);

        return ['success' => true, 'message' => $normalized ? 'Extracted and normalized.' : 'Extracted successfully.'];
    }

    /**
     * 4c. Validate the downloaded application files.
     */
    private function substepValidate(): array
    {
        $installDir = $this->targetDir;

        if (! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Source directory not found. Please re-run the download step.'];
        }

        // State recovery.
        if (
            file_exists($installDir . '/vendor/autoload.php')
            && file_exists($installDir . '/runway')
            && file_exists($installDir . '/public/index.php')
        ) {
            return ['success' => true, 'message' => 'Skipped. The step is already complete.'];
        }

        // Check for required files.
        $missing = [];

        if (! file_exists($installDir . '/runway')) {
            $missing[] = 'runway';
        }

        if (! file_exists($installDir . '/public/index.php')) {
            $missing[] = 'public/index.php';
        }

        if (! file_exists($installDir . '/app/config/services.php')) {
            $missing[] = 'app/config/services.php';
        }

        if (! empty($missing)) {
            return [
                'success' => false,
                'message' => 'Downloaded source is incomplete. Missing: ' . implode(', ', $missing)
                    . '. Ensure your source package is a valid Pubvana CMS v3 application.',
            ];
        }

        // Check for vendor directory.
        if (! file_exists($installDir . '/vendor/autoload.php')) {
            return [
                'success' => false,
                'message' => 'vendor/autoload.php is missing. '
                    . 'The release package must include the vendor directory.',
            ];
        }

        return ['success' => true, 'message' => 'Validation complete. All required files are present.'];
    }

    /**
     * 4d. Handle the public/ directory convention.
     *
     * The v3 release package ships a root .htaccess that routes to public/,
     * so the default handling is 'none'. When 'htaccess' is requested, write
     * a fallback root .htaccess plus deny rules for sensitive directories.
     */
    private function substepPublicDir(): array
    {
        $installDir = $this->targetDir;

        if (! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Source directory not found.'];
        }

        $handling = $this->config['public_dir_handling'] ?? 'none';

        // If there is no public/ directory, there is nothing to handle.
        if (! is_dir($installDir . '/public')) {
            return ['success' => true, 'message' => 'No public/ directory found. There is nothing to restructure.'];
        }

        switch ($handling) {
            case 'htaccess':
                $result = $this->strategyHtaccess($installDir, $this->getServerEnvironment());
                if ($result['success']) {
                    return $result;
                }
                return ['success' => false, 'message' => $result['message']];

            case 'auto':
                // Prefer 'none' when a root .htaccess is already present.
                if (file_exists($installDir . '/.htaccess')) {
                    return ['success' => true, 'message' => 'Root .htaccess is already present. No changes are needed.'];
                }
                return $this->strategyHtaccess($installDir, $this->getServerEnvironment());

            case 'none':
            default:
                return ['success' => true, 'message' => 'Skipped. The app ships its own routing rules.'];
        }
    }

/**
     * Strategy: Write .htaccess/server config to route requests to public/.
     */
    private function strategyHtaccess(string $installDir, ServerEnvironment $env): array
    {
        $server = $env->serverSoftware;

        if ($server === 'Nginx') {
            // Nginx cannot be auto-configured, so provide instructions instead.
            $nginxConf = $this->buildNginxConfig($installDir);
            $this->saveToSession('nginx_conf_instructions', $nginxConf);
            return [
                'success' => true,
                'message' => 'Nginx detected. Manual server configuration is required. Instructions have been saved.',
                'warning' => 'Nginx cannot be configured automatically. Please apply the Nginx config shown in the UI.',
                'requires_manual' => true,
                'manual_type'     => 'nginx',
                'manual_config'   => $nginxConf,
            ];
        }

        if ($server === 'IIS') {
            $webConfigPath = $installDir . '/web.config';
            $webConfig     = $this->buildWebConfig();
            if (@file_put_contents($webConfigPath, $webConfig) === false) {
                return ['success' => false, 'message' => 'Failed to write web.config for IIS.'];
            }
            return ['success' => true, 'message' => 'web.config written for IIS URL Rewrite.'];
        }

        // Apache / LiteSpeed.
        if ($env->modRewrite === 'failed') {
            return ['success' => false, 'message' => 'Apache mod_rewrite is disabled.'];
        }

        // Write root-level .htaccess.
        $rootHtaccess = "Options -Indexes\n"
            . "RewriteEngine On\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule ^(.*)$ public/index.php/$1 [QSA,L]\n";

        $htaccessPath = $installDir . '/.htaccess';

        if (@file_put_contents($htaccessPath, $rootHtaccess) === false) {
            return ['success' => false, 'message' => 'Failed to write root .htaccess file.'];
        }

        // Write deny .htaccess in sensitive directories.
        $denyHtaccess = "<IfModule authz_core_module>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !authz_core_module>\n"
            . "    Deny from all\n"
            . "</IfModule>\n";

        foreach (['app', 'vendor', 'writable', 'plugins', 'themes'] as $dir) {
            $path = $installDir . '/' . $dir;
            if (is_dir($path)) {
                @file_put_contents($path . '/.htaccess', $denyHtaccess);
            }
        }

        return ['success' => true, 'message' => 'Root .htaccess written to route all requests to public/index.php.'];
    }

    /**
     * 4e. Write the .env file.
     */
    private function substepWriteEnv(): array
    {
        $installDir = $this->targetDir;

        if (! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        // State recovery: if .env already exists and contains our marker, skip.
        $envPath = $installDir . '/.env';
        if (file_exists($envPath)) {
            $existing = @file_get_contents($envPath);
            if ($existing !== false && str_contains($existing, 'Pubvana Installer')) {
                return ['success' => true, 'message' => 'Skipped. The .env file already exists.'];
            }
        }

        // Collect values from session.
        $configuration = $this->getFromSession(self::SESS_CONFIGURATION, []);
        $appSettings   = $this->getFromSession(self::SESS_APP_SETTINGS, []);
        $dbDriver      = $this->getFromSession(self::SESS_DB_DRIVER, '');
        $dbCreds       = $this->getFromSession(self::SESS_DB_CREDS, []);

        $values = [];

        // Core Pubvana v3 env vars.
        if (isset($configuration['environment']) && $configuration['environment'] !== '') {
            $values['APP_ENV'] = $configuration['environment'];
        }

        if (isset($configuration['base_url']) && $configuration['base_url'] !== '') {
            $values['SITE_URL'] = rtrim($configuration['base_url'], '/') . '/';
        }

        if (isset($configuration['encryption_key']) && $configuration['encryption_key'] !== '') {
            $values['SESSION_ENCRYPTION_KEY'] = $configuration['encryption_key'];
        }

        // Database credentials (MySQL / MariaDB only for v3).
        if ($dbDriver !== '') {
            $targets = [
                'hostname' => 'DB_HOST',
                'port'     => 'DB_PORT',
                'database' => 'DB_NAME',
                'username' => 'DB_USER',
                'password' => 'DB_PASS',
            ];

            foreach ($targets as $srcKey => $envKey) {
                if (! empty($dbCreds[$srcKey])) {
                    $values[$envKey] = $dbCreds[$srcKey];
                }
            }
        }

        // Developer-defined env vars.
        foreach ($appSettings as $key => $value) {
            $values[$key] = $value;
        }

        $fs     = $this->getFilesystem();
        $writer = new EnvWriter($fs);
        $result = $writer->write($installDir, $values);

        if (! $result->success) {
            // Fallback: generate display content for manual copy.
            $displayContent = $writer->generateDisplay($installDir, $values);
            return [
                'success'         => false,
                'message'         => $result->errorMessage,
                'manual_fallback' => $displayContent,
            ];
        }

        return ['success' => true, 'message' => '.env file written successfully.'];
    }

    /**
     * 4f. Create writable directories and set permissions.
     */
    private function substepPermissions(): array
    {
        $installDir = $this->targetDir;

        if (! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        $writableDirs  = $this->config['writable_dirs']              ?? [];
        $permissions   = $this->config['writable_dir_permissions']   ?? 0755;
        $fs            = $this->getFilesystem();
        $errors        = [];
        $created       = [];

        foreach ($writableDirs as $relPath) {
            $absPath = $installDir . '/' . ltrim($relPath, '/\\');

            // Create directory if it doesn't exist.
            if (! $fs->exists($absPath)) {
                $mkResult = $fs->mkdir($absPath, true);
                if (! $mkResult->success) {
                    $errors[] = "Failed to create {$relPath}: " . $mkResult->errorMessage;
                    continue;
                }
                $created[] = $relPath;
            }

            // Set permissions recursively on this directory and all subdirectories.
            $chmodResult = $fs->chmod($absPath, $permissions);
            if (! $chmodResult->success) {
                // Non-fatal on Windows or when using FTP drivers that don't support chmod.
                $errors[] = "Warning: could not chmod {$relPath}: " . $chmodResult->errorMessage;
            }
            $this->chmodRecursive($fs, $absPath, $permissions, $errors, $relPath);
        }

        if (! empty($errors) && count($errors) === count($writableDirs)) {
            return [
                'success' => false,
                'message' => 'All permission operations failed: ' . implode('; ', $errors),
            ];
        }

        $message = empty($created)
            ? 'Writable directories verified.'
            : 'Created: ' . implode(', ', $created) . '.';

        if (! empty($errors)) {
            $message .= ' Warnings: ' . implode('; ', $errors);
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * Recursively chmod all subdirectories within a given path.
     */
    private function chmodRecursive(object $fs, string $dirPath, int $permissions, array &$errors, string $relBase): void
    {
        $listResult = $fs->listDir($dirPath);
        if (! $listResult->success) {
            return;
        }

        foreach ($listResult->content as $entry) {
            $absChild = $dirPath . '/' . $entry;
            $relChild = $relBase . '/' . $entry;

            if (! is_dir($absChild)) {
                continue;
            }

            $chmodResult = $fs->chmod($absChild, $permissions);
            if (! $chmodResult->success) {
                $errors[] = "Warning: could not chmod {$relChild}: " . $chmodResult->errorMessage;
            }

            $this->chmodRecursive($fs, $absChild, $permissions, $errors, $relChild);
        }
    }

    /**
     * 4g. Trigger the application's first-run migrations and seeds.
     *
     * v3 self-installs: the first HTTP request to the site (no
     * .migrations_installed marker present) runs every migration tier
     * (foundation, core, enabled plugins) plus seeds during bootstrap
     * (PluginLoader::loadPlugins). This substep fires that request from the
     * installer, waits for the marker, then does a render check.
     */
    private function substepMigrations(): array
    {
        if (! ($this->config['post_install']['migrate'] ?? true)) {
            return ['success' => true, 'message' => 'Skipped. Migrations are disabled in the config.'];
        }

        $installDir = $this->targetDir;

        if (! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        $configuration = $this->getFromSession(self::SESS_CONFIGURATION, []);
        $siteUrl       = $configuration['base_url'] ?? rtrim($this->detectBaseUrl(), '/') . '/';

        $runner = new MigrationRunner($installDir, $siteUrl);

        $result = $runner->runMigrations();

        if ($result->success) {
            return ['success' => true, 'message' => $result->content ?? 'Migrations and seeds applied successfully.'];
        }

        return [
            'success' => false,
            'message' => 'Migrations failed: ' . $result->errorMessage
                . ' You can also run `php runway migrate:all` from the site directory via SSH, '
                . 'then retry this step.',
        ];
    }

    /**
     * 4i. Create the admin user via the configured auth adapter.
     */
    private function substepAdmin(): array
    {
        $authSystem = $this->config['auth']['system'] ?? 'none';

        if ($authSystem === 'none') {
            return ['success' => true, 'message' => 'Skipped. No auth system is configured.'];
        }

        $installDir  = $this->targetDir;
        $adminData   = $this->getFromSession(self::SESS_ADMIN, []);
        $dbCreds     = $this->getFromSession(self::SESS_DB_CREDS, []);

        if (empty($adminData)) {
            return ['success' => false, 'message' => 'Admin credentials not found in session. Please complete the admin step.'];
        }

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        try {
            $adapter = AuthAdapterFactory::create($this->config, $installDir, $dbCreds);
            $result  = $adapter->createAdmin($adminData);

            if ($result->success) {
                return ['success' => true, 'message' => $result->content ?? 'Admin user created successfully.'];
            }

            return ['success' => false, 'message' => $result->errorMessage];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Admin creation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Handle a manually uploaded zip file. Moves it into the install directory
     * so that substepDownload (on retry) or substepExtract can process it.
     */
    private function handleManualUpload(): void
    {
        $file = $_FILES['manual_zip'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            ];
            $msg = $messages[$file['error']] ?? 'Unknown upload error.';
            $this->jsonResponse(['success' => false, 'message' => $msg]);
            return;
        }

        // Verify it's a zip.
        $fh = fopen($file['tmp_name'], 'rb');
        if ($fh === false) {
            $this->jsonResponse(['success' => false, 'message' => 'Cannot read uploaded file.']);
            return;
        }
        $magic = fread($fh, 2);
        fclose($fh);

        if ($magic !== 'PK') {
            $this->jsonResponse(['success' => false, 'message' => 'Uploaded file is not a valid zip archive.']);
            return;
        }

        $destZip = $this->targetDir . '/_uploaded.zip';

        if (! move_uploaded_file($file['tmp_name'], $destZip)) {
            $this->jsonResponse(['success' => false, 'message' => 'Failed to move uploaded file.']);
            return;
        }

        // Mark download as complete so the install flow continues from extract.
        $completed = $this->getFromSession(self::SESS_SUBSTEPS, []);
        $completed['download'] = true;
        $this->saveToSession(self::SESS_SUBSTEPS, $completed);

        $this->jsonResponse(['success' => true, 'message' => 'Upload received.']);
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * Get a value from the installer session namespace.
     */
    private function getFromSession(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Save a value to the installer session namespace.
     */
    private function saveToSession(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Clear all installer-specific session data.
     */
    private function clearInstallSession(): void
    {
        $keys = [
            self::SESS_ENV,
            self::SESS_FS_METHOD,
            self::SESS_FS_CREDS,
            self::SESS_DB_DRIVER,
            self::SESS_DB_CREDS,
            self::SESS_CONFIGURATION,
            self::SESS_APP_SETTINGS,
            self::SESS_ADMIN,
            self::SESS_SUBSTEPS,
            'csrf_token',
            'pubvana_installer_db_attempts',
            'nginx_conf_instructions',
        ];

        foreach ($keys as $key) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Redirect to a specific step by outputting a Location header.
     */
    private function redirect(string $step): void
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/install.php';
        $url        = $scriptName . '?step=' . urlencode($step);
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Output a JSON response and terminate.
     */
    private function jsonResponse(array $data): void
    {
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Detect the base URL from $_SERVER variables.
     *
     * When the app installs above the wizard folder, the base URL is the
     * wizard's URL with that many directory levels removed, so the site
     * suggestion matches where the app's front controller will be served.
     */
    private function detectBaseUrl(): string
    {
        $https  = $this->detectHttps();
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST']   ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/install.php';

        // Strip the filename to get the directory.
        $dir = rtrim(dirname($script), '/\\');
        $dir = $dir === '.' ? '' : $dir;

        // Walk up to the install target (e.g. ../ → drop one segment).
        for ($i = 0; $i < $this->targetUpSteps; $i++) {
            $dir = rtrim(dirname($dir), '/\\');
            $dir = $dir === '.' ? '' : $dir;
        }

        return $scheme . '://' . $host . $dir . '/';
    }

    /**
     * Generate a cryptographically secure encryption key.
     */
    private function generateEncryptionKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Determine whether the current request is served over HTTPS.
     */
    private function detectHttps(): bool
    {
        $https = $_SERVER['HTTPS']                  ?? '';
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $port  = (int) ($_SERVER['SERVER_PORT']     ?? 0);

        return (
            ($https !== '' && strtolower($https) !== 'off')
            || strtolower($proto) === 'https'
            || $port === 443
        );
    }

    /**
     * Get (or lazy-detect) the filesystem driver, backed by session cache.
     */
    private function getFilesystem(): FilesystemInterface
    {
        if ($this->fs !== null) {
            return $this->fs;
        }

        $method = $this->getFromSession(self::SESS_FS_METHOD);
        $creds  = $this->getFromSession(self::SESS_FS_CREDS, []);

        if ($method === null || $method === 'direct') {
            // Attempt auto-detect; fall back to DirectDriver.
            $driver = FilesystemFactory::detect($this->targetDir);

            if ($driver === null) {
                // Use the direct driver anyway as a best-effort fallback.
                $driver = new \PubvanaInstaller\Filesystem\DirectDriver();
            }

            $this->fs = $driver;
            return $this->fs;
        }

        try {
            $host = $creds['host'] ?? '';
            $user = $creds['user'] ?? '';
            $pass = $creds['pass'] ?? '';
            $port = (int) ($creds['port'] ?? 0);

            $this->fs = match ($method) {
                'ftps' => FilesystemFactory::createFtps($host, $user, $pass, $port > 0 ? $port : 21),
                'ftp'  => FilesystemFactory::createFtp($host, $user, $pass, $port > 0 ? $port : 21),
                'ssh2' => FilesystemFactory::createSsh2($host, $user, $pass, $port > 0 ? $port : 22),
                default => FilesystemFactory::detect($this->targetDir) ?? new \PubvanaInstaller\Filesystem\DirectDriver(),
            };
        } catch (Throwable) {
            $this->fs = new \PubvanaInstaller\Filesystem\DirectDriver();
        }

        return $this->fs;
    }

    /**
     * Return the canonical substep list with labels and status.
     *
     * @return array<int, array{id: string, label: string, completed: bool}>
     */
    private function buildSubstepList(): array
    {
        $completed = $this->getFromSession(self::SESS_SUBSTEPS, []);

        $substeps = [
            ['key' => 'download',    'label' => 'Download Application'],
            ['key' => 'extract',     'label' => 'Extract Files'],
            ['key' => 'validate',    'label' => 'Validate Source'],
            ['key' => 'public_dir',  'label' => 'Configure Public Directory'],
            ['key' => 'write_env',   'label' => 'Write .env Configuration'],
            ['key' => 'permissions', 'label' => 'Set File Permissions'],
            ['key' => 'migrations',  'label' => 'Run Migrations & Seeds'],
            ['key' => 'admin',       'label' => 'Create Admin Account'],
        ];

        foreach ($substeps as &$substep) {
            $substep['completed'] = isset($completed[$substep['key']]);
        }

        return $substeps;
    }

    // -------------------------------------------------------------------------
    // Private routing helpers.
    // -------------------------------------------------------------------------

    /**
     * Resolve which step to render, defaulting to 'welcome'.
     */
    private function resolveStep(): string
    {
        $step = $_POST['step'] ?? $_GET['step'] ?? 'welcome';

        // Whitelist of valid step slugs.
        $valid = [
            'welcome', 'system-check', 'filesystem', 'database',
            'configuration', 'app-settings', 'admin', 'install', 'complete',
        ];

        return in_array($step, $valid, true) ? $step : 'welcome';
    }

    /**
     * Determine whether the current request is an AJAX substep call.
     */
    private function isAjaxSubstepRequest(): bool
    {
        return isset($_POST['substep']) || isset($_GET['substep'])
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    // -------------------------------------------------------------------------
    // Private environment helpers.
    // -------------------------------------------------------------------------

    /**
     * Get the ServerEnvironment from session cache or detect if needed.
     */
    private function getServerEnvironment(): ServerEnvironment
    {
        if ($this->env !== null) {
            return $this->env;
        }

        $requiredExtensions = array_map(
            'strval',
            (array) ($this->config['requirements']['extensions'] ?? []),
        );

        $detector = new Detector($this->targetDir, $requiredExtensions);
        $this->env = $detector->detect();
        return $this->env;
    }

    // -------------------------------------------------------------------------
    // Private database helpers.
    // -------------------------------------------------------------------------

    /**
     * Map a PHP extension name to the display name used by the DB step.
     */
    private function mapDbExtToDriver(string $ext): ?string
    {
        return match ($ext) {
            'mysqli'  => 'MySQLi',
            default   => null,
        };
    }

    // -------------------------------------------------------------------------
    // Private filesystem helpers.
    // -------------------------------------------------------------------------

    /**
     * Find a .zip file in a directory. Returns the path or null.
     */
    private function findZipFile(string $dir): ?string
    {
        if (! is_dir($dir)) {
            return null;
        }

        $files = @scandir($dir);

        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            if (str_ends_with(strtolower($file), '.zip')) {
                return $dir . '/' . $file;
            }
        }

        return null;
    }

    /**
     * Extract a zip archive using the best available method.
     */
    private function extractZip(string $zipPath, string $destDir): array
    {
        // Method 1: ZipArchive.
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $res = $zip->open($zipPath);

            if ($res === true) {
                $zip->extractTo($destDir);
                $zip->close();
                return ['success' => true];
            }
        }

        // Method 2: exec unzip.
        $env = $this->getServerEnvironment();

        if ($env->execAvailable === 'passed') {
            $zipArg  = escapeshellarg($zipPath);
            $destArg = escapeshellarg($destDir);
            $output  = [];
            $code    = 0;
            @exec("unzip -o {$zipArg} -d {$destArg} 2>&1", $output, $code);

            if ($code === 0) {
                return ['success' => true];
            }
        }

        // Method 3: PharData.
        if (extension_loaded('phar')) {
            try {
                $phar = new \PharData($zipPath);
                $phar->extractTo($destDir, null, true);
                return ['success' => true];
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'PharData extraction failed: ' . $e->getMessage()];
            }
        }

        return [
            'success' => false,
            'message' => 'Cannot extract zip: no extraction method available (ZipArchive, unzip, or PharData required).',
        ];
    }

    /**
     * Normalize a directory containing a single nested subdirectory.
     *
     * GitHub-style archives create: target/reponame-v1.0.0/ (one level of nesting).
     * Flattens that when exactly one entry exists, OR when exactly one entry is an
     * app-root looking directory (contains runway/app/public markers). Top-level
     * hidden entries and the wizard's own install/ wrapper are treated as
     * non-candidates, so a nested release zip is flattened even when the wizard's
     * wrapper lives alongside it.
     *
     * @return bool True if normalization was performed.
     */
    private function normalizeDirectory(string $dir): bool
    {
        $entries = @scandir($dir);

        if ($entries === false) {
            return false;
        }

        $entries = array_values(array_filter($entries, static fn($e) => $e !== '.' && $e !== '..'));

        // Find directories that look like an application root.
        $candidates = [];

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $subPath = $dir . '/' . $entry;

            if (! is_dir($subPath)) {
                continue;
            }

            if ($this->looksLikePubvanaRoot($subPath)) {
                $candidates[] = $subPath;
            }
        }

        // Exactly one candidate, or exactly one entry overall → flatten it.
        if (count($candidates) !== 1 && count($entries) !== 1) {
            return false;
        }

        $subPath = $candidates === [] ? null : $candidates[0];

        if ($subPath === null && count($entries) === 1 && is_dir($dir . '/' . $entries[0])) {
            $subPath = $dir . '/' . $entries[0];
        }

        if ($subPath === null || ! is_dir($subPath)) {
            return false;
        }

        // Move all contents of the subdirectory up one level.
        $subEntries = @scandir($subPath);

        if ($subEntries === false) {
            return false;
        }

        foreach ($subEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $src = $subPath . '/' . $entry;
            $dst = $dir . '/' . $entry;

            if (! @rename($src, $dst)) {
                return false;
            }
        }

        @rmdir($subPath);

        return true;
    }

    /**
     * Whether a directory looks like the root of a Pubvana CMS v3 application.
     */
    private function looksLikePubvanaRoot(string $dir): bool
    {
        return is_dir($dir . '/runway')
            && is_dir($dir . '/app')
            && is_dir($dir . '/public');
    }

    /**
     * Recursively copy all contents of $src into $dst.
     */
    private function mergeDir(string $src, string $dst): void
    {
        if (! is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }

        $entries = @scandir($src);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $srcPath = $src . '/' . $entry;
            $dstPath = $dst . '/' . $entry;

            if (is_dir($srcPath)) {
                $this->mergeDir($srcPath, $dstPath);
            } else {
                @copy($srcPath, $dstPath);
            }
        }
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = @scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Clean up the extracted temp directory.
     */
    private function cleanupExtractedDir(): void
    {
        // Clean up the extracted installer directory (.pubvana-installer).
        if ($this->extractedDir !== '' && is_dir($this->extractedDir) && basename($this->extractedDir) === '.pubvana-installer') {
            $this->removeDir($this->extractedDir);
        }
    }

    // -------------------------------------------------------------------------
    // Private server config generation helpers.
    // -------------------------------------------------------------------------

    /**
     * Generate an Nginx config block for routing requests to public/index.php.
     */
    private function buildNginxConfig(string $appDir): string
    {
        $appDirEscaped = addslashes($appDir);

        return <<<NGINX
# Add this to your Nginx server block configuration:
server {
    listen 80;
    server_name yourdomain.com;
    root {$appDirEscaped}/public;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive directories
    location ~ ^/(app|vendor|writable|plugins|themes)/ {
        deny all;
    }
}
NGINX;
    }

    /**
     * Generate a web.config for IIS URL Rewrite.
     */
    private function buildWebConfig(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <rule name="Pubvana Public Dir" stopProcessing="true">
                    <match url="^(.*)$" />
                    <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="public/index.php/{R:1}" />
                </rule>
            </rules>
        </rewrite>
        <security>
            <requestFiltering>
                <hiddenSegments>
                    <add segment="app" />
                    <add segment="vendor" />
                    <add segment="writable" />
                    <add segment="plugins" />
                    <add segment="themes" />
                </hiddenSegments>
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>
XML;
    }

    // -------------------------------------------------------------------------
    // Private env_vars grouping helper.
    // -------------------------------------------------------------------------

    /**
     * Group env_vars definitions by their 'group' field for template rendering.
     *
     * @return array<string, array> Associative array of group => [varDefs]
     */
    private function groupEnvVars(array $envVars): array
    {
        $groups = [];

        foreach ($envVars as $varDef) {
            $group = $varDef['group'] ?? 'General';
            $groups[$group][] = $varDef;
        }

        return $groups;
    }

    // -------------------------------------------------------------------------
    // Private fatal error display.
    // -------------------------------------------------------------------------

    /**
     * Output a developer-facing fatal error page and exit.
     *
     * Used only for conditions that indicate a misconfigured installer
     * (not for end-user errors).
     */
    private function die(string $title, string $details): never
    {
        $safeTitle   = htmlspecialchars($title);
        $appName     = htmlspecialchars($this->config['branding']['name'] ?? 'Pubvana Installer');

        echo "<!DOCTYPE html><html lang='en'><head>"
            . "<meta charset='UTF-8'>"
            . "<title>Installer Error: {$safeTitle}</title>"
            . "<style>body{font-family:system-ui,sans-serif;max-width:700px;margin:4rem auto;padding:0 1.5rem}"
            . "h1{color:#c0392b}code{background:#f8f8f8;padding:.1em .3em;border-radius:3px}"
            . "pre{background:#f8f8f8;padding:1em;border-radius:5px;overflow:auto}"
            . ".badge{background:#e74c3c;color:#fff;padding:.2em .7em;border-radius:1em;font-size:.8em}"
            . "</style></head><body>"
            . "<h1><span class='badge'>Error</span> {$safeTitle}</h1>"
            . "<p><strong>{$appName} Installer</strong> could not start due to a configuration problem.</p>"
            . "<div>{$details}</div>"
            . "</body></html>";

        exit(1);
    }
}
