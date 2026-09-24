<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class GenericMapperPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('genericmapper', 'Generic mapper', 'v0.0.6a', 'preprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'mappings_dir',
                'label' => 'Mappings CSV directory',
                'type' => 'text',
                'default' => '',
            ],
        ];
    }
}
