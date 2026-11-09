<?php

declare(strict_types=1);

namespace Magmi\Core\Config;

class ProfileManager
{
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = rtrim($storagePath, '/\\');
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    public function save(Profile $profile): void
    {
        $file = $this->storagePath . '/' . $this->sanitize($profile->getName()) . '.json';
        file_put_contents($file, json_encode($profile->toArray(), JSON_PRETTY_PRINT));
    }

    public function load(string $name): ?Profile
    {
        $file = $this->storagePath . '/' . $this->sanitize($name) . '.json';
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return Profile::fromArray($data);
    }

    /**
     * @return Profile[]
     */
    public function list(): array
    {
        $profiles = [];
        foreach (glob($this->storagePath . '/*.json') as $file) {
            if (basename($file) === 'global.json') {
                continue;
            }
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $profiles[] = Profile::fromArray($data);
            }
        }
        return $profiles;
    }

    public function copy(string $fromName, string $toName): Profile
    {
        $profile = $this->load($fromName);
        if (!$profile) {
            throw new \InvalidArgumentException("Profile '{$fromName}' not found.");
        }
        $new = Profile::fromArray($profile->toArray());
        // Reflection to set private name
        $ref = new \ReflectionClass($new);
        $prop = $ref->getProperty('name');
        $prop->setValue($new, $toName);
        $this->save($new);
        return $new;
    }

    public function delete(string $name): void
    {
        $file = $this->storagePath . '/' . $this->sanitize($name) . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function getGlobalConfig(): array
    {
        $file = $this->storagePath . '/global.json';
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?? [];
    }

    public function saveGlobalConfig(array $config): void
    {
        $file = $this->storagePath . '/global.json';
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT));
    }

    private function sanitize(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        if ($safe === '' || strtolower($safe) === 'global') {
            throw new \InvalidArgumentException('Profile name is empty or reserved.');
        }
        return $safe;
    }
}
