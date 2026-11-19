<?php

namespace App\Modules\Clinics\Models;

use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Source of truth for clinic-scoped authorization (D-17).
 *
 * @property string $id
 * @property string $clinic_id
 * @property string|null $clinic_location_id
 * @property string $user_id
 * @property string $member_role
 * @property string $status
 * @property CarbonImmutable|null $joined_at
 * @property-read User $user
 */
class ClinicMember extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['joined_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }

    /**
     * Read-only convenience across modules (architecture.md §6.3 rule 2).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
