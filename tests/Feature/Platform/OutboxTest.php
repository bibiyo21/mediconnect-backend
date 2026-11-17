<?php

use App\Shared\Jobs\DispatchOutbox;
use App\Shared\Services\Outbox;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\TestDomainEvent;

beforeEach(function () {
    config(['outbox.events' => ['tests.something_happened' => TestDomainEvent::class]]);
    $this->received = [];
    Event::listen(TestDomainEvent::class, function (TestDomainEvent $e) {
        if ($e->note === 'explode') {
            throw new RuntimeException('listener failed password=hunter2');
        }
        $this->received[] = $e->id;
    });
});

it('stores the event only if the transaction commits', function () {
    try {
        DB::transaction(function () {
            app(Outbox::class)->record(new TestDomainEvent('rolled-back', 'x'));
            throw new RuntimeException('business rule failed');
        });
    } catch (RuntimeException) {
    }

    DB::transaction(fn () => app(Outbox::class)->record(new TestDomainEvent('kept', 'x')));

    expect(DB::table('outbox_events')->pluck('aggregate_id')->all())->toBe(['kept']);
});

it('delivers committed events to listeners and marks them dispatched', function () {
    DB::transaction(fn () => app(Outbox::class)->record(new TestDomainEvent('a1', 'hello')));
    app(Outbox::class)->relay();

    expect($this->received)->toContain('a1')
        ->and(DB::table('outbox_events')->whereNull('dispatched_at')->count())->toBe(0);
});

it('retries a failing event later without blocking the others', function () {
    Bus::fake([DispatchOutbox::class]); // relay only when the test says so
    app(Outbox::class)->record(new TestDomainEvent('bad', 'explode'));
    app(Outbox::class)->record(new TestDomainEvent('good', 'fine'));
    Bus::assertDispatched(DispatchOutbox::class);

    app(Outbox::class)->relay();

    $bad = DB::table('outbox_events')->where('aggregate_id', 'bad')->first();
    expect($this->received)->toBe(['good'])
        ->and($bad->dispatched_at)->toBeNull()
        ->and($bad->attempts)->toBe(1)
        ->and(strtotime($bad->available_at))->toBeGreaterThan(time())
        ->and($bad->last_error)->not->toContain('hunter2');
});

it('refuses unregistered event types', function () {
    config(['outbox.events' => []]);

    expect(fn () => app(Outbox::class)->record(new TestDomainEvent('x', 'y')))->toThrow(LogicException::class);
});
