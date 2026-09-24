<?php

declare(strict_types=1);

namespace Magmi\Core\Database;

/**
 * Magento database configuration.
 * Supports direct config, local.xml (M1), and env.php (M2) modes.
 */
class DbConfig
{
    private string $host = 'localhost';
    private int $port = 3306;
    private string $dbname = 'magento';
    private string $user = '';
    private string $password = '';
    private string $tablePrefix = '';
    private string $connectivity = 'net';
    private ?string $unixSocket = null;
    private ?string $magentoDir = null;

    public static function fromArray(array $config): self
    {
        $instance = new self();
        $instance->host = $config['host'] ?? 'localhost';
        $instance->port = (int) ($config['port'] ?? 3306);
        $instance->dbname = $config['dbname'] ?? 'magento';
        $instance->user = $config['user'] ?? '';
        $instance->password = $config['password'] ?? '';
        $instance->tablePrefix = $config['table_prefix'] ?? '';
        $instance->connectivity = $config['connectivity'] ?? 'net';
        $instance->unixSocket = $config['unix_socket'] ?? null;
        $instance->magentoDir = $config['magento_dir'] ?? null;
        return $instance;
    }

    public static function fromLocalXml(string $baseDir): self
    {
        $xmlPath = $baseDir . '/app/etc/local.xml';
        if (!file_exists($xmlPath)) {
            throw new \RuntimeException("Cannot load local.xml from path: {$xmlPath}");
        }
        $xml = new \SimpleXMLElement(file_get_contents($xmlPath));
        $default_setup = $xml->global->resources->default_setup->connection;

        $instance = new self();
        $instance->host = (string) $default_setup->host;
        $instance->dbname = (string) $default_setup->dbname;
        $instance->user = (string) $default_setup->username;
        $instance->password = (string) $default_setup->password;
        $instance->port = (int) ((string) $default_setup->port ?: 3306);
        return $instance;
    }

    public static function fromEnvPhp(string $baseDir): self
    {
        $envPath = $baseDir . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            throw new \RuntimeException("Cannot load env.php from path: {$envPath}");
        }
        $env = include $envPath;
        $db = $env['db']['connection']['default'] ?? [];
        $hostInfo = explode(':', $db['host'] ?? 'localhost');

        $instance = new self();
        $instance->host = $hostInfo[0];
        $instance->port = isset($hostInfo[1]) && $hostInfo[1] > 0 ? (int) $hostInfo[1] : 3306;
        $instance->dbname = $db['dbname'] ?? 'magento';
        $instance->user = $db['username'] ?? '';
        $instance->password = $db['password'] ?? '';
        $instance->tablePrefix = $env['db']['table_prefix'] ?? '';
        $instance->magentoDir = $baseDir;
        return $instance;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getDbname(): string
    {
        return $this->dbname;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function getConnectivity(): string
    {
        return $this->connectivity;
    }

    public function getUnixSocket(): ?string
    {
        return $this->unixSocket;
    }

    public function getDsn(): string
    {
        if ($this->connectivity === 'socket' && $this->unixSocket) {
            return "mysql:unix_socket={$this->unixSocket};dbname={$this->dbname};charset=utf8mb4";
        }
        return "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8mb4";
    }
}
