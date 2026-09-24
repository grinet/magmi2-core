<?php

declare(strict_types=1);

namespace Magmi\Core\Logger;

interface LoggerInterface
{
    public function log(string $message, string $type = 'info'): void;
}
