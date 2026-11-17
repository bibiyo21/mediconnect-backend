<?php

namespace App\Shared\Logging;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Logger as MonologLogger;

/**
 * Laravel builds its "emergency" fallback logger (used when a configured
 * channel fails) without applying channel taps. This makes sure that logger
 * is scrubbed as well, so a logging failure cannot leak secrets.
 */
final class ScrubbingLogManager extends LogManager
{
    protected function createEmergencyLogger()
    {
        $logger = parent::createEmergencyLogger();

        if ($logger instanceof Logger && $logger->getLogger() instanceof MonologLogger) {
            $logger->getLogger()->pushProcessor(new RedactSensitiveData);
        }

        return $logger;
    }
}
