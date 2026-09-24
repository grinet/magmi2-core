<?php

declare(strict_types=1);

namespace Magmi\Core\Import;

use Magmi\Core\Database\AttributeHelper;
use Magmi\Core\Database\DbHelper;
use Magmi\Core\Datasource\DatasourceInterface;
use Magmi\Core\Logger\LoggerInterface;
use Magmi\Core\Plugin\PluginManager;

/**
 * Magmi-compatible import engine with full plugin hook support.
 * Hook order matches the original Magmi flow for backward compatibility.
 */
class ImportEngine
{
    private const DIRECT_SELECT_MAPS = [
        'status' => [
            '1' => 1,
            'enabled' => 1,
            'enable' => 1,
            'active' => 1,
            'on' => 1,
            'true' => 1,
            'yes' => 1,
            '0' => 2,
            '2' => 2,
            'disabled' => 2,
            'disable' => 2,
            'inactive' => 2,
            'off' => 2,
            'false' => 2,
            'no' => 2,
        ],
        'visibility' => [
            '1' => 1,
            'not visible individually' => 1,
            'notvisibleindividually' => 1,
            'not visible' => 1,
            'hidden' => 1,
            '2' => 2,
            'catalog' => 2,
            'in catalog' => 2,
            'catalog only' => 2,
            '3' => 3,
            'search' => 3,
            'in search' => 3,
            'search only' => 3,
            '4' => 4,
            'catalog search' => 4,
            'catalog and search' => 4,
            'catalogsearch' => 4,
        ],
    ];

    private PluginManager $pluginManager;
    private ?DbHelper $dbHelper = null;
    private ?AttributeHelper $attrHelper = null;
    private ?LoggerInterface $logger = null;
    private array $context = [];
    private array $report = [
        'total' => 0,
        'processed' => 0,
        'skipped' => 0,
        'errors' => [],
        'status' => true,
        'processed_ids' => [],
        'debug' => [],
        'last_processed_id' => null,
        'debug_log_file' => null,
    ];
    private ?DatasourceInterface $datasource = null;
    private ?string $lastSku = null;
    private array $lastItemIds = [];

    public function __construct(PluginManager $pluginManager, ?DbHelper $dbHelper = null)
    {
        $this->pluginManager = $pluginManager;
        $this->dbHelper = $dbHelper;
    }

