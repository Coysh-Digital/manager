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

// Retention. A backup kept indefinitely is personal data kept indefinitely, so this is not optional
// and its default is not "forever". Runs after the audit check so a day's deletions are recorded in a
// chain that has just been verified.
Schedule::command('manager:backups:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/*
 | Scheduled backups.
 |
 | Hourly rather than nightly, and deciding per site inside the command. A fleet of forty sites all
 | dumping at 03:00 is forty databases being read at once on shared hosting, and "nightly" means
 | something different in Auckland from what it means in Bristol - the hour is stored per site, in the
 | organisation's own time zone.
 |
 | Nothing here decides what a backup is encrypted to or where it goes. It decides when to ask.
 */
Schedule::command('manager:backups:schedule')
    ->hourly()
    ->withoutOverlapping();

/*
 | TLS certificates.
 |
 | Once a day, because the failure this catches is a renewal that did not happen, and that becomes
 | visible weeks before it matters. Checking hourly would multiply the outbound connections by
 | twenty-four to learn the same thing.
 |
 | The one check where this platform reaches out to a site rather than waiting to be told, because the
 | connector genuinely cannot see the certificate a visitor validates - TLS terminates at the edge.
 */
Schedule::command('manager:certificates:check')
    ->dailyAt('05:00')
    ->withoutOverlapping();

/*
 | What a site serves to somebody who is not it.
 |
 | The second check that reaches out rather than waiting to be told, and for the same reason as the
 | first: a response header is decided by whatever serves the response - nginx, a CDN, a WAF - and
 | PHP on the origin sees none of that. A site whose edge strips a header the application sets looks
 | correct from inside and is not.
 |
 | Daily for the reason above it. A header appears or disappears on a deploy, and a file left in a
 | webroot stays there; checking more often would multiply the requests made to somebody else's
 | server to learn the same thing.
 |
 | Half an hour after the certificates, deliberately. Both sweep the same hosts, and a fleet of two
 | hundred opening two sets of connections to all of them in the same minute is a spike in somebody
 | else's monitoring that this platform has no reason to cause.
 */
Schedule::command('manager:web:check')
    ->dailyAt('05:30')
    ->withoutOverlapping();

/*
 | Findings, on a clock rather than on a report.
 |
 | The rule that matters here is `site_not_reporting`, and the reason it needs a schedule is the
 | reason it exists: findings were only ever evaluated when a site sent a report or when somebody
 | opened a screen, and a site that has stopped reporting does neither. The one alert whose whole
 | subject is silence was raised only by code paths that silence prevents from running.
 |
 | Hourly, which is well inside the rule's own six-hour threshold and cheap: nothing here calls out
 | to a site, it re-reads rows already stored. It also gives every other time-dependent rule a clock
 | to move against, and lets a resolved finding close itself without waiting for the next report.
 */
Schedule::command('manager:findings:sweep')
    ->hourly()
    ->withoutOverlapping();

// Heartbeats arrive every five minutes per site and the Health screen derives uptime from them
// rather than storing it, so they accumulate faster than anything else here. Retention is a stated
// window, not "forever with an index on it".
Schedule::command('manager:telemetry:prune')
    ->dailyAt('04:00')
    ->withoutOverlapping();
