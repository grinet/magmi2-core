<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class ValueReplacerPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    private array $replacements = [];
    private array $beforeFields = ['sku', 'attribute_set', 'type'];

    public function __construct()
    {
        parent::__construct('valuereplacer', 'Value Replacer', 'v0.0.8a', 'preprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'replacements',
                'label' => 'Value replacements',
                'type' => 'textarea',
                'default' => "",
            ],
        ];
    }

    public function setConfig(array $config): void
    {
        parent::setConfig($config);
        $this->replacements = [];
        $lines = explode("\n", $config['replacements'] ?? '');
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$col, $value] = explode('=', $line, 2);
            $this->replacements[trim($col)] = trim($value);
        }
    }

    private function parseValue(string $template, array $item, array $context): string
    {
        // Replace {item.field} tokens
        $template = preg_replace_callback('/\{item\.([^}]+)\}/', function ($m) use ($item) {
            return $item[$m[1]] ?? '';
        }, $template);

        // Replace {{ expression }} tokens (simple eval, use with caution)
        $template = preg_replace_callback('/\{\{(.+?)\}\}/', function ($m) use ($item, $context) {
            $expr = $m[1];
            // Replace item refs inside expression
            $expr = preg_replace('/\{item\.([^}]+)\}/', '$item["$1"]', $expr);
            try {
                $val = eval('return ' . $expr . ';');
                return (string) $val;
            } catch (\Throwable $e) {
                return $m[0];
            }
        }, $template);

        return $template;
    }

    public function processColumnList(array &$columns, array $context): bool
    {
        $newCols = array_keys($this->replacements);
        $added = array_diff($newCols, $columns);
        if (!empty($added)) {
            $columns = array_unique(array_merge($columns, $added));
        }
        return true;
    }

    public function processItemBeforeId(array &$item, array $context): bool
    {
        foreach ($this->beforeFields as $f) {
            if (isset($this->replacements[$f])) {
                $item[$f] = $this->parseValue($this->replacements[$f], $item, $context);
            }
        }
        return true;
    }

    public function processItemAfterId(array &$item, array $context): bool
    {
        foreach ($this->replacements as $col => $template) {
            if (in_array($col, $this->beforeFields)) {
                continue;
            }
            $item[$col] = $this->parseValue($template, $item, $context);
        }
        return true;
    }
}
