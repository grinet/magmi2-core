<?php

declare(strict_types=1);

namespace Magmi\Core\Import;

interface DataPumpInterface
{
    /**
     * Start a new import session.
     *
     * @param string $profileName Profile name to load configuration from
     * @param string $mode        Import mode: 'create', 'update', or 'xcreate' (create/update)
     */
    public function beginImportSession(string $profileName, string $mode): void;

    /**
     * Process a single record immediately. Returns true on success, false on skip or failure.
     *
     * @param array<string, mixed> $record
     */
    public function ingest(array $record): bool;

    /**
     * Finalize the import session and return the accumulated report.
     *
     * @return array<string, mixed> Import report
     */
    public function endImportSession(): array;
}
