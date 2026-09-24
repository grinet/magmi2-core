<?php

declare(strict_types=1);

namespace Magmi\Core\Database;

/**
 * Magento-compatible database helper.
 * Wraps PDO with prepared-statement caching and Magmi-compatible shortcuts.
 */
class DbHelper
{
    private ?\PDO $pdo = null;
    private DbConfig $config;
    private bool $useStmtCache = true;
    /** @var array<string, array{0: \PDOStatement, 1: int}> */
    private array $prepared = [];
    private int $requestCount = 0;
    private float $totalDbTime = 0.0;
    /** @var array<string, bool> */
    private array $columnCache = [];
    private bool $debugLogging = false;
    /** @var array<int, array{sql: string, params: array}> */
    private array $debugStatements = [];
    /** @var callable|null */
    private $debugHook = null;

    public function __construct(DbConfig $config)
    {
        $this->config = $config;
    }

    /** Reuse the host's connection without copying its credentials. */
    public static function fromPdo(\PDO $pdo, string $tablePrefix = ''): self
    {
        $helper = new self(DbConfig::fromArray(['table_prefix' => $tablePrefix]));
        $helper->pdo = $pdo;
        return $helper;
    }

    public function connect(): void
    {
        $dsn = $this->config->getDsn();
        $this->pdo = new \PDO(
            $dsn,
            $this->config->getUser(),
            $this->config->getPassword(),
            [
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->prepared = [];
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function getPdo(): \PDO
    {
        if (!$this->pdo) {
            throw new \RuntimeException('Database not connected.');
        }
        return $this->pdo;
    }

    /**
     * Magmi-compatible table name with prefix.
     */
    public function tableName(string $name): string
    {
        return $this->config->getTablePrefix() . $name;
    }

    public function useStmtCache(bool $use): void
    {
        $this->useStmtCache = $use;
    }

    /**
     * Execute a prepared statement with optional caching.
     *
     * @param array<int|string, mixed>|null $params
     */
    public function execStmt(string $sql, ?array $params = null, bool $close = true): \PDOStatement
    {
        $t0 = microtime(true);
        $this->requestCount++;

        $stmt = $this->prepare($sql);

        if ($this->debugLogging) {
            $this->storeDebugStatement($sql, $params);
        }

        if ($params !== null) {
            $stmt->execute($this->normalizeParams($params));
        } else {
            $stmt->execute();
        }

        if ($close) {
            $stmt->closeCursor();
        }

        $this->totalDbTime += microtime(true) - $t0;
        return $stmt;
    }

    /**
     * Magmi-compatible DELETE shortcut.
     *
     * @param array<int|string, mixed>|null $params
     */
    public function delete(string $sql, ?array $params = null): int
    {
        return $this->execStmt($sql, $params)->rowCount();
    }

    /**
     * Magmi-compatible UPDATE shortcut.
     *
     * @param array<int|string, mixed>|null $params
     */
    public function update(string $sql, ?array $params = null): int
    {
        return $this->execStmt($sql, $params)->rowCount();
    }

    /**
     * Magmi-compatible INSERT shortcut.
     *
     * @param array<int|string, mixed>|null $params
     * @return string|false
     */
    public function insert(string $sql, ?array $params = null): string|false
    {
        $this->execStmt($sql, $params);
        return $this->pdo->lastInsertId();
    }

    /**
     * Magmi-compatible SELECT shortcut (returns PDOStatement).
     *
     * @param array<int|string, mixed>|null $params
     */
    public function select(string $sql, ?array $params = null): \PDOStatement
    {
        return $this->execStmt($sql, $params, false);
    }

    public function enableDebugLogging(bool $enable, ?callable $hook = null, bool $reset = true): void
    {
        $this->debugLogging = $enable;
        $this->debugHook = $hook;
        if ($reset) {
            $this->debugStatements = [];
        }
    }

    /**
     * @return array<int, array{sql: string, params: array}>
     */
    public function getDebugStatements(): array
    {
        return $this->debugStatements;
    }

    public function columnExists(string $table, string $column): bool
    {
        $key = $table . ':' . $column;
        if (!array_key_exists($key, $this->columnCache)) {
            $sql = "SHOW COLUMNS FROM {$table} LIKE ?";
            $stmt = $this->select($sql, [$column]);
            $exists = $stmt->rowCount() > 0;
            $stmt->closeCursor();
            $this->columnCache[$key] = $exists;
        }
        return $this->columnCache[$key];
    }

    /**
     * Magmi-compatible selectone: single value from first row.
     *
     * @param array<int|string, mixed>|null $params
     */
    public function selectOne(string $sql, ?array $params, string $column): mixed
    {
        $stmt = $this->select($sql, $params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return is_array($row) ? $row[$column] : null;
    }

    /**
     * Magmi-compatible selectAll: all rows as array.
     *
     * @param array<int|string, mixed>|null $params
     * @return array<int, array<string, mixed>>
     */
    public function selectAll(string $sql, ?array $params = null): array
    {
        $stmt = $this->select($sql, $params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getTotalDbTime(): float
    {
        return $this->totalDbTime;
    }

    private function prepare(string $sql): \PDOStatement
    {
        if ($this->useStmtCache && !str_contains($sql, "'")) {
            if (!isset($this->prepared[$sql])) {
                $this->garbageStmtCache();
                $this->prepared[$sql] = [$this->pdo->prepare($sql), 1];
            } else {
                $this->prepared[$sql][1]++;
            }
            return $this->prepared[$sql][0];
        }
        return $this->pdo->prepare($sql);
    }

    private function garbageStmtCache(): void
    {
        if (count($this->prepared) >= 500) {
            uasort($this->prepared, fn(array $a, array $b): int => $b[1] <=> $a[1]);
            $this->prepared = array_slice($this->prepared, 0, 350, true);
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int|string, mixed>
     */
    private function normalizeParams(array $params): array
    {
        // If params have string keys, bind as named parameters
        if ($this->isAssoc($params)) {
            $named = [];
            foreach ($params as $key => $val) {
                $named[':' . ltrim((string) $key, ':')] = $val;
            }
            return $named;
        }
        return $params;
    }

    /**
     * @param array<int|string, mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function storeDebugStatement(string $sql, ?array $params): void
    {
        $entry = [
            'sql' => $sql,
            'params' => $params ?? [],
        ];
        $this->debugStatements[] = $entry;
        if ($this->debugHook !== null) {
            try {
                ($this->debugHook)($entry);
            } catch (\Throwable) {
                // ignore debug hook failures
            }
        }
    }
}
