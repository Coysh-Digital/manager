<?php

declare(strict_types=1);

use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ResumableInput;
use Illuminate\Support\Facades\Route;

/**
 * Nothing secret survives the recent-authentication gate.
 *
 * The gate now keeps a form across the confirm-password screen, which means a POST body is written
 * into a session. That is a useful thing to do for "Add a site" and a dangerous one for the routes
 * either side of it: `recovery-keys.prove` carries a proof of possession of the key that reads every
 * backup an organisation holds, and `sites.destroy` carries a typed confirmation whose entire
 * purpose is that somebody types it at the moment they act.
 *
 * ResumableInput has two layers — a per-route field allowlist and an unconditional key filter — and
 * this file is why there are two. The first test does not read the allowlist. It walks the routes
 * the application actually has, posts sentinels at every one of them, and asserts the sentinels are
 * nowhere afterwards. A route added in a year's time is covered the day it is written, without
 * anybody remembering this file exists.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();
});

it('never flashes a secret through the password gate', function (): void {
    $sentinels = [
        'password' => 'SENTINEL-PASSWORD',
        'passphrase' => 'SENTINEL-PASSPHRASE',
        'secret_key' => 'SENTINEL-SECRET',
        'recovery_key' => 'SENTINEL-RECOVERY',
        'signature' => 'SENTINEL-SIGNATURE',
        'challenge' => 'SENTINEL-CHALLENGE',
        'proof' => 'SENTINEL-PROOF',
        'token' => 'SENTINEL-TOKEN',
        'otp' => 'SENTINEL-OTP',
        'code' => 'SENTINEL-CODE',
        'confirm_domain' => 'SENTINEL-CONFIRM-DOMAIN',
        'confirm_site' => 'SENTINEL-CONFIRM-SITE',
        'organisation' => 'SENTINEL-ORGANISATION',
        'authorise_replacement' => 'SENTINEL-AUTHORISE',
    ];

    $gated = collect(Route::getRoutes())
        ->filter(fn ($route): bool => in_array('password.confirm', $route->gatherMiddleware(), true))
        ->filter(fn ($route): bool => count(array_intersect(['POST', 'DELETE', 'PUT', 'PATCH'], $route->methods())) > 0);

    // If this ever reads zero the test is passing vacuously, which is worse than failing.
    expect($gated)->not->toBeEmpty();

    foreach ($gated as $route) {
        // Bound parameters are not the point here — a 404 from a missing model still runs the gate,
        // which is the middleware under test. Any placeholder will do.
        $uri = preg_replace('/\{[^}]+\}/', 'placeholder', $route->uri());
        $method = in_array('POST', $route->methods(), true) ? 'post' : 'delete';

        $this->flushSession();

        $this->actingAs($this->owner)->{$method}('/'.$uri, $sentinels);

        $flashed = session('_old_input', []);
        $stashed = session('manager.resumable_input', []);

        $haystack = json_encode([$flashed, $stashed]);

        foreach ($sentinels as $field => $value) {
            // PHPUnit's assertion rather than expect()->not->toContain(): Pest's toContain is
            // variadic, so passing a failure message as a second argument turns the check into
            // "does not contain both of these" and it passes for free. That mistake was in this
            // file first, and it made every assertion below pass vacuously.
            $this->assertStringNotContainsString($value, (string) $haystack, sprintf(
                'Route %s put "%s" somewhere a stolen session could read it.',
                $route->getName() ?? $uri,
                $field,
            ));
        }
    }
});

it('will not resume a route that asks somebody to type a confirmation', function (): void {
    // A typed confirmation exists so that the person types it at the moment they act. Handing one
    // back across an authentication interstitial removes the only thing it was ever for.
    $forbidden = ['confirm_domain', 'confirm_site', 'confirm_name', 'organisation', 'authorise_replacement', 'acknowledge'];

    foreach (ResumableInput::rules() as $name => $rule) {
        foreach ($rule['fields'] as $field) {
            $this->assertNotContains($field, $forbidden, "Route {$name} would restore a typed confirmation.");
        }
    }
});

it('names only routes that exist', function (): void {
    // A typo here disables restore with no symptom whatsoever: the form is simply lost again, which
    // is the bug this was written to fix.
    foreach (ResumableInput::rules() as $name => $rule) {
        $this->assertTrue(Route::has($name), "Resumable route {$name} does not exist.");
        $this->assertTrue(Route::has($rule['return']), "Return route {$rule['return']} does not exist.");
    }
});

it('keeps the gate itself in front of every sensitive action', function (): void {
    // The alias was replaced with a subclass. If that swap ever stops applying, the actions below
    // would simply run — so this asserts the gate is still there rather than trusting the wiring.
    foreach (['sites.store', 'sites.destroy', 'recovery-keys.store', 'settings.mfa'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Route {$name} has gone.");
        $this->assertContains('password.confirm', $route->gatherMiddleware(), "Route {$name} lost its gate.");
    }
});
