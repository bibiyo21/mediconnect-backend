<?php

namespace App\Shared\Http;

/**
 * Names of request attributes that modules use to pass facts about the
 * current request to each other without depending on each other's code.
 */
final class RequestAttributes
{
    /** true when the request passed a fresh MFA step-up (set by Authentication, read by Audit). */
    public const STEP_UP_VERIFIED = 'mediconnect.step_up_verified';
}
