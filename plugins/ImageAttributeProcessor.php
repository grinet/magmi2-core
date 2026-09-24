<?php

declare(strict_types=1);

namespace Magmi\Core\BuiltinPlugin;

use Magmi\Core\Plugin\AbstractPlugin;

/**
 * Example image attribute processor plugin for Magmi New Era.
 * Handles downloading and copying product images to Magento media directory.
 */
class ImageAttributeProcessor extends AbstractPlugin implements \Magmi\Core\Plugin\ConfigurablePluginInterface
{
    private array $imageCols = ['image', 'small_image', 'thumbnail', 'media_gallery'];

    public function __construct()
    {
        parent::__construct('imageattr', 'Image attributes processor', 'v2.0.0', 'itemprocessors');
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'media_dir',
                'label' => 'Magento Media Directory',
                'type' => 'text',
                'default' => '/var/www/magento/pub/media',
            ],
            [
                'name' => 'use_remote',
                'label' => 'Download remote images',
                'type' => 'checkbox',
                'default' => true,
            ],
        ];
    }

    public function processItemAfterId(array &$item, array $context): bool
    {
        $config = $this->getConfig();
        $mediaDir = $config['media_dir'] ?? $context['magento_media_dir'] ?? null;
        if (!$mediaDir && !empty($context['magento_path'])) {
            $mediaDir = rtrim($context['magento_path'], '/\\') . '/pub/media';
        }
        $useRemote = $config['use_remote'] ?? true;

        if (!$mediaDir || !is_dir($mediaDir)) {
            return true; // Skip if no media dir configured
        }


        $valueSeparator = $context['csv_value_separator'] ?? ',';
        $importDir = $context['import_dir'] ?? null;
        foreach ($this->imageCols as $col) {
            if (!isset($item[$col]) || $item[$col] === '') {
                continue;
            }

            $value = $item[$col];
            if ($col === 'media_gallery') {
                $images = array_map('trim', explode($valueSeparator, $value));
                $processed = [];
                foreach ($images as $img) {
                    $processed[] = $this->processImage($img, $mediaDir, $importDir, (bool) $useRemote);
                }
                $item[$col] = implode($valueSeparator, array_filter($processed));
            } else {
                $item[$col] = $this->processImage($value, $mediaDir, $importDir, (bool) $useRemote);
            }
        }

        return true;
    }

    private function processImage(string $source, string $mediaDir, ?string $importDir, bool $useRemote): string
    {
        if (is_file(rtrim($mediaDir, '/') . '/catalog/product/' . ltrim($source, '/'))) {
            return '/' . ltrim($source, '/');
        }
        if (!$useRemote && preg_match('#^https?://#i', $source)) {
            throw new \RuntimeException('Remote image downloads are disabled.');
        }
        // If already a Magento path (no protocol), return as-is
        if (!str_contains($source, '://') && !str_starts_with($source, '/')) {
            if ($importDir) {
                $localPath = rtrim($importDir, '/\\') . '/' . ltrim($source, '/\\');
                if (file_exists($localPath)) {
                    $source = $localPath;
                }
            }
            if (!str_contains($source, '://') && !str_starts_with($source, '/')) {
                throw new \RuntimeException('Image file not found: ' . $source);
            }
        }

        if (preg_match('#^https?://#i', $source)) {
            $content = @file_get_contents($source, false, stream_context_create(['http' => ['timeout' => 30]]));
        } elseif (is_file($source)) {
            $content = file_get_contents($source);
        } else {
            throw new \RuntimeException('Image file not found: ' . $source);
        }
        if ($content === false) {
            throw new \RuntimeException('Could not read image: ' . $source);
        }
        $info = @getimagesizefromstring($content);
        $extension = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/avif' => 'avif',
        ][$info['mime'] ?? ''] ?? null;
        if ($extension === null) {
            throw new \RuntimeException('Unsupported or invalid image: ' . $source);
        }
        // A content-derived name avoids overwriting unrelated images and executable extensions.
        $hash = hash('sha256', $content);
        $filename = $hash . '.' . $extension;
        $subdir = $hash[0] . '/' . $hash[1];
        $finalDir = rtrim($mediaDir, '/') . '/catalog/product/' . $subdir;
        if (!is_dir($finalDir) && !mkdir($finalDir, 0755, true) && !is_dir($finalDir)) {
            throw new \RuntimeException('Could not create image directory.');
        }
        $targetPath = $finalDir . '/' . $filename;
        if (!is_file($targetPath) && file_put_contents($targetPath, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write image: ' . $targetPath);
        }

        return '/' . $subdir . '/' . $filename;
    }
}
