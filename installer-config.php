<?php
/**
 * Pubvana Installer Configuration
 *
 * Customize this file for your Pubvana CMS v3 application.
 * See the README for full documentation of all options.
 */
$version = '3.0.0-beta.2';

return [
    // --- Branding ---
    'branding' => [
        'name'          => 'Pubvana CMS',
        'version'       => $version,
        'logo'          => 'https://cdn.pubvana.net/pubvana_logo.png',
        'support_url'   => 'https://pubvanacms.com/contact',
        'support_email' => 'cs@pubvana.net',
        'welcome_text'  => 'Welcome to the Pubvana Installer',  // empty = auto-generated from app name
        'colors'        => [
            'primary'   => '#0f172a',
            'secondary' => '#1e293b',
            'accent'    => '#f59e0b',
            'neutral'   => '#1e293b',
            'base-100'  => '#FFFFFF',
            'base-200'  => '#fafaf9',
            'base-300'  => '#e2e8f0',
        ],
    ],

    // --- Install Target ---
    // Where the app is installed. Default '../' places the app one level above
    // the wizard folder (the normal setup: extract install.zip into an
    // 'install/' subfolder of the document root, and the app installs into the
    // document root itself). Leave '' to install into the wizard's own folder.
    // Absolute paths are allowed.
    'install' => [
        'target_dir' => '..',
    ],

    // --- Download Source ---
    // source.zip is REQUIRED. The release package must include vendor/ so it
    // runs on servers without composer.
    'source' => [
        'zip' => "https://github.com/Pubvana-CMS/pubvana/releases/download/{$version}/release.zip",
    ],

    // --- Server Requirements ---
    'requirements' => [
        'php'        => '8.2',
        'extensions' => [
            'curl',
            'mbstring',
            'intl',
            'json',
            'fileinfo',
            'openssl',
            'pdo',
            'pdo_mysql',
            'mysqli',
            'zip',
        ],
        'databases'  => ['mysqli'],  // v3 supports MySQL/MariaDB via PDO
    ],

    // --- Writable Directories ---
    'writable_dirs'            => [
        'writable/backups',
        'writable/cache',
        'writable/logs',
        'writable/store',
        'writable/tmp',
        'writable/trust',
        'writable/updates',
        'public/uploads',
        'public/assets',
        'public/images',
    ],
    'writable_dir_permissions' => 0755,

    // --- Custom ENV Variables ---
    // Each entry creates a form field in the wizard (the "App Settings" step).
    // Types: text, password, email, url, select, boolean
    'env_vars' => [
        [
            'key'      => 'SITE_NAME',
            'label'    => 'Site Name',
            'type'     => 'text',
            'required' => true,
            'group'    => 'Site',
            'help'     => 'The name of your website, shown in the title and header.',
            'default'  => 'Pubvana v3',
        ],
        [
            'key'      => 'ADMIN_EMAIL',
            'label'    => 'Admin Email',
            'type'     => 'email',
            'required' => true,
            'group'    => 'Site',
            'help'     => 'The site\'s contact/admin email address.',
            'default'  => '',
        ],
    ],

    // --- Post-Install Actions ---
    // v3 runs migrations and seeds on the app's first boot (self-install), so
    // the installer just triggers the site once and verifies the result.
    'post_install' => [
        'migrate' => true,
        'seed'    => false,
    ],

    // --- Authentication ---
    // system: shield, custom, none
    // 'shield' creates the admin via flight-shield's own UserManagement service
    // and assigns the configured group (seeded on first boot).
    'auth' => [
        'system'  => 'shield',
        'collect' => ['username', 'email', 'password'],
        'group'   => 'superadmin',
        // For 'custom' system only:
        // 'table'         => 'users',
        // 'fields'        => ['email' => 'email', 'password' => 'password_hash'],
        // 'hash_method'   => 'PASSWORD_DEFAULT',
        // 'extra_inserts' => ['role' => 'admin', 'active' => 1],
    ],

    // --- Public Directory Handling ---
    // The v3 release package ships its own root .htaccess that routes to
    // public/, plus writable/ and cron, so the installer leaves it alone.
    // Options: auto, htaccess, none
    'public_dir_handling' => 'none',

    // --- Post-Install Redirect ---
    'post_install_url' => '/',
];