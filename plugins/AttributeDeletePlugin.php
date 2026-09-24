<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class AttributeDeletePlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('attributedelete', 'Attribute Deleter', 'v1.0', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'attributes',
                'label' => 'Attributes to delete (comma separated)',
                'type' => 'text',
                'default' => '',
            ],
        ];
    }
}
