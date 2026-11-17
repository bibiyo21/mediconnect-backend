<?php

namespace App\Modules\Users\Models;

use App\Shared\Enums\Sex;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personal details of the account holder.
 *
 * @property string $user_id
 */
class UserProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'sex',
        'profile_photo_path', 'preferred_language',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'sex' => Sex::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
