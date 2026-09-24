<?php

declare(strict_types=1);

namespace Magmi\Core\Plugin;

/**
 * Scans directories for Magmi plugin classes and instantiates them.
 */
class PluginDiscovery
{
    /** Bundled plugins are available to every host; custom identifiers override them. */
    public function scanAvailable(string $customDirectory): array
    {
        $bundled = $this->scan(dirname(__DIR__, 2) . '/plugins');
        return array_replace($bundled, $this->scan($customDirectory));
    }

    /** Configuration panels alone do not constitute a working import plugin. */
    public static function hasImportHooks(PluginInterface $plugin): bool
    {
        foreach (['startImport', 'beforeImport', 'processColumnList', 'processItemBeforeId',
            'preprocessItemAfterId', 'processItemAfterId', 'processItemAfterImport', 'endImport', 'afterImport'] as $hook) {
            $method = new \ReflectionMethod($plugin, $hook);
            if ($method->getDeclaringClass()->getName() !== AbstractPlugin::class) {
                return true;
            }
        }
        return false;
    }

    public static function assertImplemented(PluginInterface $plugin): void
    {
        if (!self::hasImportHooks($plugin)) {
            throw new \RuntimeException('Plugin "' . $plugin->getIdentifier() . '" has configuration only; its import behavior is not implemented.');
        }
    }

    /**
     * Scan a directory for plugin classes.
     *
     * @return PluginInterface[]
     */
    public function scan(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $plugins = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $plugin = $this->loadFromFile($file->getPathname());
            if ($plugin !== null) {
                $plugins[$plugin->getIdentifier()] = $plugin;
            }
        }

        return $plugins;
    }

    /**
     * Attempt to load a plugin from a single file.
     */
    public function loadFromFile(string $file): ?PluginInterface
    {
        if (!file_exists($file)) {
            return null;
        }

        include_once $file;
        foreach (get_declared_classes() as $className) {
            if (!class_exists($className)) {
                continue;
            }
            $ref = new \ReflectionClass($className);
            if ($ref->getFileName() !== realpath($file)) {
                continue;
            }
            if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait()) {
                continue;
            }
            if ($ref->implementsInterface(PluginInterface::class)) {
                $instance = $ref->newInstance();
                if ($instance instanceof PluginInterface) {
                    return $instance;
                }
            }
        }

        return null;
    }
}
