<?php

use App\Modules\Notifications\Realtime\ChannelKind;
use App\Modules\Notifications\Realtime\Channels;
use Illuminate\Support\Facades\Broadcast;

$id = '018f1c3e-7b1a-7000-8000-000000000001';

it('parses each registered presence channel into its kind and id', function () use ($id) {
    expect(Channels::parse("presence-user.{$id}")?->kind)->toBe(ChannelKind::User)
        ->and(Channels::parse("presence-doctor.{$id}")?->kind)->toBe(ChannelKind::Doctor)
        ->and(Channels::parse("presence-clinic.{$id}")?->kind)->toBe(ChannelKind::Clinic)
        ->and(Channels::parse("presence-clinic.{$id}.queue")?->kind)->toBe(ChannelKind::ClinicQueue)
        ->and(Channels::parse("presence-clinic.{$id}.queue")?->id)->toBe($id);
});

it('refuses names that are not one of ours', function (string $name) {
    expect(Channels::parse($name))->toBeNull();
})->with([
    'private spelling' => fn () => 'private-user.018f1c3e-7b1a-7000-8000-000000000001',
    'no prefix' => fn () => 'user.018f1c3e-7b1a-7000-8000-000000000001',
    'not a uuid' => fn () => 'presence-user.42',
    'unknown name' => fn () => 'presence-billing.018f1c3e-7b1a-7000-8000-000000000001',
    'extra segment' => fn () => 'presence-clinic.018f1c3e-7b1a-7000-8000-000000000001.queue.x',
    'empty id' => fn () => 'presence-user.',
    'user suffix' => fn () => 'presence-user.018f1c3e-7b1a-7000-8000-000000000001.queue',
]);

it('has a parser entry for every pattern registered in routes/channels.php, and no stale entry', function () {
    $registered = Broadcast::driver()->getChannels()->keys()->map(fn ($pattern) => (string) $pattern)->sort()->values()->all();
    $parsed = collect(array_keys(Channels::PATTERNS))->sort()->values()->all();

    expect($registered)->not->toBe([])->and($parsed)->toBe($registered);
});
