<?php

declare(strict_types=1);

namespace Magmi\Core\Logger;

/**
 * In-memory logger for collecting logs during import.
 */
class MemoryLogger implements LoggerInterface
{
    /** @var array<int, array{time: string, type: string, message: string}> */
    private array $logs = [];

    public function log(string $message, string $type = 'info'): void
    {
        $this->logs[] = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
        ];
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function clear(): void
    {
        $this->logs = [];
    }
}
