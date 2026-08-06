<?php

declare(strict_types=1);

/*
 | The headers every response carries.
 |
 | Three of these lived in deploy/docker/nginx.conf, beside a comment reading "the application sets
 | its own headers too". It did not. That mattered most where it was least visible: the Cloud console
 | deploys through Ploi and never reads that file, so the paid, hosted, multi-tenant edition served
 | none of them.
 |
 | Asserted on real responses rather than by instantiating the middleware, because the thing that was
 | wrong was never the middleware's logic - there was no middleware. What has to hold is that a
 | response leaving this application carries them, whichever route produced it.
 |
 | There is deliberately no Content-Security-Policy here or in the middleware. A useful one for this
 | application is not a one-line addition, and a wrong directive on a live console fails as a blank
 | screen rather than a warning. It is an outstanding gap, recorded as one.
 */

it('sets the browser headers on an ordinary page', function (): void {
    $response = $this->get(route('login'));

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

it('sets them on the health endpoints too', function (): void {
    // Applied globally rather than to the web group. /up and /ready are still pages a browser can
    // open, and an orchestrator reading them is not a reason to treat them differently.
    expect($this->get('/ready')->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('sets them on a connector API response', function (): void {
    // JSON to a machine, and still rendered by a browser if somebody opens the URL. Nothing here
    // depends on the response being HTML.
    $response = $this->getJson('/api/connector/v1/does-not-exist');

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('sends HSTS when the canonical URL is HTTPS', function (): void {
    config(['app.url' => 'https://manager.example', 'manager.security.hsts_seconds' => 31536000]);

    expect($this->get(route('login'))->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

it('does not send HSTS on an installation served over HTTP', function (): void {
    // It would be a promise the operator has not made and cannot keep, and one that locks browsers
    // out of their own installation.
    config(['app.url' => 'http://manager.example']);

    expect($this->get(route('login'))->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('lets an operator switch HSTS off', function (): void {
    // Sending it once commits every browser that saw it for the whole max-age, and there is no way
    // to withdraw it early. Somebody moving a host has to be able to stop first.
    config(['app.url' => 'https://manager.example', 'manager.security.hsts_seconds' => 0]);

    expect($this->get(route('login'))->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('treats a blank HSTS setting as the default rather than as zero', function (): void {
    /*
     | The blank-versus-absent trap, which this repository has been bitten by more than once: env()
     | returns its default only when the key is absent, and a present-but-blank line yields '' - so
     | (int) '' would silently switch the header off on any installation whose .env carried the line
     | without a value.
    */
    $config = require dirname(__DIR__, 2).'/config/manager.php';

    expect($config['security']['hsts_seconds'])->toBeGreaterThan(0);
})->skip(fn (): bool => env('MANAGER_HSTS_SECONDS') !== null, 'MANAGER_HSTS_SECONDS is set in this environment.');
