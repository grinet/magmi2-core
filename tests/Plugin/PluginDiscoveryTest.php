<?php

declare(strict_types=1);

namespace Magmi\Core\Tests\Plugin;

use Magmi\Core\Plugin\PluginDiscovery;
use Magmi\Core\Plugin\StubPlugin;
use PHPUnit\Framework\TestCase;

class PluginDiscoveryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/magmi_plugins_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    public function testScanFindsPlugin(): void
    {
        file_put_contents($this->tmpDir . '/MyPlugin.php', '<?php
namespace Magmi\Core\Tests\Plugin\Fixtures;
use Magmi\Core\Plugin\AbstractPlugin;
class MyPlugin extends AbstractPlugin {
    public function __construct() {
        parent::__construct("myplugin", "My Plugin", "1.0", "itemprocessors");
    }
}
');

        $discovery = new PluginDiscovery();
        $plugins = $discovery->scan($this->tmpDir);

        $this->assertCount(1, $plugins);
        $this->assertArrayHasKey('myplugin', $plugins);
        $again = $discovery->scan($this->tmpDir);
        $this->assertArrayHasKey('myplugin', $again);
        $this->assertNotSame($plugins['myplugin'], $again['myplugin']);
    }

    public function testScanIgnoresNonPlugins(): void
    {
        file_put_contents($this->tmpDir . '/Helper.php', '<?php
namespace Magmi\Core\Tests\Plugin\Fixtures;
class Helper {}
');

        $discovery = new PluginDiscovery();
        $plugins = $discovery->scan($this->tmpDir);

        $this->assertCount(0, $plugins);
    }

    public function testLoadFromFile(): void
    {
        $path = $this->tmpDir . '/DirectPlugin.php';
        file_put_contents($path, '<?php
namespace Magmi\Core\Tests\Plugin\Fixtures;
use Magmi\Core\Plugin\AbstractPlugin;
class DirectPlugin extends AbstractPlugin {
    public function __construct() {
        parent::__construct("directplugin", "Direct Plugin", "1.0", "itemprocessors");
    }
}
');

        $discovery = new PluginDiscovery();
        $plugin = $discovery->loadFromFile($path);

        $this->assertNotNull($plugin);
        $this->assertSame('directplugin', $plugin->getIdentifier());
    }

    public function testScanEmptyDir(): void
    {
        $discovery = new PluginDiscovery();
        $plugins = $discovery->scan($this->tmpDir);
        $this->assertCount(0, $plugins);
    }
}
