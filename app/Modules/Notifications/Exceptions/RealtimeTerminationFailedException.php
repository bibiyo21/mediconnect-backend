<?php

namespace App\Modules\Notifications\Exceptions;

use RuntimeException;

/**
 * Reported (never thrown) when a WebSocket termination could not be completed, so Sentry receives one
 * alertable issue with a fixed message and groups every occurrence together. Ids and the reason are
 * in the log and the REALTIME_TERMINATION_FAILED security event, never in this message.
 */
final class RealtimeTerminationFailedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Realtime connection termination failed (REALTIME_TERMINATION_FAILED)');
    }
}
