<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Database\DbHelper;
use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class MsiStockPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('msistock', 'MSI Stock Synchronizer', 'v1.0.0', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'default_source',
                'label' => 'Default source code',
                'type' => 'text',
                'default' => 'default',
            ],
            [
                'name' => 'assign_default_source',
                'label' => 'Assign default stock source if msi_stock_source is empty',
                'type' => 'checkbox',
                'default' => true,
            ],
            [
                'name' => 'salable_stock_table',
                'label' => 'Salable stock table',
                'type' => 'text',
                'default' => 'inventory_stock_1',
            ],
        ];
    }

    public function processItemAfterImport(array &$item, array $context): bool
    {
        if (empty($context['enable_msi'])) {
            return true;
        }

        $dbHelper = $context['db_helper'] ?? null;
        if (!$dbHelper instanceof DbHelper) {
            return true;
        }

        $sku = trim((string) ($item['sku'] ?? ''));
        if ($sku === '') {
            return true;
        }

        $sources = $this->collectSources($item);
        if (empty($sources)) {
            return true;
        }

        $this->syncSourceItems($dbHelper, $sku, $sources);

        $salableQty = $this->resolveSalableQty($item, $sources);
        if ($salableQty !== null) {
            $this->syncSalableQuantity($dbHelper, $sku, $salableQty);
        }

        return true;
    }

    /**
     * @return array<string, float>
     */
    private function collectSources(array $item): array
    {
        $sources = [];

        if (!empty($item['msi_stock'])) {
            $sources = $this->parseStockPairs((string) $item['msi_stock'], $item);
        }

        if (empty($sources) && !empty($item['msi_stock_source'])) {
            $qty = $this->resolveQuantityFromItem($item);
            if ($qty !== null) {
                $sources[(string) $item['msi_stock_source']] = $qty;
            }
        }

        if (
            empty($sources)
            && !empty($this->getConfig()['assign_default_source'])
        ) {
            $defaultSource = trim((string) ($this->getConfig()['default_source'] ?? ''));
            $qty = $this->resolveQuantityFromItem($item);
            if ($defaultSource !== '' && $qty !== null) {
                $sources[$defaultSource] = $qty;
            }
        }

        return array_filter($sources, function ($qty) {
            return $qty !== null;
        });
    }

    /**
     * @return array<string, float>
     */
    private function parseStockPairs(string $value, array $item): array
    {
        $result = [];
        $parts = preg_split('/[\r\n\|;,]+/', $value) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $delimiter = str_contains($part, ':') ? ':' : (str_contains($part, '=') ? '=' : null);
            if ($delimiter === null) {
                $qty = $this->resolveQuantityFromItem($item);
                if ($qty !== null) {
                    $result[$part] = $qty;
                }
                continue;
            }
            [$source, $qtyString] = explode($delimiter, $part, 2);
            $source = trim($source);
            if ($source === '') {
                continue;
            }
            $qty = $this->toFloat($qtyString);
            if ($qty === null) {
                $qty = $this->resolveQuantityFromItem($item);
            }
            if ($qty !== null) {
                $result[$source] = $qty;
            }
        }

        return $result;
    }

    private function resolveQuantityFromItem(array $item): ?float
    {
        if (isset($item['msi_qty']) && $item['msi_qty'] !== '') {
            return $this->toFloat($item['msi_qty']);
        }
        if (isset($item['qty']) && $item['qty'] !== '') {
            return $this->toFloat($item['qty']);
        }
        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $normalized = (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_SCIENTIFIC);
        return is_nan($normalized) ? null : $normalized;
    }

    private function resolveSalableQty(array $item, array $sources): ?float
    {
        $qty = $this->resolveQuantityFromItem($item);
        if ($qty !== null) {
            return $qty;
        }
        if (!empty($sources)) {
            return array_sum($sources);
        }
        return null;
    }

    private function syncSourceItems(DbHelper $dbHelper, string $sku, array $sources): void
    {
        $table = $dbHelper->tableName('inventory_source_item');
        foreach ($sources as $sourceCode => $qty) {
            $status = $qty > 0 ? 1 : 0;
            $dbHelper->insert(
                "INSERT INTO {$table} (source_code, sku, quantity, status) VALUES (?, ?, ?, ?) " .
                "ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), status = VALUES(status)",
                [$sourceCode, $sku, $qty, $status]
            );
        }
    }

    private function syncSalableQuantity(DbHelper $dbHelper, string $sku, float $qty): void
    {
        $tableName = trim((string) ($this->getConfig()['salable_stock_table'] ?? 'inventory_stock_1'));
        if ($tableName === '') {
            return;
        }
        $table = $dbHelper->tableName($tableName);
        try {
            $updated = $dbHelper->update("UPDATE {$table} SET quantity = ? WHERE sku = ?", [$qty, $sku]);
            if ($updated === 0) {
                $dbHelper->insert(
                    "INSERT INTO {$table} (sku, quantity) VALUES (?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)",
                    [$sku, $qty]
                );
            }
        } catch (\Throwable $e) {
            // Ignore write failures caused by read-only or view-based tables
        }
    }
}
