<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------------------------------
|
| Run by the scheduler container. Everything here is either a safety net or an integrity check —
| there is nothing whose failure would go unnoticed until somebody happened to look.
|
*/

// A claimed job that never reports would otherwise sit as "claimed" indefinitely, making the fleet
// look busy rather than stuck.
Schedule::command('manager:jobs:expire')
    ->everyMinute()
    ->withoutOverlapping();

// The chain is tamper-evident, not tamper-proof, so somebody has to actually check it. Daily, with
// failures going to the log the operator already watches.
Schedule::command('manager:audit:verify')
    ->dailyAt('03:00')
    ->withoutOverlapping();
