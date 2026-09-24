<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class GroupPricePlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('groupprice', 'Group Price Importer', 'v0.0.4', 'itemprocessors');
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
        ];
    }
}
