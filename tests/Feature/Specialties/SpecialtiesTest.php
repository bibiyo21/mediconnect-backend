<?php

use App\Modules\Rbac\Enums\Role;
use App\Modules\Specialties\Models\Specialty;
use App\Modules\Specialties\Services\SpecialtyCatalog;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

/** @return array<string, string> headers for a MEDICAL_ADMIN with MFA */
function medical_admin(): array
{
    app('auth')->forgetGuards();
    [, , $access] = enroll_mfa(account(Role::MedicalAdmin));

    return bearer($access);
}

it('seeds a common list pending medical review, without overwriting later edits', function () {
    $this->seed(SpecialtySeeder::class);
    $count = Specialty::count();
    expect($count)->toBeGreaterThan(20)
        ->and(Specialty::where('review_status', 'PENDING_REVIEW')->count())->toBe($count)
        ->and(Specialty::where('slug', 'obstetrics-and-gynecology')->sole()->synonyms)->toContain('OB-GYN');

    Specialty::where('slug', 'pediatrics')->update(['name' => 'Pediatrics (edited)']);
    $this->seed(SpecialtySeeder::class);
    expect(Specialty::count())->toBe($count)->and(Specialty::where('slug', 'pediatrics')->value('name'))->toBe('Pediatrics (edited)');
});

it('lists active specialties publicly, without sign-in, and caches them', function () {
    $this->seed(SpecialtySeeder::class);
    Specialty::where('slug', 'radiology')->update(['is_active' => false]);

    $response = $this->getJson('/api/v1/specialties')->assertOk();
    expect(collect($response->json('data'))->pluck('slug'))->toContain('cardiology')->not->toContain('radiology')
        ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'slug', 'synonyms', 'subspecialties'])
        ->and($response->json('data.0'))->not->toHaveKey('review_status')
        ->and(Cache::has(SpecialtyCatalog::CACHE_KEY))->toBeTrue();
});

it('lets a MEDICAL_ADMIN create, rename, mark reviewed and deactivate, clearing the public cache', function () {
    $this->seed(SpecialtySeeder::class);
    $headers = medical_admin();
    $this->getJson('/api/v1/specialties')->assertOk(); // warm the cache

    $created = $this->postJson('/api/v1/admin/specialties', ['name' => 'Sports Medicine', 'synonyms' => ['sports doctor']], $headers)
        ->assertCreated()->assertJsonPath('data.slug', 'sports-medicine')->assertJsonPath('data.review_status', 'REVIEWED');
    $id = $created->json('data.id');
    expect(collect($this->getJson('/api/v1/specialties')->json('data'))->pluck('name'))->toContain('Sports Medicine');

    $cardiology = Specialty::where('slug', 'cardiology')->value('id');
    $this->patchJson("/api/v1/admin/specialties/{$cardiology}", ['reviewed' => true, 'synonyms' => ['cardiologist', 'heart specialist']], $headers)
        ->assertOk()->assertJsonPath('data.review_status', 'REVIEWED')->assertJsonPath('data.synonyms', ['cardiologist', 'heart specialist']);

    $this->patchJson("/api/v1/admin/specialties/{$id}", ['is_active' => false], $headers)->assertOk();
    expect(collect($this->getJson('/api/v1/specialties')->json('data'))->pluck('name'))->not->toContain('Sports Medicine');

    expect(DB::table('audit_logs')->whereIn('action', ['specialty.created', 'specialty.updated'])->count())->toBe(3);
});

it('manages subspecialties and refuses duplicates within a specialty', function () {
    $this->seed(SpecialtySeeder::class);
    $headers = medical_admin();
    $cardiology = Specialty::where('slug', 'cardiology')->value('id');

    $this->postJson("/api/v1/admin/specialties/{$cardiology}/subspecialties", ['name' => 'Interventional Cardiology'], $headers)
        ->assertCreated()->assertJsonPath('data.subspecialties.0.name', 'Interventional Cardiology');
    $this->postJson("/api/v1/admin/specialties/{$cardiology}/subspecialties", ['name' => 'interventional cardiology'], $headers)
        ->assertStatus(422)->assertJsonValidationErrors('name', 'errors');

    $sub = DB::table('subspecialties')->value('id');
    $this->patchJson("/api/v1/admin/subspecialties/{$sub}", ['is_active' => false], $headers)->assertOk();
    $public = collect($this->getJson('/api/v1/specialties')->json('data'))->firstWhere('slug', 'cardiology');
    expect($public['subspecialties'])->toBe([]);
});

it('validates names and refuses duplicates', function () {
    $this->seed(SpecialtySeeder::class);
    $headers = medical_admin();

    $this->postJson('/api/v1/admin/specialties', ['name' => 'Cardiology'], $headers)->assertStatus(422)->assertJsonValidationErrors('name', 'errors');
    $this->postJson('/api/v1/admin/specialties', ['name' => 'X'], $headers)->assertStatus(422);
    $this->postJson('/api/v1/admin/specialties', ['name' => 'Valid Name', 'synonyms' => ['ab', 'AB']], $headers)->assertStatus(422);
});

it('refuses roles without specialties.manage, including ADMIN', function () {
    app('auth')->forgetGuards();
    [, , $adminAccess] = enroll_mfa(account(Role::Admin));
    $this->postJson('/api/v1/admin/specialties', ['name' => 'Sports Medicine'], bearer($adminAccess))->assertStatus(403);
    $this->getJson('/api/v1/admin/specialties', bearer($adminAccess))->assertStatus(403);
});

it('reads and writes synonyms with commas, quotes and spaces intact', function () {
    $headers = medical_admin();
    $synonyms = ['ear, nose and throat', 'the "ENT" doctor', 'plain'];

    $id = $this->postJson('/api/v1/admin/specialties', ['name' => 'Tricky Synonyms', 'synonyms' => $synonyms], $headers)->assertCreated()->json('data.id');

    expect(Specialty::findOrFail($id)->synonyms)->toBe($synonyms);
});
