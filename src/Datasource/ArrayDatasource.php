<?php

declare(strict_types=1);

namespace Magmi\Core\Datasource;

/**
 * Datasource backed by an in-memory array of records.
 * Useful for Magmi-compatible DataPump ingestion.
 */
class ArrayDatasource implements DatasourceInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $records = [];
    /** @var string[] */
    private array $headers = [];
    private int $position = 0;

    public function getName(): string
    {
        return 'Array Datasource';
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function setRecords(array $records): void
    {
        $this->records = $records;
        $this->position = 0;
        if (!empty($records)) {
            $this->headers = array_keys($records[0]);
        }
    }

    public function open(array $config): void
    {
        $this->records = $config['records'] ?? [];
        $this->position = 0;
        if (!empty($this->records)) {
            $this->headers = array_keys($this->records[0]);
        }
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getColumnNames(): array
    {
        return $this->headers;
    }

    public function getRows(): \Generator
    {
        foreach ($this->records as $row) {
            yield $row;
        }
    }

    public function getNextRecord(): ?array
    {
        if (!isset($this->records[$this->position])) {
            return null;
        }

        $record = $this->records[$this->position];
        $this->position++;
        return $record;
    }

    public function getRecordsCount(): int
    {
        return count($this->records);
    }

    public function close(): void
    {
        $this->records = [];
        $this->headers = [];
        $this->position = 0;
    }
}
