<?php

namespace App\Modules\Consultations\DTOs;

/**
 * What one user may read of consultations, computed once per request. `doctor` is the user's own doctor profile; `profiles` are their own
 * patient profile and the dependants they are authorised for; `locations` are the clinic locations where they hold consultations.view as
 * clinic staff; `global` is true when a global-scope role (ADMIN, SUPPORT_AGENT, MEDICAL_ADMIN) holds consultations.view.
 */
final readonly class ReadScope
{
    /**
     * @param  list<string>  $profiles
     * @param  list<string>  $locations
     */
    public function __construct(
        public ?string $doctor,
        public array $profiles,
        public array $locations,
        public bool $global,
    ) {}
}
