# Pubvana Installer

Install Pubvana on PHP hosting through a web wizard. No command line required.

## Installer name

`--name` sets the installer name. This guide uses `<name>` for it. `--name=3.0.0` produces:

- `3.0.0.zip`
- a folder named `3.0.0`
- the script `3.0.0.php`

The build tool defaults to `install`.

## Requirements

- PHP 8.2 or higher
- MySQL or MariaDB
- PHP extensions: curl, mbstring, intl, json, fileinfo, openssl, pdo, pdo_mysql, mysqli, zip
- Apache, LiteSpeed, Nginx, or IIS

## Installation

### 1. Upload

1. Upload `<name>.zip` to your web root. On many hosts this is `public_html`.
2. Extract the zip there.

You should have:

- `<name>/<name>.php`
- `<name>/installer-config.php`

With the default configuration, Pubvana installs into the folder above `<name>`, which is your web root.

If your host cannot extract zips, unzip the package on your computer. Create a folder named `<name>` in your web root and upload the two files into it.

### 2. Run the wizard

Open `https://yourdomain.com/<name>/<name>.php`.

The wizard runs these steps:

**System Check**: checks your server against the requirements. Green means pass. Red means fix it before you continue.

**Filesystem**: tests direct write access. Most shared hosts allow this. If it fails, the wizard asks for FTP or SSH credentials.

**Database**: collects your MySQL or MariaDB credentials and tests the connection.

**Configuration**: collects the base URL and environment. The installer generates an encryption key.

**App Settings**: collects the site name and admin email.

**Admin Account**: creates the first admin user from an email and password. The account gets the `superadmin` group.

**Install**: downloads Pubvana, extracts it, validates the source, writes `.env`, sets permissions, runs migrations, applies site settings, and creates the admin account. The wizard shows progress for each substep.

**Done**: removes the installer files and displays a link to your site.

### 3. Log in

Open the link on the completion page, or go to `https://yourdomain.com/auth/login`. Sign in with the admin credentials you created.

## How the installer downloads Pubvana

The installer tries these methods in order and uses the first one the server supports:

1. **cURL** when the curl extension is available
2. **wget** when the server can run shell commands
3. **PHP stream download** when `allow_url_fopen` is enabled
4. **Manual upload** when all three fail

For manual upload, the wizard shows an upload field and a link to the release zip.

## Web Server Notes

The Pubvana release includes `.htaccess` files for Apache and LiteSpeed. The installer does not change them. On Nginx or IIS, route requests through the `public/` directory yourself.

## After Installation

On success, the installer deletes `<name>.php`, `installer-config.php`, and `<name>.zip`. It also deletes `.installer-progress.json` and the `.pubvana-installer` temp folder. It removes the `<name>` folder when empty.

If a file deletion fails, the installer creates `install.lock`. The built `<name>.php` exits when that file exists, so the installer cannot run again.

If you need to reinstall, delete `install.lock` and upload the installer files again.

## Troubleshooting

**System check shows red items**: your host is missing a requirement. The usual cause is a missing PHP extension. Ask your host to enable the extensions listed above.

**Database connection fails**: check the hostname, username, password, and database name. On shared hosting the database host is usually `localhost`. Some providers use a different hostname. The correct value is in your hosting control panel.

**Permission errors during install**: the installer needs write access to your web directory. If direct PHP access fails, the wizard asks for FTP or SSH credentials.

**Blank page or 500 error**: check your PHP error log. The usual cause is an unsupported PHP version.

**The installer says it is already installed**: delete `install.lock`, then upload the installer files again.

## Support

Visit [pubvana.net/contact-us](https://pubvana.net/contact-us) for help.

## License

Creative Commons Attribution 4.0 International License. See [creativecommons.org/licenses/by/4.0/](https://creativecommons.org/licenses/by/4.0/).

Original installer script by EnlivenApp.
