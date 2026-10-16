<?php

declare(strict_types=1);

use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;

/*
 * The Acknowledge popover is not cut off by the card around it.
 *
 * Reported from use (#105): clicking Acknowledge opened the reason field and its Confirm button, and
 * the card clipped the lower half of it, so Confirm could not be reached.
 *
 * The popover is absolutely positioned and hangs below the row it opens from. The card around a
 * list of findings has `rounded-[10px]` and, until this was fixed, `overflow-hidden` to round its
 * corners - and `overflow-hidden` clips anything that hangs outside the box, absolutely positioned
 * or not. Which rows lost their button depended on where the row sat in the card, so a test that
 * clicked one row would have passed.
 *
 * The clip is a fact about layout and does not show up in rendered HTML as text. What can be
 * asserted is the shape that causes it: no ancestor of the popover may clip vertically. `overflow-x`
 * on its own is fine, and is what the cards use now, so a long domain still cannot widen the page.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();

    foreach (['inventory:read', 'updates:read', 'security:read', 'system:read'] as $capability) {
        CapabilityGrant::factory()->for($this->site)->capability($capability)->create();
    }
});

/**
 * The classes of every ancestor of each Acknowledge popover on a page, as a list per popover.
 *
 * @return list<list<string>>
 */
function acknowledgePopoverAncestry(string $html): array
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    $popovers = (new DOMXPath($document))->query(
        "//details//form[contains(concat(' ', normalize-space(@class), ' '), ' absolute ')]"
    );

    $found = [];

    foreach ($popovers as $popover) {
        $classes = [];

        for ($node = $popover->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $classes[] = $node->getAttribute('class');
        }

        $found[] = $classes;
    }

    return $found;
}

/**
 * Anything that clips on the vertical axis. `overflow-x-*` is deliberately absent: it clips
 * sideways only, which is the point of using it.
 */
function clipsVertically(string $class): bool
{
    return preg_match('~(?<![\w-])(overflow-(hidden|auto|scroll|clip)|overflow-y-(hidden|auto|scroll|clip))(?![\w-])~', $class) === 1;
}

dataset('acknowledge screens', [
    'Security' => ['/security', 'https_not_enforced'],
    'Findings' => ['/findings', 'pending_migrations'],
    'Site security' => [fn () => route('sites.security', test()->site), 'https_not_enforced'],
]);

it('does not put the Acknowledge popover inside a container that clips it', function (string|Closure $url, string $rule): void {
    Finding::factory()->for($this->site)->rule($rule)->create();

    $html = $this->actingAs($this->owner)
        ->get($url instanceof Closure ? $url() : $url)
        ->assertOk()
        ->getContent();

    $popovers = acknowledgePopoverAncestry($html);

    // Without this the test passes on a page that rendered no popover at all, which is the same
    // green as a page that renders one correctly.
    expect($popovers)->not->toBeEmpty();

    foreach ($popovers as $ancestry) {
        $clipping = array_values(array_filter($ancestry, clipsVertically(...)));

        expect($clipping)->toBe([], 'The Acknowledge popover sits inside a container that clips it: '.implode(' | ', $clipping));
    }
})->with('acknowledge screens');
