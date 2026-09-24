<?php

declare(strict_types=1);

namespace Magmi\Core\Datasource;

interface DatasourceInterface
{
    /**
     * Human-readable name (e.g. "CSV Datasource")
     */
    public function getName(): string;

    /**
     * Open the datasource for reading.
     */
    public function open(array $config): void;

    /**
     * Get column headers.
     *
     * @return string[]
     */
    public function getHeaders(): array;

    /**
     * Magmi-compatible alias for getHeaders().
     *
     * @return string[]
     */
    public function getColumnNames(): array;

    /**
     * Iterate over rows.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getRows(): \Generator;

    /**
     * Get next single record (Magmi-compatible).
     *
     * @return array<string, mixed>|null
     */
    public function getNextRecord(): ?array;

    /**
     * Get total record count if available.
     */
    public function getRecordsCount(): int;

    /**
     * Close the datasource.
     */
    public function close(): void;
}
