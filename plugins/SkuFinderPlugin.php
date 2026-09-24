<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class SkuFinderPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('skufinder', 'SKU Finder', 'v0.0.3', 'preprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'matchfield',
                'label' => 'Match field (attribute code)',
                'type' => 'text',
                'default' => '',
            ],
        ];
    }

    public function processItemBeforeId(array &$item, array $context): bool
    {
        $matchField = trim($this->getConfig()['matchfield'] ?? '');
        if ($matchField === '' || $matchField === 'sku') {
            return true;
        }

        if (!isset($item[$matchField]) || trim((string) $item[$matchField]) === '') {
            return false;
        }
        $db = $context['db_helper'] ?? null;
        if (!$db instanceof \Magmi\Core\Database\DbHelper) {
            throw new \RuntimeException('SKU Finder requires a database connection.');
        }
        $attributes = $db->tableName('eav_attribute');
        $types = $db->tableName('eav_entity_type');
        $metadata = $db->selectAll(
            "SELECT a.attribute_id, a.backend_type FROM {$attributes} a JOIN {$types} t ON t.entity_type_id=a.entity_type_id WHERE t.entity_type_code='catalog_product' AND a.attribute_code=?",
            [$matchField]
        );
        if (!$metadata) {
            throw new \InvalidArgumentException('Unknown product match attribute: ' . $matchField);
        }
        $attribute = $metadata[0];
        $products = $db->tableName('catalog_product_entity');
        $backend = $attribute['backend_type'];
        if ($backend === 'static') {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $matchField) || !$db->columnExists($products, $matchField)) {
                throw new \InvalidArgumentException('Invalid static match attribute.');
            }
            $matches = $db->selectAll("SELECT sku FROM {$products} WHERE `{$matchField}`=? LIMIT 2", [$item[$matchField]]);
        } else {
            if (!in_array($backend, ['varchar', 'int', 'decimal', 'text', 'datetime'], true)) {
                throw new \InvalidArgumentException('Unsupported match attribute type.');
            }
            $values = $db->tableName('catalog_product_entity_' . $backend);
            $matches = $db->selectAll(
                "SELECT DISTINCT p.sku FROM {$products} p JOIN {$values} v ON v.entity_id=p.entity_id WHERE v.attribute_id=? AND v.store_id=0 AND v.value=? LIMIT 2",
                [$attribute['attribute_id'], $item[$matchField]]
            );
        }
        if (count($matches) > 1) {
            throw new \RuntimeException('SKU Finder matched more than one product. Use a unique attribute.');
        }
        if (!$matches) {
            return false;
        }
        $item['sku'] = $matches[0]['sku'];
        return true;
    }
}
