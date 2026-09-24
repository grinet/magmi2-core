<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class ValueTrimmerPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    public function __construct()
    {
        parent::__construct('valuetrimmer', 'Value Trimmer for select/multiselect', 'v0.0.3', 'preprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'columns',
                'label' => 'Columns to trim',
                'type' => 'text',
                'default' => '',
            ],
        ];
    }

    public function processItemBeforeId(array &$item, array $context): bool
    {
        $cols = array_filter(array_map('trim', explode(',', $this->getConfig()['columns'] ?? '')));
        foreach ($cols as $c) {
            if (isset($item[$c])) {
                $item[$c] = trim((string) $item[$c]);
            }
        }
        return true;
    }
}
