<?php
/** Run only against a test Magento: php tests/integration/magento.php /path/to/magento */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Magmi\Core\Config\Profile;
use Magmi\Core\Config\ProfileManager;
use Magmi\Core\Database\DbConfig;
use Magmi\Core\Database\DbHelper;
use Magmi\Core\Datasource\ArrayDatasource;
use Magmi\Core\Import\ImportEngine;
use Magmi\Core\Import\ProductImportDataPump;
use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\PluginManager;

$path = $argv[1] ?? '';
if (!is_file($path . '/app/etc/env.php')) {
    throw new RuntimeException('Supply the test Magento root as the first argument.');
}
$db = new DbHelper(DbConfig::fromEnvPhp($path));
$db->connect();
$table = $db->tableName('catalog_product_entity');
$sku = 'MAGMI-VERIFY-' . bin2hex(random_bytes(8));
$base = sys_get_temp_dir() . '/' . strtolower($sku);
mkdir($base, 0700);
define('MAGMI_GUI_BASE_DIR', $base);
$profiles = new ProfileManager($base . '/data/config');
$profiles->save(new Profile('Verification'));
$profiles->saveGlobalConfig(['magento_path' => $path, 'enable_msi' => true]);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
    echo "PASS: {$message}\n";
};
$find = static fn(string $code) => $db->selectOne("SELECT entity_id FROM {$table} WHERE sku = ?", [$code], 'entity_id');
try {
    $pump = new ProductImportDataPump();
    $pump->beginImportSession('Verification', 'create');
    $check($pump->ingest(['sku' => $sku, 'name' => 'Verification product', 'price' => '12.50', 'status' => 2, 'visibility' => 1, 'qty' => 3]), 'DataPump creates immediately');
    $pid = $find($sku);
    $check($pid !== null, 'Product exists before endImportSession');
    $check(!$pump->ingest(['sku' => $sku, 'name' => 'Must not overwrite']), 'Create mode skips an existing SKU');
    $report = $pump->endImportSession();
    $check($report['processed'] === 1 && $report['skipped'] === 1 && $report['status'], 'Create session report is correct');
    $pump->beginImportSession('Verification', 'update');
    $check($pump->ingest(['sku' => $sku, 'name' => 'Updated product', 'price' => '19.75', 'qty' => 7]), 'DataPump updates immediately');
    $check(!$pump->ingest(['sku' => $sku . '-missing', 'name' => 'Absent']), 'Update mode skips missing SKU');
    $pump->endImportSession();
    $varchar = $db->tableName('catalog_product_entity_varchar');
    $decimal = $db->tableName('catalog_product_entity_decimal');
    $attrs = $db->tableName('eav_attribute');
    $check($db->selectOne("SELECT v.value FROM {$varchar} v JOIN {$attrs} a ON a.attribute_id=v.attribute_id WHERE v.entity_id=? AND a.attribute_code='name' AND v.store_id=0", [$pid], 'value') === 'Updated product', 'Updated EAV name is persisted');
    $check((float)$db->selectOne("SELECT v.value FROM {$decimal} v JOIN {$attrs} a ON a.attribute_id=v.attribute_id WHERE v.entity_id=? AND a.attribute_code='price' AND v.store_id=0", [$pid], 'value') === 19.75, 'Updated EAV price is persisted');
    $stock = $db->tableName('cataloginventory_stock_item');
    $check((float)$db->selectOne("SELECT qty FROM {$stock} WHERE product_id=?", [$pid], 'qty') === 7.0, 'Stock quantity is persisted');
    $sources = $db->tableName('inventory_source_item');
    $check((float)$db->selectOne("SELECT quantity FROM {$sources} WHERE sku=? AND source_code='default'", [$sku], 'quantity') === 7.0, 'MSI source quantity is persisted');

    $pump->beginImportSession('Verification', 'update');
    $check($pump->ingest(['sku' => $sku, 'is_in_stock' => 0]), 'Status-only stock update succeeds');
    $pump->endImportSession();
    $check((float)$db->selectOne("SELECT quantity FROM {$sources} WHERE sku=? AND source_code='default'", [$sku], 'quantity') === 7.0, 'Status-only update preserves MSI quantity');
    $pump->beginImportSession('Verification', 'update');
    $galleryPath = '/magmi-verification/' . $sku . '.jpg';
    $check($pump->ingest(['sku' => $sku, 'media_gallery' => $galleryPath]), 'Media gallery import succeeds');
    $pump->endImportSession();
    $gallery = $db->tableName('catalog_product_entity_media_gallery');
    $links = $db->tableName('catalog_product_entity_media_gallery_value_to_entity');
    $check((int)$db->selectOne("SELECT COUNT(*) AS n FROM {$gallery} g JOIN {$links} l ON l.value_id=g.value_id WHERE l.entity_id=? AND g.value=?", [$pid, $galleryPath], 'n') === 1, 'Magento 2 media gallery entity relation is persisted');

    foreach (['csv', 'tsv', 'xlsx'] as $format) {
        $file = $base . '/input.' . $format;
        if ($format === 'xlsx') {
            $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet->getActiveSheet()->fromArray([['sku', 'name'], [$sku, 'CLI ' . $format]]);
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($file);
            $sheet->disconnectWorksheets();
        } else {
            $delimiter = $format === 'tsv' ? "\t" : ',';
            file_put_contents($file, 'sku' . $delimiter . 'name' . "\n" . $sku . $delimiter . 'CLI ' . $format . "\n");
        }
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/magmi', 'import', '--profile=Verification', '--mode=update', '--config=' . $base . '/data/config', '--file=' . $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        $cliReport = json_decode($output, true);
        $check($exitCode === 0 && ($cliReport['processed'] ?? 0) === 1, 'CLI ' . strtoupper($format) . ' import succeeds: ' . trim($error));
        $check($db->selectOne("SELECT v.value FROM {$varchar} v JOIN {$attrs} a ON a.attribute_id=v.attribute_id WHERE v.entity_id=? AND a.attribute_code='name' AND v.store_id=0", [$pid], 'value') === 'CLI ' . $format, 'CLI ' . strtoupper($format) . ' writes the expected value');
    }
    $pump->beginImportSession('Verification', 'update');
    $pump->ingest(['sku' => $sku, 'name' => 'Updated product']);
    $pump->endImportSession();

    $manager = new PluginManager();
    $failure = new class('fail', 'Failure test', '1', 'itemprocessors') extends AbstractPlugin {
        public function processItemAfterImport(array &$item, array $context): bool {
            if (str_ends_with($item['sku'], '-fail')) { throw new RuntimeException('Intentional failure after database writes'); }
            return true;
        }
    };
    $manager->register($failure);
    $manager->enable('fail');
    $engine = new ImportEngine($manager, $db);
    $engine->setContext(['mode' => 'xcreate']);
    $source = new ArrayDatasource();
    $source->setRecords([
        ['sku' => $sku . '-fail', 'name' => 'Must roll back'],
        ['sku' => $sku, 'name' => 'First consecutive update'],
        ['sku' => $sku, 'name' => $sku . '-lookup'],
    ]);
    $engine->setDatasource($source);
    $db->beginTransaction();
    $report = $engine->import();
    $check($find($sku . '-fail') === null, 'Failed row rolls back product and attributes');
    $check($report['processed'] === 2 && count($report['errors']) === 1, 'Processing continues after a failed row');
    $check($db->getPdo()->inTransaction(), 'Caller transaction remains open');
    require_once dirname(__DIR__, 2) . '/plugins/SkuFinderPlugin.php';
    $finder = new \Magmi\Core\BuiltinPlugin\SkuFinderPlugin();
    $finder->setConfig(['matchfield' => 'name']);
    $lookupManager = new PluginManager();
    $lookupManager->register($finder);
    $lookupManager->enable('skufinder');
    $lookupEngine = new ImportEngine($lookupManager, $db);
    $lookupEngine->setContext(['mode' => 'update']);
    $lookupSource = new ArrayDatasource();
    $lookupSource->setRecords([['name' => $sku . '-lookup', 'price' => '29.50']]);
    $lookupEngine->setDatasource($lookupSource);
    $lookupReport = $lookupEngine->import();
    $check($lookupReport['status'] && $lookupReport['processed'] === 1, 'SKU Finder resolves a product from an EAV attribute');
    $check((int)$lookupReport['last_processed_id'] === (int)$pid, 'SKU Finder updates the matching product');
    $db->rollback();
    $check($db->selectOne("SELECT v.value FROM {$varchar} v JOIN {$attrs} a ON a.attribute_id=v.attribute_id WHERE v.entity_id=? AND a.attribute_code='name' AND v.store_id=0", [$pid], 'value') === 'Updated product', 'Caller rollback restores previous data');
    echo "{$checks} integration checks passed.\n";
} finally {
    if ($db->getPdo()->inTransaction()) { $db->rollback(); }
    $db->delete("DELETE FROM {$table} WHERE sku IN (?, ?, ?)", [$sku, $sku . '-fail', $sku . '-missing']);
    $db->delete('DELETE FROM ' . $db->tableName('inventory_source_item') . ' WHERE sku IN (?, ?, ?)', [$sku, $sku . '-fail', $sku . '-missing']);
    $db->delete('DELETE FROM ' . $db->tableName('catalog_product_entity_media_gallery') . ' WHERE value = ?', ['/magmi-verification/' . $sku . '.jpg']);
    foreach (glob($base . '/input.*') as $file) { unlink($file); }
    foreach (glob($base . '/data/config/*.json') as $file) { unlink($file); }
    rmdir($base . '/data/config');
    rmdir($base . '/data');
    rmdir($base);
    echo "Temporary products and profiles removed.\n";
}
