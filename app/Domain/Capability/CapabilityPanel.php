<?php

declare(strict_types=1);

namespace App\Domain\Capability;

use App\Models\CapabilityEvent;
use App\Models\CapabilityGrant;
use App\Models\Site;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Support\Collection;

/**
 * The capability section of a site's settings, as the screen needs it.
 *
 * Every capability the platform defines appears, granted or not: a list of only the granted ones
 * answers the less useful half of the question. What is *not* permitted is the part somebody is
 * usually checking.
 *
 * This lived in a controller action when capabilities had a screen of their own. It became a class
 * when that screen became a section of another one, rather than being copied.
 */
final class CapabilityPanel
{
    /**
     * @return list<array{name: string, grant: CapabilityGrant|null, granted: bool, readOnly: bool, implemented: bool, grantable: bool, confirmable: bool, acknowledgement: string|null}>
     */
    public function for(Site $site): array
    {
        $grants = $site->capabilityGrants()->orderBy('capability')->get()->keyBy('capability');

        return array_map(fn (string $capability): array => [
            'name' => $capability,
            'grant' => $grants->get($capability),
            'granted' => $grants->get($capability)?->isGranted() ?? false,
            'readOnly' => Protocol::isReadOnlyCapability($capability),
            'implemented' => in_array($capability, CapabilityService::grantableFromInterface(), true)
                || in_array($capability, CapabilityService::confirmable(), true),
            'grantable' => in_array($capability, CapabilityService::grantableFromInterface(), true),

            // Grantable, but never with a switch. The screen has to render these differently or
            // the distinction the specification asks for exists only in the code.
            'confirmable' => in_array($capability, CapabilityService::confirmable(), true),
            'acknowledgement' => in_array($capability, CapabilityService::confirmable(), true)
                ? CapabilityService::acknowledgementFor($capability)
                : null,
        ], Protocol::capabilities());
    }

    /**
     * How this site's permissions got to where they are.
     *
     * @return Collection<int, CapabilityEvent>
     */
    public function history(Site $site, int $limit = 20): Collection
    {
        return CapabilityEvent::query()
            ->where('site_id', $site->id)
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
