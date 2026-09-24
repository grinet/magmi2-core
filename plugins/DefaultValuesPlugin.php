<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class DefaultValuesPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    private array $defaults = [];
    private array $addedCols = [];

    public function __construct()
    {
        parent::__construct('defaultvalues', 'Default Values setter', 'v0.0.5', 'preprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'defaults',
                'label' => 'Default values',
                'type' => 'textarea',
                'default' => "",
            ],
        ];
    }

    public function setConfig(array $config): void
    {
        parent::setConfig($config);
        $this->defaults = [];
        $lines = explode("\n", $config['defaults'] ?? '');
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$col, $value] = explode('=', $line, 2);
            $this->defaults[trim($col)] = trim($value);
        }
    }

    public function processColumnList(array &$columns, array $context): bool
    {
        $this->addedCols = [];
        $missing = array_diff(array_keys($this->defaults), $columns);
        foreach ($missing as $col) {
            if ($this->defaults[$col] !== '') {
                $columns[] = $col;
                $this->addedCols[] = $col;
            }
        }
        return true;
    }

    public function processItemBeforeId(array &$item, array $context): bool
    {
        foreach ($this->defaults as $col => $value) {
            if (!isset($item[$col]) || $item[$col] === '') {
                $item[$col] = $value;
            }
        }
        return true;
    }
}
