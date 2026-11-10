<?php

declare(strict_types=1);

namespace App\Domain\Advisories;

/**
 * Advisories written down here because the feed does not carry them yet.
 *
 * The feed is the source of truth and this is a stopgap for the gap between an advisory being
 * announced and Packagist listing it, which is the window in which a site is most likely to be
 * attacked. An entry here is dropped from the result the moment the feed has one with the same
 * advisory id, so leaving one behind after the feed catches up costs nothing and is not a second
 * finding.
 *
 * **An entry is only as good as what it was written from**, so each says where its range came from.
 * Ranges are the first affected release and the first fixed one, as the vendor stated them. A bound
 * that was assumed rather than stated is marked as such, and a lower bound is the cautious reading:
 * claiming a release is affected that is not is a correction, claiming one is safe that is not is an
 * incident.
 *
 * @phpstan-type Curated array{
 *     advisory_id: string,
 *     cve: string|null,
 *     severity: string,
 *     affected_versions: string,
 *     link: string
 * }
 */
final class CuratedAdvisories
{
    /**
     * @var list<Curated>
     */
    public const ENTRIES = [
        [
            // SQL injection through the element search endpoint, with an unauthenticated path to it.
            // Craft's security team reported a fix in 4.18.9 and 5.11.2 and retroactively marked
            // both critical because exploitation was being seen in the wild; the advisory id is
            // theirs, and VulnCheck's advisory states the Craft 4 range as 4.0.0 before 4.18.9.
            //
            // ASSUMED: that Craft 5 is affected from 5.0.0-RC1. It was not stated, but the same
            // controller was the subject of an earlier advisory affecting every Craft 5 release up to
            // 5.9.8, so the endpoint exists across the whole line.
            'advisory_id' => 'GHSA-qcvp-ggx3-97r6',
            'cve' => null,
            'severity' => 'critical',
            'affected_versions' => '>=4.0.0-RC1,<4.18.9|>=5.0.0-RC1,<5.11.2',
            'link' => 'https://github.com/craftcms/cms/security/advisories/GHSA-qcvp-ggx3-97r6',
        ],
    ];
}
