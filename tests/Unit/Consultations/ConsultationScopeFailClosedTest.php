<?php

use App\Modules\Consultations\DTOs\ReadScope;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationScope;

/*
 * Increment 5.8-N (HAG-30): ConsultationScope::applyTo fails closed. For each single-branch scope the generated SQL keeps `1 = 0` inside
 * the OR group, so a branch that is later emptied can never widen the read. No database is touched: the SQL is compared as text.
 */

it('a doctor-only scope keeps 1 = 0 inside the OR group and matches on the doctor column', function () {
    $sql = app(ConsultationScope::class)->applyTo(Consultation::query(), new ReadScope('doc-1', [], [], false))->toSql();

    expect($sql)->toMatch('/\(1 = 0 or "doctor_profile_id" = \?\)/i');
});

it('a patient-only scope keeps 1 = 0 inside the OR group and matches on the patient column', function () {
    $sql = app(ConsultationScope::class)->applyTo(Consultation::query(), new ReadScope(null, ['pat-1'], [], false))->toSql();

    expect($sql)->toMatch('/\(1 = 0 or "patient_profile_id" in \(\?\)\)/i');
});

it('a location-only scope keeps 1 = 0 inside the OR group and matches on the clinic location column', function () {
    $sql = app(ConsultationScope::class)->applyTo(Consultation::query(), new ReadScope(null, [], ['loc-1'], false))->toSql();

    expect($sql)->toMatch('/\(1 = 0 or "clinic_location_id" in \(\?\)\)/i');
});

it('a scope with no branch at all reads nothing (1 = 0 alone)', function () {
    $sql = app(ConsultationScope::class)->applyTo(Consultation::query(), new ReadScope(null, [], [], false))->toSql();

    expect($sql)->toContain('where 1 = 0');
});

it('a global reader has no restriction on the query', function () {
    $sql = app(ConsultationScope::class)->applyTo(Consultation::query(), new ReadScope(null, [], [], true))->toSql();

    expect($sql)->not->toContain('1 = 0')->and($sql)->not->toContain('where');
});
