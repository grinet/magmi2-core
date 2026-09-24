<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class ConfigurableItemPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('configurableitem', 'Configurable Item processor', 'v1.3.7a', 'producttypes');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'simplesbeforeconf',
                'label' => 'Create simples before configurable',
                'type' => 'checkbox',
                'default' => false,
            ],
            [
                'name' => 'updsimplevis',
                'label' => 'Update simple product visibility',
                'type' => 'checkbox',
                'default' => true,
            ],
            [
                'name' => 'nolink',
                'label' => 'Do not link simples to configurable',
                'type' => 'checkbox',
                'default' => false,
            ],
        ];
    }
}
