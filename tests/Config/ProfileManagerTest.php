<?php

declare(strict_types=1);

namespace Magmi\Core\Tests\Config;

use Magmi\Core\Config\Profile;
use Magmi\Core\Config\ProfileManager;
use PHPUnit\Framework\TestCase;

class ProfileManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/magmi_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
    }

    public function testSaveAndLoad(): void
    {
        $manager = new ProfileManager($this->tmpDir);
        $profile = new Profile('Default');
        $profile->setDatasourceConfig(['type' => 'csv', 'file' => 'test.csv']);

        $manager->save($profile);
        $loaded = $manager->load('Default');

        $this->assertNotNull($loaded);
        $this->assertSame('Default', $loaded->getName());
        $this->assertSame('csv', $loaded->getDatasourceConfig()['type']);
    }

    public function testCopy(): void
    {
        $manager = new ProfileManager($this->tmpDir);
        $profile = new Profile('Default');
        $manager->save($profile);

        $copy = $manager->copy('Default', 'Copy');
        $this->assertSame('Copy', $copy->getName());
        $this->assertNotNull($manager->load('Copy'));
    }
    public function testGlobalSettingsAreNotProfiles(): void
    {
        $manager = new ProfileManager($this->tmpDir);
        $manager->save(new Profile('Default'));
        $manager->saveGlobalConfig(['db_host' => 'test']);
        self::assertCount(1, $manager->list());
        $this->expectException(\InvalidArgumentException::class);
        $manager->save(new Profile('global'));
    }
}
