<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Site;

/**
 * The breadcrumb trail a screen sits at the end of.
 *
 * Encoded as JSON into the `crumb` section rather than passed as view data, because the topbar is
 * rendered by the layout and `@section` is the only channel a child view has to it. A plain string
 * still works and reads as a single unlinked segment, so a screen that has not been converted is
 * merely terse rather than broken.
 */
final class Crumbs
{
    /**
     * @param  list<array{label: string, href?: string}>  $segments
     */
    public static function encode(array $segments): string
    {
        return (string) json_encode($segments);
    }

    /**
     * Fleet → this site → this screen.
     *
     * The middle segment is the one that was missing: a site's screens offered no way back to the
     * fleet except the sidebar, which on a phone is behind a drawer.
     */
    public static function site(Site $site, ?string $page = null): string
    {
        $segments = [
            ['label' => 'Sites', 'href' => route('sites.index')],
            ['label' => $site->name, 'href' => route('sites.show', $site)],
        ];

        if ($page !== null) {
            $segments[] = ['label' => $page];
        }

        return self::encode($segments);
    }

    /**
     * A top-level screen, with no parent above it.
     */
    public static function top(string $label): string
    {
        return self::encode([['label' => $label]]);
    }
}
