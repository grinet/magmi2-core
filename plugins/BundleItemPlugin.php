<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class BundleItemPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('bundleitem', 'Bundle Item processor', 'v1.1', 'producttypes');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'bundle_options_col',
                'label' => 'Bundle options column',
                'type' => 'text',
                'default' => 'bundle_options',
            ],
        ];
    }
}