    public function setDbHelper(DbHelper $dbHelper): void
    {
        $this->dbHelper = $dbHelper;
        $this->attrHelper = null;
        $this->lastSku = null;
        $this->lastItemIds = [];
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setContext(array $context): void
    {
        $defaults = [
            'profile' => 'Default',
            'import_file' => null,
        ];
        $this->context = array_merge($defaults, $context);
    }

    private function log(string $message, string $type = 'info'): void
    {
        if ($this->logger) {
            $this->logger->log($message, $type);
        }
    }

    private function updateMediaGallery(int $pid, array $item): void
    {
        if (empty($item['media_gallery']) || !$this->dbHelper) {
            return;
        }

        $value = (string) $item['media_gallery'];
        if ($value === '') {
            return;
        }

        $mediaPaths = array_filter(
            array_map('trim', preg_split('/[,;]/', $value) ?: []),
            fn($v) => $v !== ''
        );
        if (empty($mediaPaths)) {
            return;
        }

        $galleryTable = $this->dbHelper->tableName('catalog_product_entity_media_gallery');
        $valueTable = $this->dbHelper->tableName('catalog_product_entity_media_gallery_value');
        $linkTable = $this->dbHelper->tableName('catalog_product_entity_media_gallery_value_to_entity');

        // Load existing entries to detect duplicates
        $existing = $this->dbHelper->selectAll(
            "SELECT g.value_id, g.value FROM {$galleryTable} g " .
            "JOIN {$valueTable} v ON g.value_id = v.value_id WHERE v.entity_id = ?",
            [$pid]
        );
        $existingMap = [];
        foreach ($existing as $row) {
            $existingMap[$row['value']] = (int) $row['value_id'];
        }

        // Remove stale entries that are no longer in the import payload
        foreach ($existingMap as $value => $valueId) {
            if (!in_array($value, $mediaPaths, true)) {
                $this->dbHelper->delete(
                    "DELETE FROM {$valueTable} WHERE value_id = ? AND entity_id = ?",
                    [$valueId, $pid]
                );
                $this->dbHelper->delete("DELETE FROM {$linkTable} WHERE value_id = ? AND entity_id = ?", [$valueId, $pid]);
                unset($existingMap[$value]);
            }
        }

        // Reuse gallery rows that already exist for the requested paths
        $placeholders = implode(',', array_fill(0, count($mediaPaths), '?'));
        $attrId = $this->attrHelper->getAttributeId('media_gallery');
        $existingGlobal = $this->dbHelper->selectAll(
            "SELECT value_id, value FROM {$galleryTable} WHERE attribute_id = ? AND value IN ({$placeholders})",
            array_merge([$attrId], $mediaPaths)
        );
        foreach ($existingGlobal as $row) {
            $existingMap[$row['value']] = (int) $row['value_id'];
        }

        foreach ($mediaPaths as $path) {
            $valueId = $existingMap[$path] ?? null;
            if ($valueId === null) {
                $valueId = (int) $this->dbHelper->insert(
                    "INSERT INTO {$galleryTable} (attribute_id, value) VALUES (?, ?)",
                    [$attrId, $path]
                );
                $existingMap[$path] = $valueId;
            }

            $this->dbHelper->insert(
                "INSERT IGNORE INTO {$linkTable} (value_id, entity_id) VALUES (?, ?)",
                [$valueId, $pid]
            );
            $this->dbHelper->insert(
                "INSERT INTO {$valueTable} (value_id, store_id, entity_id, label, position, disabled) VALUES (?, ?, ?, ?, ?, ?) " .
                "ON DUPLICATE KEY UPDATE label = VALUES(label), position = VALUES(position), disabled = VALUES(disabled)",
                [$valueId, 0, $pid, null, 0, 0]
            );
        }
    }

    private function logProfileBanner(string $phase): void
    {
        $line = sprintf(
            "### [%s] -- Profile %s %s -- File : %s ---",
            date('Y-m-d H:i:s'),
            $this->context['profile'] ?? 'Default',
            $phase,
            $this->context['import_file'] ?? 'N/A'
        );
        $this->log($line);
        if (!empty($this->report['debug_log_file'])) {
            file_put_contents($this->report['debug_log_file'], $line . PHP_EOL, FILE_APPEND);
        }
    }

    private function logProductBanner(array $item): void
    {
        $sku = $item['sku'] ?? '[unknown]';
        $line = sprintf(
            "### [%s] -- Product %s -- Profile : %s -- File : %s ---",
            date('Y-m-d H:i:s'),
            $sku,
            $this->context['profile'] ?? 'Default',
            $this->context['import_file'] ?? 'N/A'
        );
        $this->log($line);
        if (!empty($this->report['debug_log_file'])) {
            file_put_contents($this->report['debug_log_file'], PHP_EOL . $line . PHP_EOL, FILE_APPEND);
        }
    }

    private function normalizeAttributeValue(string $code, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (isset(self::DIRECT_SELECT_MAPS[$code])) {
            $normalized = $this->mapDirectSelect($code, $value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if ($code === 'visibility') {
            return $this->normalizeSelectValue($value, [
                '1' => 'Not Visible Individually',
                'not visible individually' => 'Not Visible Individually',
                'not visible' => 'Not Visible Individually',
                'notvisibleindividually' => 'Not Visible Individually',
                'not visible individually' => 'Not Visible Individually',
                '2' => 'Catalog',
                'catalog' => 'Catalog',
                'in catalog' => 'Catalog',
                'catalog only' => 'Catalog',
                '3' => 'Search',
                'search' => 'Search',
                'in search' => 'Search',
                'search only' => 'Search',
                '4' => 'Catalog, Search',
                'catalog search' => 'Catalog, Search',
                'catalog and search' => 'Catalog, Search',
                'catalogsearch' => 'Catalog, Search',
            ]);
        }

        if ($code === 'status') {
            return $this->normalizeSelectValue($value, [
                '1' => 'Enabled',
                'enabled' => 'Enabled',
                'enable' => 'Enabled',
                'active' => 'Enabled',
                'on' => 'Enabled',
                'true' => 'Enabled',
                'yes' => 'Enabled',
                '0' => 'Disabled',
                '2' => 'Disabled',
                'disabled' => 'Disabled',
                'disable' => 'Disabled',
                'inactive' => 'Disabled',
                'off' => 'Disabled',
                'false' => 'Disabled',
                'no' => 'Disabled',
            ]);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    private function normalizeSelectValue(mixed $value, array $map): ?string
    {
        $stringValue = is_string($value) ? trim($value) : (is_numeric($value) ? (string) $value : null);
        if ($stringValue === null || $stringValue === '') {
            return null;
        }

        $canonicalMap = [];
        foreach ($map as $key => $label) {
            $canonicalKey = $this->canonicalOptionKey((string) $key);
            if ($canonicalKey === '') {
                continue;
            }
            $canonicalMap[$canonicalKey] = $label;
        }

        $key = $this->canonicalOptionKey($stringValue);
        if ($key !== '' && isset($canonicalMap[$key])) {
            return $canonicalMap[$key];
        }

        return $stringValue;
    }

    private function canonicalOptionKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['&', '+', '/'], ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return $value;
    }

    private function mapDirectSelect(string $code, mixed $value): ?int
    {
        if (!isset(self::DIRECT_SELECT_MAPS[$code])) {
            return null;
        }

        if (is_int($value)) {
            return self::DIRECT_SELECT_MAPS[$code][(string) $value] ?? $value;
        }

        if (is_numeric($value)) {
            $intValue = (int) $value;
            return self::DIRECT_SELECT_MAPS[$code][(string) $intValue] ?? $intValue;
        }

        if (is_string($value)) {
            $key = $this->canonicalOptionKey($value);
            if ($key === '') {
                return null;
            }
            return self::DIRECT_SELECT_MAPS[$code][$key] ?? null;
        }

        return null;
    }

    public function setDatasource(DatasourceInterface $datasource): void
    {
        $this->datasource = $datasource;
    }

    /**
     * Resolve SKU to product IDs (Magmi-compatible).
     *
     * @return array<string, mixed>|null
     */
    public function resolveProductIds(string $sku): ?array
    {
        if (!$this->dbHelper || !$this->dbHelper->isConnected()) {
            return null;
        }
        $table = $this->dbHelper->tableName('catalog_product_entity');
        $sql = "SELECT sku, entity_id as pid, attribute_set_id as asid, created_at, updated_at, type_id as type FROM {$table} WHERE sku = ?";
        $rows = $this->dbHelper->selectAll($sql, [$sku]);
        if (count($rows) > 0) {
            $row = $rows[0];
            $row['__new'] = false;
            return $row;
        }
        return null;
    }

    private bool $sessionActive = false;
    private bool $sessionReady = false;

    public function import(): array
    {
        if (!$this->datasource) {
            throw new \RuntimeException('No datasource set.');
        }
        try {
            if ($this->beginSession($this->datasource->getColumnNames())) {
                $limit = (int) ($this->context['import_limit'] ?? 0);
                foreach ($this->datasource->getRows() as $index => $row) {
                    if ($limit > 0 && $this->report['total'] >= $limit) {
                        break;
                    }
                    $this->processRecord($row, $index);
                }
            }
        } finally {
            if ($this->sessionActive) {
                $this->finishSession();
            }
        }
        return $this->report;
    }

    public function beginSession(array $columns): bool
    {
        if ($this->sessionActive) {
            throw new \LogicException('An import session is already active.');
        }
        $mode = $this->context['mode'] ?? 'xcreate';
        if (!in_array($mode, ['create', 'update', 'xcreate'], true)) {
            throw new \InvalidArgumentException('Invalid import mode: ' . $mode);
        }
        if ((!$this->dbHelper || !$this->dbHelper->isConnected()) && empty($this->context['dry_run'])) {
            throw new \RuntimeException('A connected database is required. Use dry_run explicitly for validation only.');
        }
        if (!empty($this->context['dry_run']) && $this->dbHelper !== null) {
            throw new \LogicException('Validation-only sessions must not receive a database helper.');
        }
        $this->sessionReady = false;
        $this->sessionActive = true;
        $this->report = [
            'total' => 0,
            'processed' => 0,
            'skipped' => 0,
            'errors' => [],
            'status' => true,
            'processed_ids' => [],
            'debug' => [],
            'last_processed_id' => null,
            'debug_log_file' => null,
        ];

        $this->lastSku = null;
        $this->lastItemIds = [];

        $debugMode = (bool)($this->context['debug'] ?? false);
        $debugLog = (bool)($this->context['debug_log'] ?? false);
        $debugLogPath = $this->context['debug_log_path'] ?? null;

        if ($this->dbHelper) {
            $this->context['db_helper'] = $this->dbHelper;
        }

        if ($this->dbHelper) {
            $this->dbHelper->enableDebugLogging(false);
        }
        if ($this->dbHelper && $debugMode) {
            $logFile = $debugLog ? $this->prepareDebugLogFile($debugLogPath) : null;
            $this->dbHelper->enableDebugLogging(true, $logFile ? function (array $entry) use ($logFile): void {
                $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($line !== false) {
                    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
                }
            } : null);
            $this->report['debug_log_file'] = $logFile;
        }

        $this->log('Import started');
        $this->logProfileBanner('Import Started');

        // 1. startImport
        $this->pluginManager->call('*', 'startImport', null, $this->context, false);

        // 2. Get column names and process them
        $this->pluginManager->callMutable('preprocessors,itemprocessors,producttypes,relatedproducts', 'processColumnList', $columns, $this->context, false);

        if (count($columns) < 1) {
            $this->report['errors'][] = [
                'row' => '-',
                'message' => 'Invalid input data: not enough columns found. Check the CSV separator matches the file (e.g. \\t for TSV).',
            ];
            $this->report['status'] = false;
            return false;
        }

        // 3. beforeImport
        if (!$this->pluginManager->call('*', 'beforeImport', null, $this->context)) {
            $this->report['errors'][] = [
                'row' => '-',
                'message' => 'Import aborted by plugin beforeImport hook.',
            ];
            $this->report['status'] = false;
            return false;
        }

        $this->sessionReady = true;
        return true;
    }

    public function processRecord(array $row, int|string $index = 0): bool
    {
        if (!$this->sessionActive) {
            throw new \LogicException('No import session started.');
        }
        if (!$this->sessionReady) {
            return false;
        }
        $this->report['total']++;
        $debugMode = (bool) ($this->context['debug'] ?? false);
        $item = $row;
        $transaction = false;
        $savepoint = false;
        $success = false;
        $meta = [];
        $this->logProductBanner($item);

        try {
            if ($this->dbHelper && empty($this->context['dry_run'])) {
                if (!$this->dbHelper->getPdo()->inTransaction()) {
                    $this->dbHelper->beginTransaction();
                    $transaction = true;
                }
                $this->dbHelper->execStmt('SAVEPOINT magmi_import_record');
                $savepoint = true;
            }
            // 4a. processItemBeforeId
            if (!$this->pluginManager->callMutable('preprocessors,itemprocessors,producttypes,relatedproducts', 'processItemBeforeId', $item, $this->context)) {
                $this->report['skipped']++;
                return false;
            }

            // SKU/product ID resolution (Magmi-compatible)
            $sku = $item['sku'] ?? null;
            if ($sku === null || trim((string) $sku) === '') {
                $this->report['skipped']++;
                return false;
            }

            $meta = $this->resolveItemIds((string) $sku, $item);
            $isNew = $meta['__new'] ?? false;
            $mode = $this->context['mode'] ?? 'xcreate';

            // Mode filtering: create only, update only, or create+update
            if ($mode === 'create' && !$isNew) {
                $this->report['skipped']++;
                return false;
            }
            if ($mode === 'update' && $isNew) {
                $this->report['skipped']++;
                return false;
            }

            // 4b. preprocessItemAfterId
            $metaContext = $this->buildPluginContext($meta);

            if (!$this->pluginManager->callMutable('preprocessors,itemprocessors,producttypes,relatedproducts', 'preprocessItemAfterId', $item, $metaContext)) {
                $this->report['skipped']++;
                return false;
            }

            // 4c. processItemAfterId (main processing)
            if (!$this->pluginManager->callMutable('preprocessors,itemprocessors,producttypes,relatedproducts', 'processItemAfterId', $item, $metaContext)) {
                $this->report['skipped']++;
                return false;
            }

            // DB write if connected
            if ($debugMode) {
                $finalSnapshot = [
                    'type' => 'item_final',
                    'sku' => $item['sku'] ?? null,
                    'payload' => $item,
                ];
                $this->report['debug'][] = $finalSnapshot;
                if (!empty($this->report['debug_log_file'])) {
                    $snapshotText = sprintf(
                        "item_final payload (SKU %s):\n%s\n",
                        $item['sku'] ?? '[unknown]',
                        print_r($item, true)
                    );
                    file_put_contents($this->report['debug_log_file'], $snapshotText, FILE_APPEND);
                }
            }

            if ($this->dbHelper && $this->dbHelper->isConnected() && empty($this->context['dry_run'])) {
                $pid = $this->writeProduct($item, $meta);
                if ($pid) {
                    $meta['pid'] = $pid;
                    $this->lastItemIds = array_merge($meta, ['__new' => false]);
                    $metaContext = $this->buildPluginContext($meta);
                }
            }

            // 4d. processItemAfterImport
            $this->pluginManager->callMutable('preprocessors,itemprocessors,producttypes,relatedproducts', 'processItemAfterImport', $item, $metaContext, false);

            if ($transaction) {
                $this->dbHelper->commit();
                $transaction = false;
                $savepoint = false;
            }
            $success = true;
            if (isset($pid) && $pid) {
                $this->report['processed_ids'][] = $pid;
                $this->report['last_processed_id'] = $pid;
            }
            $this->report['processed']++;
            return true;
        } catch (\Throwable $e) {
            if ($savepoint && $this->dbHelper->getPdo()->inTransaction()) {
                $this->dbHelper->execStmt('ROLLBACK TO SAVEPOINT magmi_import_record');
            }
            $this->lastSku = null;
            $this->lastItemIds = [];
            $this->attrHelper = null;
            // Exception hook
            $excMeta = array_merge($meta ?? [], ['exception' => $e]);
            try {
                $this->pluginManager->callMutable('preprocessors,itemprocessors,producttypes,relatedproducts', 'processItemException', $item, $this->buildPluginContext($excMeta), false);
            } catch (\Throwable $hookError) {
                $this->log('Exception hook failed: ' . $hookError->getMessage(), 'error');
            }
            $message = $e->getMessage();
            if (!empty($item['sku'])) {
                $message .= ' | SKU: ' . $item['sku'];
            }
            $this->report['errors'][] = [
                'row' => $index,
                'message' => $message,
            ];
            $this->report['status'] = false;
            $this->log('Error at row ' . $index . ': ' . $e->getMessage(), 'error');
        } finally {
            if ($savepoint && $this->dbHelper->getPdo()->inTransaction()) {
                if (!$success) {
                    $this->dbHelper->execStmt('ROLLBACK TO SAVEPOINT magmi_import_record');
                }
                $this->dbHelper->execStmt('RELEASE SAVEPOINT magmi_import_record');
            }
            if ($transaction && $this->dbHelper->getPdo()->inTransaction()) {
                $this->dbHelper->rollback();
            }
        }
        return false;
    }

    public function finishSession(): array
    {
        if (!$this->sessionActive) {
            throw new \LogicException('No import session started.');
        }
        $this->sessionActive = false;
        // 5. endImport
        $this->pluginManager->call('*', 'endImport', null, $this->context, false);

        // 6. afterImport
        $this->pluginManager->call('*', 'afterImport', null, $this->context, false);

        $this->log(sprintf(
            'Import finished. Total: %d, Processed: %d, Skipped: %d, Errors: %d',
            $this->report['total'],
            $this->report['processed'],
            $this->report['skipped'],
            count($this->report['errors'])
        ));
        $this->logProfileBanner('Import Finished');

        if ($this->dbHelper && !empty($this->context['debug'])) {
            $this->report['debug'] = array_merge($this->report['debug'], $this->dbHelper->getDebugStatements());
        }

        if (!empty($this->report['errors'])) {
            $this->report['status'] = false;
        }

        return $this->report;
    }

    private function prepareDebugLogFile(?string $preferredPath): ?string
    {
        $date = date('Y.m.d');
        $filename = "magmi_debug_{$date}.log";

        $candidates = [];
        if (!empty($this->context['magento_path'])) {
            $magentoVarLog = rtrim($this->context['magento_path'], '/\\') . '/var/log';
            $candidates[] = $magentoVarLog;
        }
        if (!empty($preferredPath)) {
            $candidates[] = $preferredPath;
        }
        $candidates[] = sys_get_temp_dir();

        foreach ($candidates as $dir) {
            if ($dir && is_dir($dir) && is_writable($dir)) {
                return rtrim($dir, '/\\') . '/' . $filename;
            }
        }

        return null;
    }

    private function buildPluginContext(?array $meta = null): array
    {
        $context = $this->context;
        if ($meta !== null) {
            $context['meta'] = $meta;
        }
        return $context;
    }

    /**
     * Resolve item IDs from SKU (Magmi-compatible getItemIds).
     *
     * @return array<string, mixed>
     */
    private function resolveItemIds(string $sku, array $item): array
    {
        if ($sku !== $this->lastSku) {
            $pids = $this->resolveProductIds($sku);
            if ($pids !== null) {
                $this->lastItemIds = $pids;
                $this->lastSku = $sku;
                return array_merge($pids, ['same' => false, 'sku' => $sku]);
            }
            $this->lastItemIds = [
                'pid' => null,
                'sku' => $sku,
                'asid' => $item['attribute_set'] ?? null,
                'type' => $item['type'] ?? 'simple',
                '__new' => true,
            ];
            $this->lastSku = $sku;
            return array_merge($this->lastItemIds, ['same' => false, 'sku' => $sku]);
        }
        return array_merge($this->lastItemIds, ['same' => true, 'sku' => $sku]);
    }

    /**
     * Full product write to Magento DB with EAV attribute handling.
     */
    private function writeProduct(array &$item, array $meta): ?int
    {
        $pid = $meta['pid'] ?? null;
        if ($meta['__new'] ?? false) {
            $pid = $this->createProduct($item, $meta);
        } else {
            $this->updateProduct($item, (int) $pid);
        }

        // Write EAV attributes and related data if we have a product ID and DB connection
        if ($pid && $this->dbHelper && $this->dbHelper->isConnected()) {
            $this->writeAttributes($item, (int) $pid);
            $this->updateStock((int) $pid, $item);
            $this->assignCategories((int) $pid, $item);
            $this->updateWebsites((int) $pid, $item);
        }

        return $pid ? (int) $pid : null;
    }

    private function createProduct(array $item, array $meta): int
    {
        $table = $this->dbHelper->tableName('catalog_product_entity');
        $type = $item['type'] ?? 'simple';
        $asid = $this->resolveAttributeSetId($meta['asid'] ?? null);
        $now = date('Y-m-d H:i:s');

        $columns = ['attribute_set_id', 'type_id', 'sku', 'created_at', 'updated_at'];
        $params = [$asid, $type, $item['sku'], $now, $now];

        if ($this->dbHelper->columnExists($table, 'entity_type_id')) {
            array_unshift($columns, 'entity_type_id');
            array_unshift($params, $this->getProductEntityType());
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            $placeholders
        );

        $pid = $this->dbHelper->insert($sql, $params);

        return (int) $pid;
    }

    private function updateProduct(array $item, int $pid): void
    {
        $table = $this->dbHelper->tableName('catalog_product_entity');
        $now = date('Y-m-d H:i:s');
        $type = $item['type'] ?? null;

        if ($type !== null) {
            $sql = "UPDATE {$table} SET type_id = ?, updated_at = ? WHERE entity_id = ?";
            $this->dbHelper->update($sql, [$type, $now, $pid]);
        } else {
            $sql = "UPDATE {$table} SET updated_at = ? WHERE entity_id = ?";
            $this->dbHelper->update($sql, [$now, $pid]);
        }
    }

    /**
     * Write EAV attribute values for a product.
     */
    private function writeAttributes(array $item, int $pid): void
    {
        if (!$this->attrHelper) {
            $this->attrHelper = new AttributeHelper($this->dbHelper);
        }

        // Determine store IDs to write to
        $storeIds = $this->getItemStoreIds($item);

        // Load attribute metadata for all item columns
        $this->attrHelper->initAttrInfos(array_keys($item));

        // Separate static attributes from EAV attributes
        $staticValues = [];
        $eavValues = [];

        foreach ($item as $code => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            if ($code === 'media_gallery') {
                continue;
            }

            $normalizedValue = $this->normalizeAttributeValue($code, $value);
            if ($normalizedValue === null) {
                continue;
            }
            $info = $this->attrHelper->getAttrInfo($code);
            if (!$info) {
                continue; // Not a known attribute
            }
            $backendType = $info['backend_type'] ?? '';
            if ($backendType === 'static') {
                $staticValues[$code] = $normalizedValue;
            } else {
                $eavValues[$code] = $normalizedValue;
            }
        }

        // Update static fields on catalog_product_entity
        if (!empty($staticValues)) {
            $this->writeStaticAttributes($pid, $staticValues);
        }

        // Write EAV values per store
        foreach ($storeIds as $storeId) {
            $this->writeEavAttributes($pid, $eavValues, $storeId);
        }

        $this->updateMediaGallery($pid, $item);
    }

    /**
     * Write static attributes to catalog_product_entity.
     */
    private function writeStaticAttributes(int $pid, array $values): void
    {
        $table = $this->dbHelper->tableName('catalog_product_entity');
        $sets = [];
        $params = [];
        foreach ($values as $col => $val) {
            if (!$this->dbHelper->columnExists($table, $col)) {
                continue;
            }
            $sets[] = "{$col} = ?";
            $params[] = $val;
        }
        if (empty($sets)) {
            return;
        }
        $params[] = $pid;
        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE entity_id = ?";
        $this->dbHelper->update($sql, $params);
    }

    private function resolveAttributeSetId(mixed $value): int
    {
        if (is_numeric($value)) {
            $id = (int) $value;
            if ($id > 0) {
                return $id;
            }
        }

        if (is_string($value) && $value !== '') {
            $table = $this->dbHelper->tableName('eav_attribute_set');
            $sql = "SELECT attribute_set_id FROM {$table} WHERE entity_type_id = ? AND attribute_set_name = ?";
            $id = $this->dbHelper->selectOne($sql, [$this->getProductEntityType(), $value], 'attribute_set_id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        return $this->getDefaultAttributeSetId();
    }

    /**
     * Write EAV attributes to their backend tables.
     */
    private function writeEavAttributes(int $pid, array $values, int $storeId): void
    {
        // Group attributes by backend type
        $byType = [];
        $valueSeparator = $this->context['csv_value_separator'] ?? ',';

        foreach ($values as $code => $value) {
            $info = $this->attrHelper->getAttrInfo($code);
            if (!$info) {
                continue;
            }
            $backendType = $info['backend_type'];
            $attrId = (int) $info['attribute_id'];

            $frontendInput = $info['frontend_input'] ?? '';
            if ($frontendInput === 'select' || $frontendInput === 'multiselect') {
                // Multiselect values are comma-separated option IDs stored in their natural
                // backend table (varchar/text). Single-select values are stored as int.
                if ($frontendInput === 'multiselect') {
                    $backendType = $info['backend_type'];
                } else {
                    $backendType = 'int';
                }

                if ($frontendInput === 'multiselect') {
                    $rawValues = array_filter(array_map('trim', explode($valueSeparator, (string) $value)), fn($v) => $v !== '');
                } else {
                    $single = trim((string) $value);
                    $rawValues = $single === '' ? [] : [$single];
                }

                if (empty($rawValues)) {
                    continue;
                }

                // Direct-mapped system attributes (status, visibility) are stored as
                // their canonical integer value; do not resolve them through eav_attribute_option.
                if (in_array($code, ['status', 'visibility'], true)) {
                    $value = (string) $rawValues[0];
                } else {
                    $optionIds = $this->attrHelper->getOptionIds($attrId, $storeId, $rawValues);
                    if (empty($optionIds)) {
                        continue;
                    }

                    if ($frontendInput === 'multiselect') {
                        $value = implode(',', array_values($optionIds));
                    } else {
                        $value = (string) array_values($optionIds)[0];
                    }
                }

                if ($value === '') {
                    continue;
                }
            }

            if (!isset($byType[$backendType])) {
                $byType[$backendType] = [];
            }
            $byType[$backendType][] = [
                'attribute_id' => $attrId,
                'value' => $value,
                'code' => $code,
            ];
        }

        // Write per backend type table
        foreach ($byType as $type => $attrs) {
            $table = $this->dbHelper->tableName("catalog_product_entity_{$type}");
            foreach ($attrs as $attr) {
                $this->writeEavValue($table, $pid, $attr['attribute_id'], $storeId, $attr['value'], $attr['code']);
            }
        }
    }

    private function writeEavValue(string $table, int $entityId, int $attributeId, int $storeId, mixed $value, string $attributeCode): void
    {
        $displayValue = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $displayValue = $displayValue !== false ? mb_substr($displayValue, 0, 120) : '[unserializable]';

        try {
            // Check if a value already exists
            $sql = "SELECT value_id FROM {$table} WHERE entity_id = ? AND attribute_id = ? AND store_id = ?";
            $existing = $this->dbHelper->selectOne($sql, [$entityId, $attributeId, $storeId], 'value_id');

            if ($existing !== null) {
                $sql = "UPDATE {$table} SET value = ? WHERE value_id = ?";
                $this->dbHelper->update($sql, [$value, $existing]);
            } else {
                $sql = "INSERT INTO {$table} (entity_id, attribute_id, store_id, value) VALUES (?, ?, ?, ?)";
                $this->dbHelper->insert($sql, [$entityId, $attributeId, $storeId, $value]);
            }
        } catch (\Throwable $e) {
            $contextMsg = sprintf(
                'Attribute "%s" (ID %d) with value "%s" failed for entity %d, store %d.',
                $attributeCode,
                $attributeId,
                $displayValue,
                $entityId,
                $storeId
            );
            throw new \RuntimeException($contextMsg . ' ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Determine store IDs from item store column.
     *
     * @return int[]
     */
    private function getItemStoreIds(array $item): array
    {
        if (!empty($item['store'])) {
            $storeCodes = array_map('trim', explode(',', $item['store']));
            $storeIds = [];
            foreach ($storeCodes as $code) {
                if ($code === 'admin' || $code === '0') {
                    $storeIds[] = 0;
                    continue;
                }
                $table = $this->dbHelper->tableName('store');
                $sql = "SELECT store_id FROM {$table} WHERE code = ?";
                $sid = $this->dbHelper->selectOne($sql, [$code], 'store_id');
                if ($sid === null) {
                    throw new \InvalidArgumentException('Unknown store code: ' . $code);
                }
                $storeIds[] = (int) $sid;
            }
            return array_unique($storeIds ?: [0]);
        }
        return [0];
    }

    /**
     * Update product stock in cataloginventory_stock_item.
     */
    private function updateStock(int $pid, array $item): void
    {
        $stockCols = ['qty', 'min_qty', 'use_config_min_qty', 'is_qty_decimal', 'backorders',
                      'use_config_backorders', 'min_sale_qty', 'use_config_min_sale_qty',
                      'max_sale_qty', 'use_config_max_sale_qty', 'is_in_stock', 'low_stock_date',
                      'notify_stock_qty', 'use_config_notify_stock_qty', 'manage_stock',
                      'use_config_manage_stock', 'stock_status_changed_auto', 'use_config_qty_increments',
                      'qty_increments', 'use_config_enable_qty_inc', 'enable_qty_increments',
                      'is_decimal_divided', 'website_id', 'deferred_stock_update', 'use_config_deferred_stock_update'];

        $stockItemCols = array_intersect(array_keys($item), $stockCols);
        if (empty($stockItemCols)) {
            return;
        }

        $csit = $this->dbHelper->tableName('cataloginventory_stock_item');
        $stockId = (int) ($item['stock_id'] ?? 1);

        // Ensure stock item row exists
        $this->dbHelper->insert(
            "INSERT IGNORE INTO {$csit} (product_id, stock_id) VALUES (?, ?)",
            [$pid, $stockId]
        );

        // Build update
        $sets = [];
        $params = [];
        foreach ($stockItemCols as $col) {
            $sets[] = "{$col} = ?";
            $params[] = $item[$col];
        }

        // Auto-calculate is_in_stock if qty changed and not explicitly set
        if (isset($item['qty']) && !in_array('is_in_stock', $stockItemCols)) {
            $mqty = (float) ($item['min_qty'] ?? 0);
            $isInStock = (float) $item['qty'] > $mqty ? 1 : 0;
            $sets[] = 'is_in_stock = ?';
            $params[] = $isInStock;
        }

        $params[] = $pid;
        $params[] = $stockId;
        $sql = "UPDATE {$csit} SET " . implode(', ', $sets) . ' WHERE product_id = ? AND stock_id = ?';
        $this->dbHelper->update($sql, $params);

        $this->updateMsiStock($pid, $item);
    }

    private function updateMsiStock(int $pid, array $item): void
    {
        if (empty($this->context['enable_msi']) || !$this->dbHelper) {
            return;
        }

        $sku = $item['sku'] ?? null;
        if (!$sku) {
            return;
        }

        $qty = isset($item['qty']) ? (float) $item['qty'] : null;
        $status = null;
        if (isset($item['is_in_stock'])) {
            $status = ((int) $item['is_in_stock']) > 0 ? 1 : 0;
        }
        if ($qty === null && $status === null) {
            return;
        }

        $sourceCode = $item['source_code'] ?? 'default';
        if ($status === null) {
            $status = ($qty !== null && $qty > 0) ? 1 : 0;
        }
        $updateQuantity = $qty !== null;
        $qty ??= 0.0;

        $sourceTable = $this->dbHelper->tableName('inventory_source_item');
        $sql = "INSERT INTO {$sourceTable} (source_code, sku, quantity, status) VALUES (?, ?, ?, ?) " .
               "ON DUPLICATE KEY UPDATE " . ($updateQuantity ? "quantity = VALUES(quantity), " : "") . "status = VALUES(status)";
        $this->dbHelper->insert($sql, [$sourceCode, $sku, $qty, $status]);
    }

    /**
     * Assign categories to product based on category_ids column.
     */
    private function assignCategories(int $pid, array $item): void
    {
        if (empty($item['category_ids'])) {
            return;
        }

        $cce = $this->dbHelper->tableName('catalog_category_entity');
        $ccpt = $this->dbHelper->tableName('catalog_category_product');

        // Reset existing assignments if category_reset is not 0
        if (!isset($item['category_reset']) || $item['category_reset'] == 1) {
            $sql = "DELETE FROM {$ccpt} WHERE product_id = ?";
            $this->dbHelper->delete($sql, [$pid]);
        }

        $catDefs = array_map('trim', explode(',', $item['category_ids']));
        $inserts = [];
        $data = [];
        $deletes = [];

        foreach ($catDefs as $catDef) {
            $parts = explode('::', $catDef);
            $catId = (int) $parts[0];
            $position = isset($parts[1]) ? (int) $parts[1] : 0;

            if ($catId < 0) {
                $deletes[] = abs($catId);
            } else {
                $inserts[] = '(?, ?, ?)';
                $data[] = $catId;
                $data[] = $pid;
                $data[] = $position;
            }
        }

        // Delete removed categories
        if (!empty($deletes)) {
            $placeholders = implode(',', array_fill(0, count($deletes), '?'));
            $sql = "DELETE FROM {$ccpt} WHERE category_id IN ({$placeholders}) AND product_id = ?";
            $deletes[] = $pid;
            $this->dbHelper->delete($sql, $deletes);
        }

        // Insert new assignments
        if (!empty($inserts)) {
            $sql = "INSERT INTO {$ccpt} (category_id, product_id, position) VALUES " . implode(', ', $inserts) .
                   " ON DUPLICATE KEY UPDATE position = VALUES(position)";
            $this->dbHelper->insert($sql, $data);
        }
    }

    /**
     * Associate product with websites.
     */
    private function updateWebsites(int $pid, array $item): void
    {
        $cpst = $this->dbHelper->tableName('catalog_product_website');

        // If websites column is explicitly set
        if (!empty($item['websites'])) {
            $wsCodes = array_map('trim', explode(',', $item['websites']));
            $cws = $this->dbHelper->tableName('store_website');
            $placeholders = implode(',', array_fill(0, count($wsCodes), '?'));
            $sql = "SELECT website_id FROM {$cws} WHERE code IN ({$placeholders})";
            $rows = $this->dbHelper->selectAll($sql, $wsCodes);
            $wsIds = array_map(fn($r) => (int) $r['website_id'], $rows);
        } else {
            // Default: assign to all websites
            $cs = $this->dbHelper->tableName('store');
            $rows = $this->dbHelper->selectAll("SELECT website_id FROM {$cs} WHERE store_id != 0 GROUP BY website_id");
            $wsIds = array_map(fn($r) => (int) $r['website_id'], $rows);
        }

        if (empty($wsIds)) {
            return;
        }

        // Clear existing and re-insert
        $this->dbHelper->delete("DELETE FROM {$cpst} WHERE product_id = ?", [$pid]);

        $placeholders = implode(',', array_fill(0, count($wsIds), '?'));
        $sql = "INSERT INTO {$cpst} (product_id, website_id) SELECT ?, website_id FROM " .
               $this->dbHelper->tableName('store_website') .
               " WHERE website_id IN ({$placeholders})";
        $this->dbHelper->insert($sql, array_merge([$pid], $wsIds));
    }

    private function getProductEntityType(): int
    {
        if (!$this->dbHelper) {
            return 4; // Magento default catalog_product entity type
        }
        if ($this->attrHelper) {
            return $this->attrHelper->getProductEntityTypeId();
        }
        $table = $this->dbHelper->tableName('eav_entity_type');
        $sql = "SELECT entity_type_id FROM {$table} WHERE entity_type_code = ?";
        $id = $this->dbHelper->selectOne($sql, ['catalog_product'], 'entity_type_id');
        return $id !== null ? (int) $id : 4;
    }

    private function getDefaultAttributeSetId(): int
    {
        if (!$this->dbHelper) {
            return 4; // Default attribute set
        }
        $table = $this->dbHelper->tableName('eav_attribute_set');
        $sql = "SELECT attribute_set_id FROM {$table} WHERE entity_type_id = ? AND attribute_set_name = ?";
        $id = $this->dbHelper->selectOne($sql, [$this->getProductEntityType(), 'Default'], 'attribute_set_id');
        return $id !== null ? (int) $id : 4;
    }

    public function getReport(): array
    {
        return $this->report;
    }
}
