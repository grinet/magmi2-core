<?php

declare(strict_types=1);

namespace Magmi\Core\Config;

/**
 * Holds configuration for a single import profile.
 */
class Profile
{
    private string $name;
    private array $datasourceConfig = [];
    private array $pluginConfig = [];
    private array $globalConfig = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setDatasourceConfig(array $config): void
    {
        $this->datasourceConfig = $config;
    }

    public function getDatasourceConfig(): array
    {
        return $this->datasourceConfig;
    }

    public function setPluginConfig(array $config): void
    {
        $this->pluginConfig = $config;
    }

    public function getPluginConfig(): array
    {
        return $this->pluginConfig;
    }

    public function setGlobalConfig(array $config): void
    {
        $this->globalConfig = $config;
    }

    public function getGlobalConfig(): array
    {
        return $this->globalConfig;
    }

    public function isPluginEnabled(string $identifier): bool
    {
        return $this->pluginConfig[$identifier]['enabled'] ?? false;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'datasource' => $this->datasourceConfig,
            'plugins' => $this->pluginConfig,
            'global' => $this->globalConfig,
        ];
    }

    public static function fromArray(array $data): self
    {
        $profile = new self($data['name'] ?? 'Default');
        $profile->setDatasourceConfig($data['datasource'] ?? []);
        $profile->setPluginConfig($data['plugins'] ?? []);
        $profile->setGlobalConfig($data['global'] ?? []);
        return $profile;
    }
}
