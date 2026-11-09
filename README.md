# Magmi Core

Magento product import engine, DataPump API, CSV/TSV reader, CLI and bundled plugins. No GUI or Magento framework dependency. Requires PHP 8.2+, Composer 2, PDO MySQL and mbstring.

## Install

```sh
# In an existing Composer project; for a new one run composer init first.
# Required until this package is available on Packagist:
composer config repositories.magmi-core vcs https://github.com/grinet/magmi2-core
composer require grinet/magmi2-core:^0.1@alpha
vendor/bin/magmi help
# Optional Excel support:
composer require phpoffice/phpspreadsheet:^5.0
```

The application owns its writable configuration and custom plugins; the package never needs a writable vendor directory. GUI and Magento adapters supply these paths for you.

Save this as `import.php` in your Composer project, adjust the paths and SKU, then run `php import.php`:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Magmi\Core\Config\{Profile, ProfileManager, RuntimeConfig};
use Magmi\Core\Database\{DbConfig, DbHelper};
use Magmi\Core\Import\ProductImportDataPump;

$base = '/absolute/path/to/import-app';
$magento = '/absolute/path/to/magento';
$profiles = new ProfileManager($base . '/data/config');
if (!$profiles->load('Default')) {
    $profiles->save(new Profile('Default'));
}
$db = new DbHelper(DbConfig::fromEnvPhp($magento));
$db->connect();
$pump = new ProductImportDataPump(new RuntimeConfig($base, $db, [
    'magento_path' => $magento,
    'magento_media_dir' => $magento . '/pub/media',
]));
$pump->beginImportSession('Default', 'update');
try {
    $ok = $pump->ingest(['sku' => 'EXISTING-SKU', 'name' => 'Updated name']);
} finally {
    $report = $pump->endImportSession();
}
echo json_encode($report, JSON_PRETTY_PRINT), PHP_EOL;
```

`ingest()` executes immediately and returns success; `endImportSession()` returns the report. Modes: `create`, `update`, `xcreate`. Bundled plugins are discovered automatically. Custom plugins in `<base>/plugins` override matching identifiers. Runtime profiles live in `<base>/data/config`. CLI reads that directory relative to the current directory, or accepts `--config=/absolute/path`.

Optional legacy SFTP adapter requires `phpseclib/phpseclib:^2.0`; FTP requires `ext-ftp`. Neither is needed for local file imports.

## Development

```sh
composer install
composer test
# Writes and cleans up temporary products; test Magento only:
php tests/integration/magento.php /path/to/test-magento
```

## Release status

This is an **alpha release**. Import behavior has been exercised against Magento Open Source 2.4.9 and PHP 8.4; other Magento versions allowed by Composer constraints are not yet certified. Sixteen legacy plugin entries are configuration-only and cannot be enabled for import. Indexers/cache are not refreshed automatically after direct database writes. Use a test store and validate your import before production use.

License: [OSL-3.0](LICENSE).

## PHP compatibility

PHP 8.5 syntax and PHPCompatibility static checks were run on this release. Existing minimum PHP requirements are retained. Use a Magento release that supports your PHP runtime; syntax/static checks alone do not verify installation, DI compilation or storefront/admin behavior.

The release remains alpha. Magento/Mage-OS database integration requires a test store; only standalone tests and source checks are included here.
