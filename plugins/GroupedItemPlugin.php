<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class GroupedItemPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('groupeditem', 'Grouped Item processor', 'v1.4.1', 'producttypes');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'grouped_skus_col',
                'label' => 'Grouped SKUs column',
                'type' => 'text',
                'default' => 'grouped_skus',
            ],
        ];
    }
}
