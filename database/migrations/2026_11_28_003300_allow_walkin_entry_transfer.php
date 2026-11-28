<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A queue transfer keeps an entry's id and moves it to another queue (increment 5.8-2, D-G). The composite foreign key
 * walkin_queue_events(walkin_queue_entry_id, walkin_queue_id) -> walkin_queue_entries(id, walkin_queue_id) would refuse that UPDATE for every
 * event the entry already has, and the events are append-only so they cannot follow it.
 *
 * Replacement: the event keeps its own queue (its history), a plain foreign key holds the entry, and a BEFORE INSERT trigger refuses an event
 * that names a queue other than the entry's current one. The transfer writes its own TRANSFERRED event after it moves the entry, so it passes.
 * The appendOnly trigger (forbid_mutation) is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION walkin_queue_events_match_entry() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v_queue uuid;
            BEGIN
                IF NEW.walkin_queue_entry_id IS NULL THEN
                    RETURN NEW;
                END IF;
                -- FOR SHARE: an event for an entry waits for a transfer of that entry that is still in progress.
                SELECT walkin_queue_id INTO v_queue FROM walkin_queue_entries WHERE id = NEW.walkin_queue_entry_id FOR SHARE;
                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;
                IF v_queue IS DISTINCT FROM NEW.walkin_queue_id THEN
                    RAISE EXCEPTION 'walk-in event does not name the current queue of its entry' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER walkin_queue_events_match_entry BEFORE INSERT ON walkin_queue_events FOR EACH ROW EXECUTE FUNCTION walkin_queue_events_match_entry()');
        DB::statement('ALTER TABLE walkin_queue_events DROP CONSTRAINT walkin_queue_events_entry_queue_fk');
    }

    public function down(): void
    {
        $moved = DB::selectOne('SELECT COUNT(*) AS n FROM walkin_queue_events ev JOIN walkin_queue_entries e ON e.id = ev.walkin_queue_entry_id WHERE ev.walkin_queue_id <> e.walkin_queue_id');
        if ((int) ($moved->n ?? 0) > 0) {
            throw new RuntimeException('Refusing to roll back: a transferred entry has events on its previous queue, and the composite key cannot hold them.');
        }

        DB::statement('ALTER TABLE walkin_queue_events ADD CONSTRAINT walkin_queue_events_entry_queue_fk FOREIGN KEY (walkin_queue_entry_id, walkin_queue_id) REFERENCES walkin_queue_entries (id, walkin_queue_id)');
        DB::statement('DROP TRIGGER IF EXISTS walkin_queue_events_match_entry ON walkin_queue_events');
        DB::statement('DROP FUNCTION IF EXISTS walkin_queue_events_match_entry()');
    }
};
