<?php

namespace App\Modules\Patients\Models;

use App\Modules\Patients\Enums\PatientStatus;
use App\Modules\Users\Models\User;
use App\Shared\Enums\Sex;
use Carbon\CarbonImmutable;
use Database\Factories\PatientProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who receives care. user_id is null for a dependent without a login
 * of their own, so the person's identity fields live here (C-6).
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property CarbonImmutable|null $date_of_birth
 * @property Sex|null $sex
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property PatientStatus $status
 */
class PatientProfile extends Model
{
    /** @use HasFactory<PatientProfileFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'sex',
        'emergency_contact_name', 'emergency_contact_phone',
    ];

    protected $attributes = ['status' => 'ACTIVE'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'sex' => Sex::class,
            'status' => PatientStatus::class,
        ];
    }

    protected static function newFactory(): PatientProfileFactory
    {
        return PatientProfileFactory::new();
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
}
