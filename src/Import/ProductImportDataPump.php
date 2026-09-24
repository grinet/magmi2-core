<?php

declare(strict_types=1);

namespace Magmi\Core\Import;

use Magmi\Core\Config\ProfileManager;
use Magmi\Core\Config\RuntimeConfig;
use Magmi\Core\Database\DbConfig;
use Magmi\Core\Database\DbHelper;
use Magmi\Core\Plugin\PluginDiscovery;
use Magmi\Core\Plugin\PluginManager;

/**
 * Magmi-compatible product import data pump.
 *
 * Each ingest() processes its record immediately within a shared import session.
 */
class ProductImportDataPump implements DataPumpInterface
{
    private string $profileName = '';
    private string $mode = 'create';
    private bool $started = false;
    private int $recordIndex = 0;
    private ?ImportEngine $engine = null;
    private ?ProfileManager $profileManager = null;

    public function __construct(private readonly ?RuntimeConfig $runtime = null) {}

    public function beginImportSession(string $profileName, string $mode): void
    {
        if ($this->engine !== null) {
            throw new \LogicException('An import session is already active.');
        }
        $this->profileName = $profileName;
        $this->mode = $this->normalizeMode($mode);
        $this->recordIndex = 0;
        $this->started = false;

        $configDir = $this->resolveConfigDir();
        $this->profileManager = new ProfileManager($configDir);

        if ($this->profileManager->load($profileName) === null) {
            throw new \InvalidArgumentException('Profile not found: ' . $profileName);
        }
        $pluginManager = $this->buildPluginManager();
        $dbHelper = $this->buildDbHelper();

        $this->engine = new ImportEngine($pluginManager, $dbHelper);
        $global = array_merge($this->profileManager->getGlobalConfig(), $this->runtime?->context ?? []);
        $this->engine->setContext(array_merge($global, [
            'profile' => $profileName,
            'mode' => $this->mode,
            'magento_media_dir' => $global['magento_media_dir'] ??
                (!empty($global['magento_path']) ? rtrim($global['magento_path'], '/') . '/pub/media' : null),
        ]));
    }

    public function ingest(array $record): bool
    {
        if ($this->engine === null) {
            throw new \LogicException('No import session started. Call beginImportSession() first.');
        }
        if (!$this->started) {
            $this->started = true;
            if (!$this->engine->beginSession(array_keys($record))) {
                return false;
            }
        }
        return $this->engine->processRecord($record, $this->recordIndex++);
    }

    public function endImportSession(): array
    {
        if ($this->engine === null) {
            throw new \LogicException('No import session started. Call beginImportSession() first.');
        }
        try {
            if (!$this->started) {
                $this->engine->beginSession(['sku']);
            }
            return $this->engine->finishSession();
        } finally {
            $this->engine = null;
            $this->started = false;
        }
    }

    private function normalizeMode(string $mode): string
    {
        return match ($mode) {
            'xcreate' => 'xcreate',
            'create' => 'create',
            'update' => 'update',
            default => throw new \InvalidArgumentException('Invalid import mode: ' . $mode),
        };
    }

    private function resolveConfigDir(): string
    {
        if ($this->runtime !== null) {
            return $this->runtime->configDirectory();
        }
        if (defined('MAGMI_GUI_BASE_DIR')) {
            return MAGMI_GUI_BASE_DIR . '/data/config';
        }

        return getcwd() . '/data/config';
    }

    private function buildPluginManager(): PluginManager
    {
        $manager = new PluginManager();
        $discovery = new PluginDiscovery();

        if ($this->profileManager !== null) {
            $profile = $this->profileManager->load($this->profileName) ?? new \Magmi\Core\Config\Profile($this->profileName);
            $pluginConfig = $profile->getPluginConfig();
            $pluginDir = $this->resolvePluginDir();
            $realPlugins = $discovery->scanAvailable($pluginDir);
            foreach ($realPlugins as $plugin) {
                $manager->register($plugin);
            }
            foreach ($pluginConfig as $pluginId => $config) {
                if ($config['enabled'] ?? false) {
                    $manager->enable($pluginId);
                    $plugin = $manager->getAll()[$pluginId];
                    PluginDiscovery::assertImplemented($plugin);
                    if ($plugin instanceof \Magmi\Core\Plugin\ConfigurablePluginInterface) {
                        $plugin->setConfig($config['config'] ?? []);
                    }
                }
            }
        }

        return $manager;
    }

    private function buildDbHelper(): ?DbHelper
    {
        if ($this->runtime?->database !== null) {
            return $this->runtime->database;
        }
        if ($this->profileManager === null) {
            return null;
        }

        $global = $this->profileManager->getGlobalConfig();
        $dbConfig = $this->buildDbConfig($global);
        if ($dbConfig === null) {
            throw new \RuntimeException('Database configuration is required for DataPump imports.');
        }
        $dbHelper = new DbHelper($dbConfig);
        $dbHelper->connect();
        return $dbHelper;
    }

    private function buildDbConfig(array $global): ?DbConfig
    {
        if (!empty($global['magento_path']) && is_dir($global['magento_path'])) {
            $envPath = rtrim($global['magento_path'], '/\\') . '/app/etc/env.php';
            if (file_exists($envPath)) {
                return DbConfig::fromEnvPhp($global['magento_path']);
            }
        }

        if (!empty($global['db_host']) && !empty($global['db_name']) && !empty($global['db_user'])) {
            return DbConfig::fromArray([
                'host' => $global['db_host'],
                'port' => $global['db_port'] ?? 3306,
                'dbname' => $global['db_name'],
                'user' => $global['db_user'],
                'password' => $global['db_pass'] ?? '',
                'table_prefix' => $global['db_prefix'] ?? '',
            ]);
        }

        return null;
    }

    private function resolvePluginDir(): string
    {
        if ($this->runtime !== null) {
            return $this->runtime->pluginDirectory();
        }
        if (defined('MAGMI_GUI_BASE_DIR')) {
            return MAGMI_GUI_BASE_DIR . '/plugins';
        }

        return getcwd() . '/plugins';
    }

}
