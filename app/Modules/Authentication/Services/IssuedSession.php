<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Models\UserDevice;
use Carbon\CarbonImmutable;

final readonly class IssuedSession
{
    public function __construct(
        public UserDevice $device,
        public bool $web,
        public bool $fullAccess,
        public ?string $accessToken = null,
        public ?CarbonImmutable $accessTokenExpiresAt = null,
        public ?string $refreshToken = null,
        public ?CarbonImmutable $refreshTokenExpiresAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'device_id' => $this->device->id,
            'mfa' => ['setup_required' => ! $this->fullAccess],
        ];

        if (! $this->web) {
            $data['tokens'] = [
                'token_type' => 'Bearer',
                'access_token' => $this->accessToken,
                'access_token_expires_at' => $this->accessTokenExpiresAt?->toIso8601ZuluString(),
                'refresh_token' => $this->refreshToken,
                'refresh_token_expires_at' => $this->refreshTokenExpiresAt?->toIso8601ZuluString(),
            ];
        }

        return $data;
    }
}
