<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class ProductTagsPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('producttags', 'Product Tags Importer', 'v0.0.3', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'tag_separator',
                'label' => 'Tag separator',
                'type' => 'text',
                'default' => ',',
            ],
        ];
    }
}
