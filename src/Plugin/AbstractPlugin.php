<?php

declare(strict_types=1);

namespace Magmi\Core\Plugin;

/**
 * Base plugin class with default no-op implementations for all hooks.
 * Plugins should extend this and override only the hooks they need.
 */
abstract class AbstractPlugin implements PluginInterface
{
    private string $id;
    private string $name;
    private string $version;
    private string $category;
    private string $description;
    private array $config = [];

    public function __construct(
        string $id,
        string $name,
        string $version,
        string $category,
        string $description = ''
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->version = $version;
        $this->category = $category;
        $this->description = $description;
    }

    public function getIdentifier(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConfigFields(): array
    {
        return [];
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function startImport(array $context): bool
    {
        return true;
    }

    public function processColumnList(array &$columns, array $context): bool
    {
        return true;
    }

    public function beforeImport(array $context): bool
    {
        return true;
    }

    public function processItemBeforeId(array &$item, array $context): bool
    {
        return true;
    }

    public function preprocessItemAfterId(array &$item, array $context): bool
    {
        return true;
    }

    public function processItemAfterId(array &$item, array $context): bool
    {
        return true;
    }

    public function processItemAfterImport(array &$item, array $context): bool
    {
        return true;
    }

    public function processItemException(array &$item, array $context): void
    {
    }

    public function endImport(array $context): bool
    {
        return true;
    }

    public function afterImport(array $context): bool
    {
        return true;
    }
}
