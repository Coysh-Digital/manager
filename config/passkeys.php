<?php

declare(strict_types=1);

return [

    /*
    |----------------------------------------------------------------------------------------------
    | Relying party
    |----------------------------------------------------------------------------------------------
    |
    | The domain a credential is bound to. A passkey created here will not verify anywhere else,
    | which is what makes it phishing-resistant: a convincing copy of this interface on another
    | domain cannot ask the authenticator for an assertion this platform would accept.
    |
    | Derived from APP_URL rather than configured separately, so it cannot drift from the address the
    | interface is actually served on. The hardening check already refuses an unset APP_URL, which is
    | the same thing that would leave this null.
    |
    */

    'relying_party_id' => parse_url((string) config('app.url'), PHP_URL_HOST),

    /*
    |----------------------------------------------------------------------------------------------
    | Allowed origins
    |----------------------------------------------------------------------------------------------
    |
    | The origins permitted to complete a ceremony. One entry, and deliberately not readable from the
    | environment: every additional origin is another place a credential for this platform can be
    | exercised from, and a self-hosted installation has one front door.
    |
    */

    'allowed_origins' => [
        (string) config('app.url'),
    ],

    /*
    |----------------------------------------------------------------------------------------------
    | User handle secret
    |----------------------------------------------------------------------------------------------
    |
    | The user handle is stored on the authenticator itself, so it must not be an email address or
    | anything else that identifies a person to whoever picks the device up. It is derived as an HMAC
    | of the primary key under this secret instead.
    |
    | Set PASSKEYS_USER_HANDLE_SECRET explicitly before rotating APP_KEY. Rotating the application key
    | while this is unset changes every derived handle, which orphans every passkey already
    | registered - an account with no other second factor would be locked out.
    |
    | `?:` rather than an env() default, deliberately. A variable that is present but empty - which is
    | how half the entries in .env.example start life - would otherwise be taken at its word and used
    | as an empty HMAC key. Falling back on blank as well as on absent is the difference between a
    | derived handle and a constant one.
    |
    */

    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET') ?: config('app.key'),

    /*
    |----------------------------------------------------------------------------------------------
    | Ceremony timeout
    |----------------------------------------------------------------------------------------------
    |
    | How long the browser gives somebody to touch a key or present a fingerprint, in milliseconds.
    |
    */

    'timeout' => 60000,

    /*
    |----------------------------------------------------------------------------------------------
    | Authentication guard
    |----------------------------------------------------------------------------------------------
    */

    'guard' => 'web',

    /*
    |----------------------------------------------------------------------------------------------
    | Routes
    |----------------------------------------------------------------------------------------------
    |
    | Unused, and left here deliberately rather than deleted.
    |
    | The package's own routes are switched off in AppServiceProvider, because one of them signs
    | somebody in on a passkey alone. Here a passkey is a second factor rather than a credential in
    | its own right, so every passkey endpoint this application serves is declared in routes/web.php
    | against its own controllers.
    |
    | These keys stay because the package reads them while registering nothing, and somebody
    | comparing this file against the package default should not have to work out whether their
    | absence means something.
    |
    */

    'middleware' => ['web'],

    'management_middleware' => ['password.confirm'],

    'throttle' => 'throttle:6,1',

    'redirect' => '/',

];
