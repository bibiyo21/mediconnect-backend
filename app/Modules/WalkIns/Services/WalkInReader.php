<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\DTOs\EntryView;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\ViewLevel;
use App\Modules\WalkIns\Estimation\WaitEstimate;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\Contracts\Clock;
use App\Shared\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads of walk-in queues (increment 5.5a, decision 295): the staff board, a patient's own entries, one entry, and the
 * staff dedupe search. Minimum necessary access: names for the location's staff and the queue's doctor, phones only in the
 * staff detail of one entry, a restricted view for global-scope holders. Reads that expose a guest's name or phone, every
 * restricted read and every search are audited with ids, a type and a count; never the term or the values.
 */
final class WalkInReader
{
    /** Set by a name search that stopped at the row cap: the result may be incomplete. */
    private bool $truncated = false;

    private const LIVE_ORDER = [EntryStatus::InConsultation, EntryStatus::Called];

    public function __construct(
        private readonly WalkInAccess $access,
        private readonly PatientAccess $patients,
        private readonly EntryPresenter $presenter,
        private readonly RecordsAudit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * The staff board of a location for one local date: each queue with its live entries in call order.
     *
     * @return array{location: ClinicLocation, local_date: string, restricted: bool, queues: list<array{queue: WalkInQueue, doctor_name: string|null, live_count: int, entries: list<EntryView>}>}
     */
    public function board(User $user, ClinicLocation $location, ?string $doctorId, ?string $date): array
    {
        $general = $this->access->viewLevel($user, $location->id, null);
        $ownDoctor = $this->access->ownDoctorId($user);
        // A doctor with no staff rights reads the board of a location they are assigned to, and sees only their own queue.
        abort_if($general === ViewLevel::None && ($ownDoctor === null || ! $this->access->doctorRoleHolds($user, 'walkins.view') || ! DoctorClinicAssignment::activeAt($ownDoctor, $location->id)), 403, 'You do not have access to this location.');

        $today = $this->clock->now()->setTimezone($location->timezone)->toDateString();
        $date ??= $today;
        $queues = WalkInQueue::query()->where('clinic_location_id', $location->id)->whereDate('local_date', $date)
            ->when($doctorId !== null, fn ($q) => $q->where('doctor_profile_id', $doctorId))->orderBy('number_prefix')->get();

        $doctors = DoctorProfile::query()->whereIn('id', $queues->pluck('doctor_profile_id')->filter()->unique())->pluck('display_name', 'id');
        $levels = [];
        foreach ($queues as $queue) {
            $level = $this->access->viewLevel($user, $location->id, $queue->doctor_profile_id);
            if ($level !== ViewLevel::None) {
                $levels[$queue->id] = $level;
            }
        }
        // One query for every visible queue's live entries, one presentation per audience: the cost does not grow with the queue count except for each queue's schedule facts.
        $live = array_map(fn (EntryStatus $s) => $s->value, [...EntryStatus::live(), EntryStatus::Skipped]);
        $byQueue = $levels === [] ? collect() : WalkInQueueEntry::query()->whereIn('walkin_queue_id', array_keys($levels))->whereIn('status', $live)->orderBy('queue_number')->get()->groupBy('walkin_queue_id');
        $ordered = [];
        // Each queue's estimate is computed once for this read and shared by the ordering and the presentation.
        $estimates = [];
        foreach ($queues as $queue) {
            if (isset($levels[$queue->id])) {
                if ($levels[$queue->id] !== ViewLevel::Restricted) {
                    $estimates[$queue->id] = $this->presenter->estimatesFor($queue);
                }
                $ordered[$queue->id] = $this->callOrder($queue, $byQueue->get($queue->id, collect()), $levels[$queue->id], $estimates[$queue->id] ?? []);
            }
        }
        $views = [];
        foreach (array_unique(array_map(fn (ViewLevel $l) => $l->value, $levels)) as $levelValue) {
            $level = ViewLevel::from($levelValue);
            $entries = [];
            foreach ($ordered as $queueId => $list) {
                if ($levels[$queueId] === $level) {
                    array_push($entries, ...$list);
                }
            }
            foreach ($this->presenter->present($entries, $level, withEstimate: $level !== ViewLevel::Restricted, minutesForNext: (int) config('walkins.board_minutes_for_next'), estimates: $estimates) as $view) {
                $views[$view->queue->id][] = $view;
            }
        }

        $out = [];
        $entryCount = 0;
        foreach ($queues as $queue) {
            if (! isset($levels[$queue->id])) {
                continue;
            }
            $list = $views[$queue->id] ?? [];
            $entryCount += count($list);
            $out[] = ['queue' => $queue, 'doctor_name' => $doctors->get($queue->doctor_profile_id), 'live_count' => count($list), 'entries' => $list];
        }
        $restricted = in_array(ViewLevel::Restricted, $levels, true);
        // A restricted view is always audited. So is any date other than today and yesterday: history is read with a trace (decision 295, minimum necessary access).
        $historical = $date !== $today && $date !== CarbonImmutable::parse($today)->subDay()->toDateString();
        if ($restricted || $historical) {
            $this->audit->record('walkins.board_read', 'clinic_location', $location->id, array_filter([
                'queues' => count($out), 'entries' => $entryCount, 'audience' => $restricted ? 'RESTRICTED' : 'STAFF', 'historical_date' => $historical ? $date : null,
            ], fn ($v) => $v !== null), accessEvent: true, clinicId: $location->clinic_id);
        }

        return ['location' => $location, 'local_date' => $date, 'restricted' => $restricted, 'queues' => $out];
    }

    /**
     * @param  iterable<WalkInQueueEntry>  $list
     * @param  array<string, WaitEstimate>  $estimates  this queue's estimates, by entry id
     * @return list<WalkInQueueEntry>
     */
    private function callOrder(WalkInQueue $queue, iterable $list, ViewLevel $level, array $estimates): array
    {
        $entries = collect($list);
        $entries->each(fn (WalkInQueueEntry $e) => $e->setRelation('queue', $queue));
        if ($level === ViewLevel::Restricted) {
            return array_values($entries->sortBy('queue_number')->all());
        }

        $position = array_flip(array_keys($estimates));
        $rank = function (WalkInQueueEntry $e) use ($position): array {
            $group = match (true) {
                in_array($e->status, self::LIVE_ORDER, true) => 0,
                isset($position[$e->id]) => 1,
                default => 2,
            };

            return [$group, $position[$e->id] ?? 0, $e->queue_number];
        };

        return array_values($entries->sort(fn (WalkInQueueEntry $a, WalkInQueueEntry $b) => $rank($a) <=> $rank($b))->all());
    }

    /**
     * A patient's own places for a local date: entries of profiles they may act for now (their own and their authorized dependants'),
     * whoever registered them. The date is the location's local date (today there by default). Nobody else's.
     *
     * @return list<EntryView>
     */
    public function mine(User $user, ?string $date): array
    {
        $profiles = $this->patients->profileIds($user);
        if ($profiles === []) {
            return [];
        }
        $anchor = $this->clock->now();
        // The window is around the date asked for (a local date can differ from "now" by a day across zones), or around now when none is given.
        $center = $date === null ? $anchor : CarbonImmutable::parse($date);
        $entries = WalkInQueueEntry::query()->with('queue')->whereIn('patient_profile_id', $profiles)
            ->whereHas('queue', fn ($q) => $q->whereDate('local_date', '>=', $center->subDays($date === null ? 2 : 1)->toDateString())->whereDate('local_date', '<=', $center->addDays($date === null ? 2 : 1)->toDateString()))
            ->orderBy('created_at')->get();
        $timezones = ClinicLocation::query()->whereIn('id', $entries->pluck('queue.clinic_location_id')->unique())->pluck('timezone', 'id');
        $entries = $entries->filter(function (WalkInQueueEntry $e) use ($date, $anchor, $timezones) {
            $wanted = $date ?? $anchor->setTimezone((string) ($timezones[$e->queue->clinic_location_id] ?? config('mediconnect.timezone')))->toDateString();

            return $e->queue->local_date->toDateString() === $wanted;
        });

        return $this->presenter->present($entries, ViewLevel::Owner);
    }

    /** Whether the user owns the entry now: its profile is theirs or one they are currently authorized to manage. */
    public function owns(User $user, WalkInQueueEntry $entry): bool
    {
        return $this->patients->owns($user, $entry->patient_profile_id);
    }

    /**
     * One entry for its reader. A stranger gets 404 (the entry's existence is not revealed). The location's staff get the detail with the
     * guest's name and phone, and that read is audited; the queue's doctor sees names but no phone; a global-scope holder sees the restricted view, audited.
     */
    public function show(User $user, WalkInQueueEntry $entry): EntryView
    {
        $queue = $entry->queue;
        $level = $this->access->viewLevel($user, $queue->clinic_location_id, $queue->doctor_profile_id);
        if ($level === ViewLevel::None || ($level === ViewLevel::Restricted && $this->owns($user, $entry))) {
            abort_unless($this->owns($user, $entry), 404);
            $level = ViewLevel::Owner;
        }

        $view = $this->presenter->one($entry, $level, includePhone: $level === ViewLevel::Staff);
        $clinicId = ClinicLocation::query()->whereKey($queue->clinic_location_id)->value('clinic_id');
        $exposesGuest = $entry->isGuest() && $entry->guest_pii_purged_at === null && $level->seesNames();
        if ($level === ViewLevel::Restricted || $exposesGuest) {
            $this->audit->record('walkins.entry_read', 'walkin_queue_entry', $entry->id, ['audience' => $level->value, 'exposes_guest_data' => $exposesGuest], accessEvent: true, clinicId: is_string($clinicId) ? $clinicId : null);
        }

        return $view;
    }

    /**
     * Search-first dedupe (A3, A4): the location's live entries today, by queue number, exact phone (blind index) or name. A name is
     * matched in memory over at most search_max_rows decrypted rows, never in SQL on ciphertext. A phone is never echoed. Audited by type and count.
     *
     * @return array{type: string, truncated: bool, entries: list<EntryView>}
     */
    public function search(User $user, ClinicLocation $location, string $term): array
    {
        // The staff who register walk-ins (walkins.register at the location) or the doctor of their own queue, once DOCTOR holds that permission.
        $isStaff = $this->access->memberMay($user, 'walkins.register', $location->id);
        $ownDoctor = $this->access->ownDoctorId($user);
        $asDoctor = ! $isStaff && $ownDoctor !== null && $this->access->doctorRoleHolds($user, 'walkins.register');
        abort_unless($isStaff || $asDoctor, 403, 'You do not have access to this location.');
        $level = $isStaff ? ViewLevel::Staff : ViewLevel::Doctor;

        $date = $this->clock->now()->setTimezone($location->timezone)->toDateString();
        $live = array_map(fn (EntryStatus $s) => $s->value, [...EntryStatus::live(), EntryStatus::Skipped]);
        $base = WalkInQueueEntry::query()->with('queue')
            ->whereHas('queue', fn ($q) => $q->where('clinic_location_id', $location->id)->whereDate('local_date', $date)->when($asDoctor, fn ($r) => $r->where('doctor_profile_id', $ownDoctor)))
            ->whereIn('status', $live);

        $type = self::termType($term);
        $this->truncated = false;
        $entries = match ($type) {
            'phone' => $base->where('guest_phone_hash', WalkInRegistration::phoneHash(PhoneNumber::parse($term)->e164))->orderBy('queue_number')->limit(50)->get(),
            'number' => $this->byNumber($base, $term),
            default => $this->byName($base, $term),
        };

        $this->audit->record('walkins.search', 'clinic_location', $location->id, ['type' => $type, 'count' => $entries->count()], accessEvent: true, clinicId: $location->clinic_id);

        return ['type' => $type, 'truncated' => $this->truncated, 'entries' => $this->presenter->present($entries, $level, withEstimate: false)];
    }

    /** phone (digits that look like a Philippine mobile), number (A-07, 07, 7), or name. */
    public static function termType(string $term): string
    {
        $t = trim($term);
        if (preg_match('/^[+(\d][\d\s\-().]{6,}$/', $t) === 1) {
            return 'phone';
        }

        return preg_match('/^(?:[A-Za-z]{1,4}-?)?\d{1,4}$/', $t) === 1 ? 'number' : 'name';
    }

    /**
     * @param  Builder<WalkInQueueEntry>  $base
     * @return Collection<int, WalkInQueueEntry>
     */
    private function byNumber($base, string $term): Collection
    {
        preg_match('/^(?:([A-Za-z]{1,4})-?)?(\d{1,4})$/', trim($term), $m);
        $prefix = isset($m[1]) && $m[1] !== '' ? strtoupper($m[1]) : null;
        $number = (int) ($m[2] ?? 0);

        return $base->where('queue_number', $number)
            ->when($prefix !== null, fn ($q) => $q->whereHas('queue', fn ($r) => $r->where('number_prefix', $prefix)))->orderBy('queue_number')->limit(50)->get();
    }

    /**
     * @param  Builder<WalkInQueueEntry>  $base
     * @return Collection<int, WalkInQueueEntry>
     */
    private function byName($base, string $term): Collection
    {
        $needle = mb_strtolower(trim($term));
        $cap = (int) config('walkins.search_max_rows');
        $rows = $base->orderBy('queue_number')->limit($cap)->get();
        $this->truncated = $rows->count() >= $cap;
        $names = PatientProfile::query()->whereIn('id', $rows->pluck('patient_profile_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (PatientProfile $p) => [$p->id => trim($p->first_name.' '.$p->last_name)]);

        return $rows->filter(function (WalkInQueueEntry $e) use ($needle, $names) {
            $name = $e->isGuest() ? ($e->guest_pii_purged_at === null ? $e->guest_name_encrypted : null) : ($names[$e->patient_profile_id] ?? null);

            return $name !== null && str_contains(mb_strtolower($name), $needle);
        })->take(50)->values();
    }

    /** Whether the term is a phone number the API cannot read (so a search can answer 422 instead of an empty list). */
    public static function termIsBadPhone(string $term): bool
    {
        if (self::termType($term) !== 'phone') {
            return false;
        }
        try {
            PhoneNumber::parse($term);
        } catch (\Throwable) {
            return true;
        }

        return false;
    }
}
