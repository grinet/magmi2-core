<?php

declare(strict_types=1);
namespace Magmi\Core\Tests\Config;

use Magmi\Core\Config\RuntimeConfig;
use Magmi\Core\Plugin\PluginDiscovery;
use PHPUnit\Framework\TestCase;

final class RuntimeConfigTest extends TestCase
{
    public function testHostPathsDoNotDependOnGui(): void
    {
        $runtime = new RuntimeConfig('/tmp/custom-host');
        self::assertSame('/tmp/custom-host/data/config', $runtime->configDirectory());
        self::assertSame('/tmp/custom-host/plugins', $runtime->pluginDirectory());
    }
    public function testBundledPluginsAreAvailableWithoutGui(): void
    {
        $plugins = (new PluginDiscovery())->scanAvailable('/does-not-exist');
        self::assertArrayHasKey('imageattr', $plugins);
        self::assertArrayHasKey('msistock', $plugins);
        self::assertTrue(PluginDiscovery::hasImportHooks($plugins['columnmapper']));
    }
    public function testRelativePathsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeConfig('relative');
    }
}
