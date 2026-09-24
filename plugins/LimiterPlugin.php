<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\ConfigurablePluginInterface;

class LimiterPlugin extends AbstractPlugin implements ConfigurablePluginInterface
{
    private array $recRanges = [];
    private int $rmax = -1;
    private array $filters = [];
    private array $colFilter = [];

    public function __construct()
    {
        parent::__construct('limiter', 'Magmi Import Limiter', 'v0.0.7', 'preprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'ranges',
                'label' => 'Record ranges (e.g. 1-100,200-300)',
                'type' => 'text',
                'default' => '',
            ],
            [
                'name' => 'filters',
                'label' => 'Filters (field::regex;;field2::regex2)',
                'type' => 'text',
                'default' => '',
            ],
            [
                'name' => 'col_filter',
                'label' => 'Column filter (comma separated)',
                'type' => 'text',
                'default' => '',
            ],
        ];
    }

    public function setConfig(array $config): void
    {
        parent::setConfig($config);
        $this->parseRanges($config['ranges'] ?? '');
        $this->parseFilters($config['filters'] ?? '');
        $this->colFilter = array_filter(array_map('trim', explode(',', $config['col_filter'] ?? '')));
    }

    private function parseRanges(string $rangestr): void
    {
        $this->recRanges = [];
        if ($rangestr === '') {
            return;
        }
        foreach (explode(',', $rangestr) as $rdef) {
            $r = explode('-', $rdef);
            $rmin = isset($r[0]) && $r[0] !== '' ? (int) $r[0] : -1;
            $rmax = isset($r[1]) && $r[1] !== '' ? (int) $r[1] : $rmin;
            if ($rmax > $this->rmax) {
                $this->rmax = $rmax;
            }
            $this->recRanges[] = [$rmin, $rmax];
        }
    }

    private function parseFilters(string $fltstr): void
    {
        $this->filters = [];
        if ($fltstr === '') {
            return;
        }
        foreach (explode(';;', $fltstr) as $fdef) {
            $parts = explode('::', $fdef, 2);
            if (count($parts) === 2) {
                $this->filters[] = [trim($parts[0]), trim($parts[1])];
            }
        }
    }

    private function filterMatch(array $item, array $fltdef): bool
    {
        [$field, $re] = $fltdef;
        $negate = false;
        if (str_starts_with($field, '!')) {
            $field = substr($field, 1);
            $negate = true;
        }
        if (!isset($item[$field])) {
            return false;
        }
        $match = preg_match("|$re|", (string) $item[$field]);
        if ($negate) {
            $match = !$match;
        }
        return $match;
    }

    public function processColumnList(array &$columns, array $context): bool
    {
        if (!empty($this->colFilter)) {
            $columns = array_values(array_intersect($columns, $this->colFilter));
        }
        return true;
    }

    public function processItemBeforeId(array &$item, array $context): bool
    {
        $ok = true;
        $crow = $context['current_row'] ?? 0;

        if (!empty($this->recRanges)) {
            $inRange = false;
            foreach ($this->recRanges as $rr) {
                if ($crow >= $rr[0] && ($crow <= $rr[1] || $rr[1] === -1)) {
                    $inRange = true;
                    break;
                }
            }
            $ok = $inRange;
        }

        if ($ok && !empty($this->filters)) {
            foreach ($this->filters as $flt) {
                if ($this->filterMatch($item, $flt)) {
                    $ok = false;
                    break;
                }
            }
        }

        if (!empty($this->colFilter) && $ok) {
            $item = array_intersect_key($item, array_flip($this->colFilter));
        }

        return $ok;
    }
}
