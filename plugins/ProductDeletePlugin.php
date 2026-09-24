<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class ProductDeletePlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('productdelete', 'Product Deleter', 'v0.0.2', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'delete_col',
                'label' => 'Delete trigger column',
                'type' => 'text',
                'default' => 'magmi:delete',
            ],
            [
                'name' => 'delsimples',
                'label' => 'Also delete associated simple products',
                'type' => 'checkbox',
                'default' => false,
            ],
        ];
    }
}
