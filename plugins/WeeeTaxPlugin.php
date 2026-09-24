<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class WeeeTaxPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('weeetax', 'Weee Tax importer', 'v0.0.5', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'country',
                'label' => 'Default country',
                'type' => 'text',
                'default' => 'US',
            ],
            [
                'name' => 'attributes',
                'label' => 'Weee attributes (comma separated)',
                'type' => 'text',
                'default' => '',
            ],
        ];
    }
}
