<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Subscribe every existing destination to backup failures.
 *
 * A destination stores the events it wants and `wants()` requires explicit membership — an empty
 * list means none, not all, which is the right default and is documented on the table. It also
 * means a new event reaches nobody who set up notifications before it existed.
 *
 * Normally that is correct: somebody chose their events and a later release should not start sending
 * them something they did not ask for. This one is the exception, and the reason is that it was
 * already promised. The pricing page has said "with a notification when a run does not complete"
 * since launch, so every destination on this table was created by somebody who had been told this
 * would happen and had no checkbox to tick for it.
 *
 * Adding it, rather than leaving people to discover a new option they believed they already had, is
 * what makes the sentence true. Anybody who does not want it can untick it; the screen lists it now.
 *
 * Not reversible in the meaningful sense. Down removes the subscription again, which restores the
 * column but not the state of somebody having chosen — a destination that ticked it deliberately
 * after this ran would lose it. That is stated rather than worked around, because the alternative is
 * recording who chose what and when, which is a bigger thing than this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->each(static function (object $row): array {
            $events = json_decode((string) $row->events, true);
            $events = is_array($events) ? $events : [];

            return in_array(NotificationEvent::BACKUP_FAILED, $events, true)
                ? $events
                : [...$events, NotificationEvent::BACKUP_FAILED];
        });
    }

    public function down(): void
    {
        $this->each(static function (object $row): array {
            $events = json_decode((string) $row->events, true);
            $events = is_array($events) ? $events : [];

            return array_values(array_filter(
                $events,
                static fn ($event): bool => $event !== NotificationEvent::BACKUP_FAILED,
            ));
        });
    }

    /**
     * Rewrite every destination's event list through a callback.
     *
     * Row by row rather than a jsonb operation, because this table is small — one or two rows per
     * organisation — and a portable loop is worth more here than a clever statement that would tie
     * the migration to one database.
     *
     * @param  callable(object): list<string>  $rewrite
     */
    private function each(callable $rewrite): void
    {
        DB::table('notification_destinations')
            ->orderBy('id')
            ->select(['id', 'events'])
            ->chunk(100, static function ($rows) use ($rewrite): void {
                foreach ($rows as $row) {
                    DB::table('notification_destinations')
                        ->where('id', $row->id)
                        ->update(['events' => json_encode($rewrite($row))]);
                }
            });
    }
};
