<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Models\Membership;
use App\Models\Site;
use App\Models\SiteNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Notes on a site.
 *
 * Any member may write one. This is the least privileged useful thing in the interface after the
 * Refresh button: it changes nothing about the site, grants nothing, and the whole value of it comes
 * from being easy enough that people actually do it. Restricting it to administrators would mean the
 * person who found the thing worth writing down often could not.
 *
 * Deleting is narrower — an owner, or whoever wrote it. Notes are the record of why a site is the way
 * it is, and quietly removable institutional memory is worse than none.
 */
final class SiteNoteController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function store(Request $request, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:'.SiteNote::MAX_LENGTH],
            'pinned' => ['nullable', 'boolean'],
        ]);

        $note = SiteNote::query()->create([
            'site_id' => $site->id,
            'author_id' => $request->user()->id,

            // Denormalised at write time so an account removed a year from now does not turn this
            // note's authorship into "Unknown".
            'author_label' => $request->user()->name ?: $request->user()->email,
            'body' => trim($validated['body']),
            'pinned' => (bool) ($validated['pinned'] ?? false),
        ]);

        // The note itself is not recorded in the audit log, only that one was written. The log is
        // append-only and hash-chained, so anything put in it is there permanently and cannot be
        // corrected — and free text somebody typed in a hurry is the last thing that should be.
        $this->audit->record(
            action: 'site.note.added',
            site: $site,
            actor: $request->user(),
            targetType: 'note',
            targetId: $note->external_id,
            after: ['pinned' => $note->pinned, 'length' => mb_strlen($note->body)],
        );

        return back()->with('status', $note->pinned
            ? 'Note pinned. It shows at the top of this site\'s Overview.'
            : 'Note added.');
    }

    /**
     * Pin or unpin.
     *
     * Pinned notes are the operational caveats somebody must read before touching the site; the rest
     * are history. Which is which changes over time, so this is a toggle rather than a property set
     * once at writing.
     */
    public function pin(Request $request, Site $site, SiteNote $note): RedirectResponse
    {
        $this->authoriseNote($note, $site);

        $note->forceFill(['pinned' => ! $note->pinned])->save();

        return back()->with('status', $note->pinned ? 'Note pinned.' : 'Note unpinned.');
    }

    public function destroy(Request $request, Site $site, SiteNote $note): RedirectResponse
    {
        $this->authoriseNote($note, $site);

        // The author, or an owner. Notes are institutional memory, and one colleague deleting
        // another's explanation of why a site is configured the way it is should not be routine.
        abort_unless(
            $note->author_id === $request->user()->id || app(Membership::class)->isOwner(),
            403,
        );

        $this->audit->record(
            action: 'site.note.deleted',
            site: $site,
            actor: $request->user(),
            targetType: 'note',
            targetId: $note->external_id,
            before: ['author' => $note->authorName(), 'length' => mb_strlen($note->body)],
        );

        $note->delete();

        return back()->with('status', 'Note deleted.');
    }

    /**
     * A note belonging to another site is a 404, even inside the right organisation.
     *
     * Route binding resolves the note on its own identifier, so without this a note id from one site
     * could be acted on through another site's URL.
     */
    private function authoriseNote(SiteNote $note, Site $site): void
    {
        abort_if($note->site_id !== $site->id, 404);
    }
}
