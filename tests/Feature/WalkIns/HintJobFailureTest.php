<?php

use App\Modules\WalkIns\Jobs\EmitEntryHints;
use App\Modules\WalkIns\Jobs\EmitQueueUpdated;
use App\Modules\WalkIns\Jobs\PruneFailedHints;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/*
 * Increment 5.7-3 (M-1, L-3): a walk-in hint never reaches failed_jobs, and the daily prune deletes only old hint rows.
 */

beforeEach(function () {
    Queue::fake();
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    Cache::flush();
});

/** A row as Laravel writes it into failed_jobs: the displayName is the job or event class. */
function failed_row(string $displayName, string $failedAt): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid7(),
        'connection' => 'redis',
        'queue' => 'notifications',
        'payload' => json_encode(['displayName' => $displayName, 'data' => ['command' => 'profile-linked payload']]),
        'exception' => 'Connection refused',
        'failed_at' => $failedAt,
    ]);
}

it('drops a failing entry-hint job without rethrowing, and logs the job and class only', function () {
    // A malformed id makes the database read throw for real (no mock): the hint must be dropped, not retried into failed_jobs.
    Log::spy();

    expect(fn () => (new EmitEntryHints('not-a-uuid'))->handle(app(QueueRealtime::class)))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')->once()->with('walkins.realtime_hint_dropped', Mockery::on(fn (array $ctx) => $ctx['job'] === 'EmitEntryHints'
        && $ctx['queue_id'] === 'not-a-uuid' && $ctx['exception'] === QueryException::class));
});

it('drops a failing queue-hint job without rethrowing', function () {
    Log::spy();

    expect(fn () => (new EmitQueueUpdated('not-a-uuid'))->handle(app(QueueRealtime::class)))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')->once()->with('walkins.realtime_hint_dropped', Mockery::on(fn (array $ctx) => $ctx['job'] === 'EmitQueueUpdated'));
});

it('prunes only old walk-in hint rows from failed_jobs, and nothing else', function () {
    $old = now()->subHours(25)->toDateTimeString();
    $recent = now()->subHours(1)->toDateTimeString();
    failed_row('App\\Modules\\WalkIns\\Events\\WalkInEntryUpdated', $old);
    failed_row('App\\Modules\\WalkIns\\Jobs\\EmitEntryHints', $old);
    failed_row('App\\Modules\\WalkIns\\Events\\WalkInQueueUpdated', $recent);
    failed_row('App\\Modules\\Payments\\Jobs\\ProcessPaymentWebhook', $old);
    failed_row('App\\Modules\\Notifications\\Events\\SomethingElse', $old);

    (new PruneFailedHints)->handle(app(Clock::class));

    $left = DB::table('failed_jobs')->pluck('payload')->map(fn ($p) => json_decode($p, true)['displayName'])->all();
    expect($left)->toEqualCanonicalizing([
        'App\\Modules\\WalkIns\\Events\\WalkInQueueUpdated',
        'App\\Modules\\Payments\\Jobs\\ProcessPaymentWebhook',
        'App\\Modules\\Notifications\\Events\\SomethingElse',
    ]);
});

it('runs the failed-hint prune daily at 03:45 from the scheduler (MINOR-3)', function () {
    $events = collect(Schedule::events())->filter(fn ($e) => str_contains((string) $e->description, PruneFailedHints::class) || str_contains($e->getSummaryForDisplay(), 'PruneFailedHints'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('45 3 * * *');
});
