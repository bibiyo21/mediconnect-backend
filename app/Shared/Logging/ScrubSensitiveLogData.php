<?php

namespace App\Shared\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Log channel "tap": attaches the redaction processor (config/logging.php).
 */
final class ScrubSensitiveLogData
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new RedactSensitiveData);
        }
    }
}
