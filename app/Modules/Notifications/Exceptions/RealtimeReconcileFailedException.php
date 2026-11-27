<?php

namespace App\Modules\Notifications\Exceptions;

use RuntimeException;

/** Reported (never thrown) when the realtime sweep has been degraded or blind for several runs; fixed message so Sentry groups it. */
final class RealtimeReconcileFailedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Realtime reconciliation sweep degraded or unavailable (REALTIME_RECONCILE_FAILED)');
    }
}
