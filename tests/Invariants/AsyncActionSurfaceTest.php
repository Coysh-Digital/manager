<?php

declare(strict_types=1);

use App\Http\Middleware\AnswerInPlace;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;

/**
 * Invariant 16, read from the browser's side.
 *
 *  16. Retries must not cause an action to run twice.
 *
 * `AnswerInPlace` lets a form post be performed without following the redirect afterwards, and
 * resources/js/submit.js re-posts the form the ordinary way whenever it cannot read the answer - an
 * expired session, a network that went away, an error page instead of JSON. That fallback is the
 * whole reason the enhancement is safe to have at all, and it is also the reason a route cannot
 * carry the middleware unless performing it twice is harmless.
 *
 * So the list is written down here as well as in routes/web.php. Adding a seventh route means
 * editing a test named after the rule it is about to bend, rather than adding six characters to a
 * route definition in a diff nobody reads twice.
 */

/**
 * Every route that may answer in place, and why it is allowed to.
 *
 * The first five pass an idempotency key to JobService::enqueue(), which returns the job already
 * outstanding rather than queuing a second. The sixth converges: cancelling a job that has already
 * finished answers "that backup had already finished" and changes nothing.
 *
 * @var list<string>
 */
$permitted = [
    'backups.cancel',
    'backups.store',
    'backups.store-many',
    'sites.refresh',
    'sites.refresh-all',
    'updates.refresh',
];

/**
 * Whether a route answers in place, asked about the class rather than the alias.
 *
 * `gatherMiddleware()` reports what the route definition wrote, which is the alias - so a test
 * matching on the class alone silently passes on every route, and a test matching on the string
 * `'in-place'` would go quiet the day somebody renamed the alias. Resolved through the same map the
 * pipeline resolves it with, so the two cannot disagree.
 */
function answersInPlace(Illuminate\Routing\Route $route): bool
{
    /*
     | The Kernel's map rather than the Router's.
     |
     | `Router::getMiddleware()` carries only the framework's own aliases; the ones registered in
     | bootstrap/app.php through `$middleware->alias([...])` live on the Kernel, and asking the
     | wrong object returns a map that quietly does not contain this one - which makes every check
     | below pass on an empty set.
    */
    $kernel = app(Kernel::class);
    $aliases = $kernel instanceof Illuminate\Foundation\Http\Kernel ? $kernel->getMiddlewareAliases() : [];

    foreach ($route->gatherMiddleware() as $entry) {
        if (! is_string($entry)) {
            continue;
        }

        // Parameters are separated by a colon - `capability:backups:create`. Only the name matters.
        $name = explode(':', $entry, 2)[0];

        if (($aliases[$name] ?? $name) === AnswerInPlace::class) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function routesAnsweringInPlace(): array
{
    $names = [];

    foreach (Route::getRoutes() as $route) {
        if (answersInPlace($route)) {
            $names[] = $route->getName() ?? $route->uri();
        }
    }

    sort($names);

    return $names;
}

it('answers in place on exactly the routes that are safe to perform twice', function () use ($permitted): void {
    /*
     | Set equality, not a subset.
     |
     | A subset check would let somebody add the middleware to `backups.destroy` and ship it green.
     | Deleting an artifact is not something the native-resubmit fallback may be pointed at, and no
     | amount of care in the JavaScript changes that - the browser decides when to give up, and the
     | second request is indistinguishable from the first.
    */
    expect(routesAnsweringInPlace())->toBe($permitted);
});

it('answers in place only on POST', function (): void {
    foreach (Route::getRoutes() as $route) {
        if (! answersInPlace($route)) {
            continue;
        }

        // A GET that redirects is a navigation, and reshaping one into JSON would break a link
        // rather than enhance a button.
        expect($route->methods())->toContain('POST')
            ->and($route->methods())->not->toContain('DELETE');
    }
});

it('never answers in place behind a password confirmation', function (): void {
    foreach (Route::getRoutes() as $route) {
        if (! answersInPlace($route)) {
            continue;
        }

        /*
         | The recent-authentication gate exists to interrupt somebody and produce a full page they
         | have to act on. A route that answers quietly cannot also do that: the redirect to the
         | confirmation form would arrive as JSON, and although submit.js follows a destination that
         | is not the current page, relying on that would make a security gate depend on a script.
        */
        expect($route->gatherMiddleware())->not->toContain('password.confirm');
    }
});

it('is not in the global stack, and reaches no connector route', function (): void {
    $kernel = app(Kernel::class);

    expect($kernel->hasMiddleware(AnswerInPlace::class))->toBeFalse();

    foreach (Route::getRoutes() as $route) {
        if (! answersInPlace($route)) {
            continue;
        }

        // The connector API is machine-to-machine, stateless, and has no flash data to convert. A
        // response middleware written for a browser has no business in that pipeline.
        expect($route->getName())->not->toStartWith('connector.');
    }
});
