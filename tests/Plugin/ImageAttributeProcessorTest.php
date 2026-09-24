<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/plugins/ImageAttributeProcessor.php';

final class ImageAttributeProcessorTest extends TestCase
{
    private string $dir;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/magmi-image-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }
    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->dir);
    }
    public function testLocalImageCopiesWhenRemoteDownloadsAreDisabled(): void
    {
        $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6UAAAAABJRU5ErkJggg==');
        file_put_contents($this->dir . '/source.php', $content);
        $plugin = new \Magmi\Core\BuiltinPlugin\ImageAttributeProcessor();
        $plugin->setConfig(['media_dir' => $this->dir, 'use_remote' => false]);
        $item = ['image' => $this->dir . '/source.php'];
        self::assertTrue($plugin->processItemAfterId($item, []));
        self::assertStringEndsWith('.png', $item['image']);
        self::assertSame($content, file_get_contents($this->dir . '/catalog/product' . $item['image']));
        $original = $item['image'];
        $plugin->processItemAfterId($item, []);
        self::assertSame($original, $item['image']);
    }
    public function testNonImageCannotBePublished(): void
    {
        file_put_contents($this->dir . '/source.php', '<?php echo "not an image";');
        $plugin = new \Magmi\Core\BuiltinPlugin\ImageAttributeProcessor();
        $plugin->setConfig(['media_dir' => $this->dir]);
        $item = ['image' => $this->dir . '/source.php'];
        $this->expectException(RuntimeException::class);
        $plugin->processItemAfterId($item, []);
    }
}
