<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Where an alert tells somebody to go, and what to call the way there.
 *
 * Every alert used to end with the same sentence and the same URL - `/findings` - whatever had
 * happened. Reported live: a backup failure arrived pointing at a screen that lists security
 * findings, which had nothing to say about it. Somebody following that link finds the screen it
 * names working perfectly and no sign of the thing they were told about, and the reasonable
 * conclusion is that the alert was wrong rather than that the link was.
 *
 * **Named routes, never a signed one.** An alert sits in a mailbox for years and gets forwarded, so
 * the link may be opened by somebody who should not be reading it - which is fine as long as it is
 * only ever an address. Every destination here requires a session and is scoped to the
 * organisation by `site.scoped`, so the worst an unintended reader gets is a sign-in screen. A
 * tokenised link would make the email itself the credential, and `tests/Invariants/MailBrandingTest`
 * asserts that neither part of the message contains one.
 *
 * The fallbacks are not defensive padding: an event carries no site when the site refused before
 * anything existed to name, and the Settings test button deliberately sends one with none at all.
 */
final class AlertLink
{
    private function __construct(
        public readonly string $label,
        public readonly string $url,
    ) {}

    public static function for(NotificationEvent $event): self
    {
        $site = $event->site;

        return match ($event->type) {
            NotificationEvent::BACKUP_FAILED => $site === null
                ? new self('Open backups', route('backups.index'))
                : new self('Open backups for this site', route('sites.backups', $site)),

            NotificationEvent::SITE_SILENT => $site === null
                ? new self('Open your sites', route('sites.index'))
                : new self('Open this site', route('sites.show', $site)),

            NotificationEvent::CONNECTOR_REVOKED => $site === null
                ? new self('Open your sites', route('sites.index'))
                : new self("Open this site's settings", route('sites.settings', $site)),

            NotificationEvent::CAPABILITY_CONFIRMED => $site === null
                ? new self('Open your sites', route('sites.index'))
                : new self('Open the permissions for this site', route('sites.capabilities', $site)),

            // finding.opened, and anything a hosting layer dispatches that this does not know
            // about. Findings is the screen the alerts were all pointing at before this class
            // existed, so an unrecognised type is no worse off than it was.
            default => new self('Open findings', route('findings.index')),
        };
    }

    /** Where somebody changes what they are sent. Fixed, and the same on every alert. */
    public static function preferences(): string
    {
        return route('settings.notifications');
    }
}
