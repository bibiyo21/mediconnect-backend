<?php

namespace App\Modules\Scheduling\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Booking rules (architecture.md §8.1), resolved field by field: the most
 * specific policy in force that sets a value wins (service mode → doctor →
 * clinic location → platform). D-22 fields may stay null (not decided).
 */
final class SchedulingPolicies
{
    public const FIELDS = [
        'min_booking_notice_minutes', 'max_advance_booking_days', 'allow_same_day', 'reservation_hold_minutes',
        'reservation_max_extension_minutes', 'confirmation_hold_hours', 'late_grace_minutes', 'free_cancellation_hours',
        'noshow_refund_percent', 'doctor_cancel_refund_percent', 'availability_horizon_days',
    ];

    /** Must be set by the platform policy; the rest may be null until decided (D-22). */
    public const REQUIRED = ['min_booking_notice_minutes', 'max_advance_booking_days', 'allow_same_day', 'reservation_hold_minutes', 'reservation_max_extension_minutes', 'confirmation_hold_hours', 'availability_horizon_days'];

    /** @return array<string, int|bool|null> */
    public function resolve(string $doctorProfileId, ?string $clinicLocationId = null, ?string $serviceModeId = null, ?DateTimeInterface $at = null): array
    {
        $at ??= now();
        $scopes = array_filter([
            ['SERVICE_MODE', $serviceModeId],
            ['DOCTOR', $doctorProfileId],
            ['CLINIC_LOCATION', $clinicLocationId],
            ['PLATFORM', null],
        ], fn (array $s) => $s[0] === 'PLATFORM' || $s[1] !== null);

        $resolved = array_fill_keys(self::FIELDS, null);
        foreach ($scopes as [$type, $id]) {
            $row = DB::table('scheduling_policies')->where('scope_type', $type)
                ->when($id === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $id))
                ->where('effective_from', '<=', $at)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
                ->orderByDesc('effective_from')->first();
            if ($row === null) {
                continue;
            }
            foreach (self::FIELDS as $field) {
                if ($resolved[$field] === null && $row->{$field} !== null) {
                    $resolved[$field] = $field === 'allow_same_day' ? (bool) $row->{$field} : (int) $row->{$field};
                }
            }
        }
        foreach (self::REQUIRED as $field) {
            if ($resolved[$field] === null) {
                throw new LogicException("No platform scheduling policy sets {$field}; seed SchedulingPolicySeeder.");
            }
        }

        return $resolved;
    }
}
