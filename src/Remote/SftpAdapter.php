<?php

declare(strict_types=1);

namespace Magmi\Core\Remote;

/**
 * SFTP adapter using phpseclib.
 * Requires "phpseclib/phpseclib" in consuming project.
 */
class SftpAdapter implements RemoteAdapterInterface
{
    private mixed $sftp = null;

    public function connect(array $config): void
    {
        $host = $config['host'] ?? '';
        $port = (int) ($config['port'] ?? 22);
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $keyFile = $config['private_key'] ?? '';

        if (!class_exists('phpseclib\Net\SFTP')) {
            throw new \RuntimeException('phpseclib is required. Run: composer require phpseclib/phpseclib');
        }

        $this->sftp = new \phpseclib\Net\SFTP($host, $port);

        if ($keyFile && file_exists($keyFile)) {
            $key = new \phpseclib\Crypt\RSA();
            $key->loadKey(file_get_contents($keyFile));
            if (!$this->sftp->login($user, $key)) {
                throw new \RuntimeException('SFTP key authentication failed.');
            }
        } else {
            if (!$this->sftp->login($user, $pass)) {
                throw new \RuntimeException('SFTP password authentication failed.');
            }
        }
    }

    public function disconnect(): void
    {
        if ($this->sftp) {
            $this->sftp = null;
        }
    }

    public function list(string $remotePath): array
    {
        if (!$this->sftp) {
            throw new \RuntimeException('Not connected.');
        }
        $raw = $this->sftp->nlist($remotePath);
        if ($raw === false) {
            return [];
        }
        // Remove . and ..
        return array_values(array_filter($raw, fn($f) => $f !== '.' && $f !== '..'));
    }

    public function download(string $remotePath, string $localPath): void
    {
        if (!$this->sftp) {
            throw new \RuntimeException('Not connected.');
        }
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!$this->sftp->get($remotePath, $localPath)) {
            throw new \RuntimeException("Failed to download {$remotePath}");
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        if (!$this->sftp) {
            throw new \RuntimeException('Not connected.');
        }
        if (!$this->sftp->put($remotePath, file_get_contents($localPath))) {
            throw new \RuntimeException("Failed to upload to {$remotePath}");
        }
    }
}
