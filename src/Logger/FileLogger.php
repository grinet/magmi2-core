<?php

declare(strict_types=1);

namespace Magmi\Core\Logger;

/**
 * Simple file-based logger compatible with Magmi's log format.
 */
class FileLogger implements LoggerInterface
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->logFile = $logFile;
    }

    public function log(string $message, string $type = 'info'): void
    {
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($type), $message);
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
