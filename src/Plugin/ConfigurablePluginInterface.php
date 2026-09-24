<?php

declare(strict_types=1);

namespace Magmi\Core\Plugin;

/**
 * Interface for plugins that have their own configuration fields.
 */
interface ConfigurablePluginInterface extends PluginInterface
{
    /**
     * Return an array of configuration field definitions.
     *
     * Each field is an array with keys:
     * - name: string (field name/key)
     * - label: string (human-readable label)
     * - type: string ('text', 'password', 'select', 'checkbox', 'textarea')
     * - default: mixed (default value)
     * - options: array (for select type: value => label)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigFields(): array;

    /**
     * Set plugin configuration values.
     *
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void;

    /**
     * Get current plugin configuration values.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;
}
