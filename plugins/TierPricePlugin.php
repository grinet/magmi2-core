<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class TierPricePlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('tierprice', 'Tier price importer', 'v0.0.9a', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'customer_groups',
                'label' => 'Customer groups (comma separated)',
                'type' => 'text',
                'default' => 'General,NOT LOGGED IN',
            ],
            [
                'name' => 'qty_base',
                'label' => 'Base qty for tier prices',
                'type' => 'text',
                'default' => '1',
            ],
        ];
    }
}
