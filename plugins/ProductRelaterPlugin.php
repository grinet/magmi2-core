<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class ProductRelaterPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('productrelater', 'Product relater', 'v1.0.3', 'relatedproducts');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'separator',
                'label' => 'SKU separator',
                'type' => 'text',
                'default' => ',',
            ],
            [
                'name' => 'position',
                'label' => 'Default position',
                'type' => 'text',
                'default' => '0',
            ],
        ];
    }
}
