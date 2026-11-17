<?php

namespace App\Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * TOTP factor. The secret is encrypted with the application key; recovery
 * codes are stored only as hashes.
 *
 * @property string $user_id
 * @property string $secret_encrypted
 * @property list<string>|null $recovery_codes_hashed
 * @property int|null $last_used_timestep
 */
class MfaFactor extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['secret_encrypted', 'recovery_codes_hashed'];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'recovery_codes_hashed' => 'array',
            'confirmed_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'last_used_timestep' => 'integer',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
