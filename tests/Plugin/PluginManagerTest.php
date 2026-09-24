<?php

declare(strict_types=1);

namespace Magmi\Core\Tests\Plugin;

use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

class PluginManagerTest extends TestCase
{
    public function testRegisterAndEnable(): void
    {
        $manager = new PluginManager();
        $plugin = new class('test_plugin', 'Test', 'v1.0', 'itemprocessors') extends AbstractPlugin {};

        $manager->register($plugin);
        $this->assertFalse($manager->isEnabled('test_plugin'));

        $manager->enable('test_plugin');
        $this->assertTrue($manager->isEnabled('test_plugin'));
        $this->assertCount(1, $manager->getEnabled());
    }

    public function testGetByCategory(): void
    {
        $manager = new PluginManager();
        $plugin = new class('p1', 'P1', 'v1.0', 'itemprocessors') extends AbstractPlugin {};

        $manager->register($plugin);
        $this->assertCount(1, $manager->getByCategory('itemprocessors'));
        $this->assertCount(0, $manager->getByCategory('preprocessors'));
    }

    public function testCallExecutesHook(): void
    {
        $manager = new PluginManager();
        $plugin = new class('p1', 'P1', 'v1.0', 'itemprocessors') extends AbstractPlugin {
            public bool $called = false;
            public function beforeImport(array $context): bool
            {
                $this->called = true;
                return true;
            }
        };

        $manager->register($plugin);
        $manager->enable('p1');

        $result = $manager->call('itemprocessors', 'beforeImport', null, []);
        $this->assertTrue($result);
        $this->assertTrue($plugin->called);
    }

    public function testCallBreakOnFalse(): void
    {
        $manager = new PluginManager();
        $plugin1 = new class('p1', 'P1', 'v1.0', 'itemprocessors') extends AbstractPlugin {
            public function processItemBeforeId(array &$item, array $context): bool
            {
                return false;
            }
        };

        $manager->register($plugin1);
        $manager->enable('p1');

        $item = ['sku' => 'TEST'];
        $result = $manager->call('itemprocessors', 'processItemBeforeId', $item, []);
        $this->assertFalse($result);
    }
}
