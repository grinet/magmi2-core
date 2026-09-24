<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class CategoryCreatorPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('categorycreator', 'On the fly category creator/importer', 'v0.2.5', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'catsep',
                'label' => 'Category separator',
                'type' => 'text',
                'default' => '/',
            ],
            [
                'name' => 'baseroot',
                'label' => 'Base root category for relative paths',
                'type' => 'text',
                'default' => '',
            ],
            [
                'name' => 'lastonly',
                'label' => 'Assign only last category',
                'type' => 'checkbox',
                'default' => false,
            ],
            [
                'name' => 'urlending',
                'label' => 'URL ending',
                'type' => 'text',
                'default' => '.html',
            ],
        ];
    }
}
