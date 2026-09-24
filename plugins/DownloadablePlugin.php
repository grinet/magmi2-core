<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class DownloadablePlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('downloadable', 'Downloadable products importer', 'v1.0.0.1', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'sample_dir',
                'label' => 'Sample files directory',
                'type' => 'text',
                'default' => 'downloadable/files/samples',
            ],
            [
                'name' => 'file_dir',
                'label' => 'Files directory',
                'type' => 'text',
                'default' => 'downloadable/files/files',
            ],
        ];
    }
}
