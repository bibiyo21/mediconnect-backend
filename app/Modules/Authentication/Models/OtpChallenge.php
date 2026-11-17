<?php

namespace App\Modules\Authentication\Models;

use App\Modules\Authentication\Enums\OtpPurpose;
use App\Modules\Authentication\Enums\OtpStatus;
use App\Shared\Enums\DeliveryChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail of a one-time code. The code itself is never stored here; its
 * keyed hash lives in Redis until it expires.
 *
 * @property string $public_id
 * @property string|null $user_id
 * @property DeliveryChannel $channel
 * @property OtpPurpose $purpose
 * @property OtpStatus $status
 * @property int $attempts
 * @property string $destination_hash
 * @property string $destination_masked
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $verification_token_expires_at
 */
class OtpChallenge extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => DeliveryChannel::class,
            'purpose' => OtpPurpose::class,
            'status' => OtpStatus::class,
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'verification_token_expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
