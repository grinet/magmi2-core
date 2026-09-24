<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class ColumnMapperPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    private array $mappings = [];

    public function __construct()
    {
        parent::__construct('columnmapper', 'Column mapper', 'v0.0.3b', 'preprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'mappings',
                'label' => 'Column mappings',
                'type' => 'textarea',
                'default' => "",
            ],
        ];
    }

    public function setConfig(array $config): void
    {
        parent::setConfig($config);
        $this->mappings = [];
        $lines = explode("\n", $config['mappings'] ?? '');
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$from, $to] = explode('=', $line, 2);
            $this->mappings[trim($from)] = array_map('trim', explode(',', $to));
        }
    }

    public function processColumnList(array &$columns, array $context): bool
    {
        $out = [];
        $extras = [];
        foreach ($columns as $c) {
            if (isset($this->mappings[$c])) {
                $mapped = array_unique($this->mappings[$c]);
                $first = array_shift($mapped);
                $out[] = $first;
                if ($first !== $c) {
                    // first replacement
                }
                if (count($mapped) > 0) {
                    $extras = array_merge($extras, $mapped);
                }
            } else {
                $out[] = $c;
            }
        }
        $columns = array_unique(array_merge($out, $extras));
        return true;
    }

    public function processItemBeforeId(array &$item, array $context): bool
    {
        foreach ($this->mappings as $from => $targets) {
            if (isset($item[$from])) {
                foreach ($targets as $t) {
                    $item[$t] = $item[$from];
                }
                if (!in_array($from, $targets)) {
                    unset($item[$from]);
                }
            }
        }
        return true;
    }
}
