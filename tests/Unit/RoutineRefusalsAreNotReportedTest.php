<?php

use App\Modules\Presence\Exceptions\ActiveConsultationBlocksChange;
use App\Modules\WalkIns\Exceptions\DoctorCheckedInBlocksClose;
use App\Modules\WalkIns\Exceptions\EntryAlreadyLive;
use App\Modules\WalkIns\Exceptions\QueueEmpty;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

/*
 * Increment 5.8-3 (review C3, m9): Laravel reports an exception unless its report() returns true (Handler::reportThrowable). A routine 409 must
 * return true, so a stale screen never raises a Sentry event. Every module exception that declares report() is checked, not a sample.
 */

/** @return list<class-string<Throwable>> every exception class under app/Modules/<Module>/Exceptions that declares its own report(). */
function routine_refusal_classes(): array
{
    $classes = [];
    foreach (glob(app_path('Modules/*/Exceptions/*.php')) ?: [] as $file) {
        $class = 'App\\Modules\\'.basename(dirname(dirname($file))).'\\Exceptions\\'.basename($file, '.php');
        if (! class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! $reflection->hasMethod('report') || $reflection->getMethod('report')->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $classes[] = $class;
    }

    return $classes;
}

it('every module refusal that declares report() answers true, so Laravel does not report it', function () {
    $classes = routine_refusal_classes();
    expect(count($classes))->toBeGreaterThanOrEqual(26);

    foreach ($classes as $class) {
        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        expect($instance->report())->toBeTrue($class);
    }
});

it('the named routine refusals answer report() with true', function () {
    $refusals = [
        ActiveConsultationBlocksChange::calledBeforeBreak(),
        ActiveConsultationBlocksChange::inProgress(),
        new DoctorCheckedInBlocksClose,
        new EntryAlreadyLive('entry'),
        new QueueEmpty,
    ];

    foreach ($refusals as $refusal) {
        expect($refusal->report())->toBeTrue();
    }
});

it('a routine 409 raises no logged message through the exception handler', function () {
    Event::fake([MessageLogged::class]);

    app(ExceptionHandler::class)->report(new QueueEmpty);

    Event::assertNotDispatched(MessageLogged::class);
});
