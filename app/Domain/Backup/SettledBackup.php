<?php

declare(strict_types=1);

namespace App\Domain\Backup;

/**
 * One backup that was being watched and has now stopped being outstanding.
 *
 * Read only, and assembled for one purpose: a screen that was showing an in-progress card needs to
 * say where it went. Before this existed the answer was `window.location.reload()` - correct, and
 * jarring, because it threw away the scroll position and whatever else the person was doing at the
 * moment a background job happened to finish.
 *
 * So the card disappears and a sentence takes its place. What that sentence cannot do is pretend the
 * rest of the screen has caught up: the artifact table, the summary tiles and the "Last backup"
 * strip are all still describing the world as it was. That is why every one of these carries an
 * invitation to reload rather than quietly leaving a stale page looking current.
 */
final readonly class SettledBackup
{
    public function __construct(
        public string $jobId,
        public string $siteName,

        /** What happened, as somebody watching the stepper should be told. */
        public string $sentence,

        /**
         * Which of the toast tones this belongs in.
         *
         * A failure is amber rather than red, matching the "Did not complete" panel it will be
         * sitting above once the page is reloaded. Red is kept for the things somebody is being
         * refused, so that it stays rare enough to mean something.
         */
        public string $tone,
    ) {}

    /**
     * @return array{job_id: string, site: string, sentence: string, tone: string}
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'site' => $this->siteName,
            'sentence' => $this->sentence,
            'tone' => $this->tone,
        ];
    }
}
