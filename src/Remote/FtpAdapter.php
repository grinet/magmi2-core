<?php

declare(strict_types=1);

namespace Magmi\Core\Remote;

class FtpAdapter implements RemoteAdapterInterface
{
    private mixed $connection = null;
    private bool $passive = true;
    private bool $ssl = false;

    public function connect(array $config): void
    {
        $host = $config['host'] ?? '';
        $port = (int) ($config['port'] ?? 21);
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $this->passive = $config['passive'] ?? true;
        $this->ssl = $config['ssl'] ?? false;

        if ($this->ssl) {
            $this->connection = ftp_ssl_connect($host, $port, 30);
        } else {
            $this->connection = ftp_connect($host, $port, 30);
        }

        if (!$this->connection) {
            throw new \RuntimeException("Could not connect to FTP server {$host}:{$port}");
        }

        if (!ftp_login($this->connection, $user, $pass)) {
            throw new \RuntimeException('FTP login failed.');
        }

        if ($this->passive) {
            ftp_pasv($this->connection, true);
        }
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            ftp_close($this->connection);
            $this->connection = null;
        }
    }

    public function list(string $remotePath): array
    {
        if (!$this->connection) {
            throw new \RuntimeException('Not connected.');
        }
        $listing = ftp_nlist($this->connection, $remotePath);
        return $listing ?: [];
    }

    public function download(string $remotePath, string $localPath): void
    {
        if (!$this->connection) {
            throw new \RuntimeException('Not connected.');
        }
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!ftp_get($this->connection, $localPath, $remotePath, FTP_BINARY)) {
            throw new \RuntimeException("Failed to download {$remotePath}");
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        if (!$this->connection) {
            throw new \RuntimeException('Not connected.');
        }
        if (!ftp_put($this->connection, $remotePath, $localPath, FTP_BINARY)) {
            throw new \RuntimeException("Failed to upload to {$remotePath}");
        }
    }
}
