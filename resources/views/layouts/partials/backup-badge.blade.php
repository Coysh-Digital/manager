{{--
    The count beside "Backups" in the sidebar.

    Two counts, one slot. A failure outranks work in progress: a backup that did not happen is the
    thing somebody has to act on, and one that is still running resolves itself. So the red count
    wins the space when there is one, and the running count takes it otherwise - never both, because
    a nav entry is not a dashboard.

    Its own file so that precedence has one statement. The poller keeps this current now that
    pressing "Back up now" does not reload the page, and it fetches this markup rather than the two
    integers: a rule written once in Blade and again in JavaScript is a rule that will eventually be
    two different rules.
--}}
@if (($backupsFailed ?? 0) > 0)
    <span class="rounded border border-danger-line bg-danger-bg px-1.5 py-px font-mono text-[11px] text-danger">{{ $backupsFailed }}</span>
@elseif (($backupsRunning ?? 0) > 0)
    {{-- No border and no fill. Work in progress is not a state to interrupt anybody about; it is
         there so pressing "Back up now" and navigating away still shows that something is
         happening. --}}
    <span class="font-mono text-[11px] text-text-3">{{ $backupsRunning }}</span>
@endif
