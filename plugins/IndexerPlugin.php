<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class IndexerPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('indexer', 'On the fly indexer', 'v0.2', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'urlending',
                'label' => 'URL ending',
                'type' => 'text',
                'default' => '.html',
            ],
            [
                'name' => 'usecatinurl',
                'label' => 'Use category in URL',
                'type' => 'checkbox',
                'default' => false,
            ],
            [
                'name' => 'useurlending',
                'label' => 'Use URL ending',
                'type' => 'checkbox',
                'default' => true,
            ],
        ];
    }
}
