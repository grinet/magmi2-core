<?php

declare(strict_types=1);

namespace Magmi\Core\Plugin;

class PluginManager
{
    /** @var PluginInterface[] */
    private array $plugins = [];

    /** @var array<string, bool> */
    private array $enabled = [];

    public function register(PluginInterface $plugin): void
    {
        $id = $plugin->getIdentifier();
        $this->plugins[$id] = $plugin;
        if (!isset($this->enabled[$id])) {
            $this->enabled[$id] = false;
        }
    }

    public function isRegistered(string $identifier): bool
    {
        return isset($this->plugins[$identifier]);
    }

    public function enable(string $identifier): void
    {
        if (!isset($this->plugins[$identifier])) {
            throw new \InvalidArgumentException("Plugin '{$identifier}' not found.");
        }
        $this->enabled[$identifier] = true;
    }

    public function disable(string $identifier): void
    {
        $this->enabled[$identifier] = false;
    }

    public function isEnabled(string $identifier): bool
    {
        return $this->enabled[$identifier] ?? false;
    }

    /**
     * @return PluginInterface[]
     */
    public function getAll(): array
    {
        return $this->plugins;
    }

    /**
     * @return PluginInterface[]
     */
    public function getEnabled(): array
    {
        $enabled = [];
        foreach ($this->plugins as $id => $plugin) {
            if ($this->enabled[$id]) {
                $enabled[$id] = $plugin;
            }
        }
        return $enabled;
    }

    /**
     * @return PluginInterface[]
     */
    public function getByCategory(string $category): array
    {
        $result = [];
        foreach ($this->plugins as $id => $plugin) {
            if ($plugin->getCategory() === $category) {
                $result[$id] = $plugin;
            }
        }
        return $result;
    }

    /**
     * @return PluginInterface[]
     */
    public function getEnabledByCategory(string $category): array
    {
        $result = [];
        foreach ($this->getEnabled() as $id => $plugin) {
            if ($plugin->getCategory() === $category) {
                $result[$id] = $plugin;
            }
        }
        return $result;
    }

    /**
     * Magmi-compatible plugin hook caller.
     *
     * @param string|string[] $categories  Plugin families to call (comma-separated string or array), or '*' for all
     * @param string          $hook        Hook method name to call on each plugin
     * @param mixed|null      $data        Data to pass by reference (optional)
     * @param array|null      $params      Extra parameters (optional)
     * @param bool            $breakOnFalse Stop calling chain if a plugin returns false
     * @return bool Overall result (false if any plugin returned false and breakOnFalse is true)
     */
    public function call(string|array $categories, string $hook, mixed $data = null, ?array $params = null, bool $breakOnFalse = true): bool
    {
        return $this->callMutable($categories, $hook, $data, $params, $breakOnFalse);
    }

    /** Invoke a hook while preserving changes to the caller's item or columns. */
    public function callMutable(string|array $categories, string $hook, mixed &$data, ?array $params = null, bool $breakOnFalse = true): bool
    {
        $result = true;

        if ($categories === '*') {
            $categories = array_unique(array_map(fn($p) => $p->getCategory(), $this->plugins));
        } elseif (is_string($categories)) {
            $categories = array_map('trim', explode(',', $categories));
        }

        foreach ($categories as $category) {
            foreach ($this->getEnabledByCategory($category) as $plugin) {
                if (!method_exists($plugin, $hook)) {
                    continue;
                }

                if ($data === null) {
                    $callRes = $params === null ? $plugin->$hook([]) : $plugin->$hook($params);
                } else {
                    $callRes = call_user_func_array([$plugin, $hook], [&$data, $params ?? []]);
                }

                if ($callRes === false) {
                    $result = false;
                }

                if ($result === false && $breakOnFalse) {
                    return false;
                }
            }
        }

        return $result;
    }
}
