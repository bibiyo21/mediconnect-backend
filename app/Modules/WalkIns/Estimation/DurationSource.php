<?php

namespace App\Modules\WalkIns\Estimation;

/** Where an expected consultation length came from, most to least trustworthy. */
enum DurationSource: string
{
    /** A trimmed mean of the doctor's recent completed consultations for the service. */
    case Rolling = 'ROLLING';
    /** The service's configured duration_minutes. */
    case Service = 'SERVICE';
    /** The configured default, when there is neither history nor a service. */
    case Default = 'DEFAULT';
}
