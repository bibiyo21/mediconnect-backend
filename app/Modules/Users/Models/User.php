<?php

namespace App\Modules\Users\Models;

use App\Modules\Users\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An account. Roles are not exclusive (§4.2): one user may hold a patient
 * profile, a doctor profile and staff roles at the same time.
 *
 * @property string $id
 * @property string|null $email
 * @property string|null $mobile_e164
 * @property UserStatus $status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = ['email', 'mobile_e164', 'password', 'locale'];

    protected $hidden = ['password'];

    protected $attributes = ['status' => 'ACTIVE', 'locale' => 'en'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'mobile_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return HasMany<UserAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
