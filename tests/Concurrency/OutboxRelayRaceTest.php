<?php

use App\Shared\Jobs\DispatchOutbox;
use App\Shared\Services\Outbox;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\TestDomainEvent;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toEndWith('_test');
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    config(['outbox.events' => ['tests.something_happened' => TestDomainEvent::class]]);
    Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
    DB::purge('pgsql_second');
});

afterEach(fn () => Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]));

it('lets two relays run at once without dispatching the same event twice or waiting', function () {
    $received = [];
    Event::listen(TestDomainEvent::class, function (TestDomainEvent $e) use (&$received) {
        $received[] = $e->id;
    });
    Bus::fake([DispatchOutbox::class]); // relay only when the test says so

    app(Outbox::class)->record(new TestDomainEvent('one', 'x'));
    app(Outbox::class)->record(new TestDomainEvent('two', 'x'));

    // Another relay has claimed event "one" and is still working on it.
    $other = DB::connection('pgsql_second');
    $other->beginTransaction();
    $other->table('outbox_events')->where('aggregate_id', 'one')->lockForUpdate()->first();

    DB::statement("SET lock_timeout = '300ms'");
    $dispatched = app(Outbox::class)->relay(); // must skip the locked row, not wait for it
    DB::statement('RESET lock_timeout');
    $other->rollBack();

    expect($dispatched)->toBe(1)->and($received)->toBe(['two']);
});
