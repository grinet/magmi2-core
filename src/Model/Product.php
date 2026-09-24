<?php

declare(strict_types=1);

namespace Magmi\Core\Model;

/**
 * Simple data holder for a product being imported.
 * Plugins mutate this during the import pipeline.
 */
class Product
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function merge(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }
}
