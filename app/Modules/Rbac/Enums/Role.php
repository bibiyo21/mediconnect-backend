<?php

namespace App\Modules\Rbac\Enums;

/**
 * Platform roles (CLAUDE.md §20.1). Roles are global labels; which clinics a
 * clinic role applies to is held in clinic_members (D-17), not in Spatie teams.
 */
enum Role: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case Admin = 'ADMIN';
    case MedicalAdmin = 'MEDICAL_ADMIN';
    case Doctor = 'DOCTOR';
    case Patient = 'PATIENT';
    case ClinicManager = 'CLINIC_MANAGER';
    case ClinicStaff = 'CLINIC_STAFF';
    case FinanceOfficer = 'FINANCE_OFFICER';
    case SupportAgent = 'SUPPORT_AGENT';
    case Moderator = 'MODERATOR';

    /** Roles that must use MFA (rbac-permissions.md §2). */
    public function requiresMfa(): bool
    {
        return in_array($this, [
            self::SuperAdmin, self::Admin, self::MedicalAdmin, self::ClinicManager,
            self::FinanceOfficer, self::SupportAgent, self::Moderator,
        ], true);
    }

    /**
     * Roles that may not be held together with this one (D-32: the grant
     * reviewer cannot also be a grant requester).
     *
     * @return list<self>
     */
    public function incompatibleWith(): array
    {
        return match ($this) {
            self::Admin => [self::SuperAdmin, self::MedicalAdmin],
            self::SuperAdmin, self::MedicalAdmin => [self::Admin],
            default => [],
        };
    }
}
