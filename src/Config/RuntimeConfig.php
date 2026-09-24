<?php

declare(strict_types=1);
namespace Magmi\Core\Config;

use Magmi\Core\Database\DbHelper;

/** Host-provided paths and connection; no framework or GUI dependency. */
final class RuntimeConfig
{
    public function __construct(
        public readonly string $basePath,
        public readonly ?DbHelper $database = null,
        public readonly array $context = [],
    ) {
        if ($basePath === '' || !str_starts_with($basePath, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Runtime basePath must be an absolute path.');
        }
    }

    public function configDirectory(): string { return rtrim($this->basePath, '/\\') . '/data/config'; }
    public function pluginDirectory(): string { return rtrim($this->basePath, '/\\') . '/plugins'; }
}
