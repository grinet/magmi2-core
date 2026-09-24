<?php

declare(strict_types=1);

namespace Magmi\Core\Import;

use Magmi\Core\Config\ProfileManager;
use Magmi\Core\Database\DbConfig;
use Magmi\Core\Database\DbHelper;
use Magmi\Core\Plugin\PluginDiscovery;
use Magmi\Core\Plugin\PluginManager;

/**
 * Magmi-compatible DataPump factory.
 *
 * Usage:
 *   $pump = DataPumpFactory::getDataPumpInstance('productimport');
 *   $pump->beginImportSession('my_profile', 'create');
 *   $pump->ingest(['sku' => 'SKU001', 'name' => 'Product 1', ...]);
 *   $pump->endImportSession();
 */
class DataPumpFactory
{
    public static function getDataPumpInstance(string $type, ?\Magmi\Core\Config\RuntimeConfig $runtime = null): DataPumpInterface
    {
        return match ($type) {
            'productimport' => new ProductImportDataPump($runtime),
            default => throw new \InvalidArgumentException("Unknown data pump type: {$type}"),
        };
    }
}
