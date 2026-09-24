<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class CustomOptionsPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('customoptions', 'Custom Options', 'v0.0.7a', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'separator',
                'label' => 'Option separator',
                'type' => 'text',
                'default' => ';',
            ],
        ];
    }
}
