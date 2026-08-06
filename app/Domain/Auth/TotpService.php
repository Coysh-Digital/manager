<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * Time-based one-time passwords.
 *
 * The QR code is rendered locally as an SVG rather than fetched from a chart service. Handing a
 * TOTP secret to a third party to draw a picture of it would defeat the purpose of having one.
 */
final class TotpService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * A new secret, not yet attached to anyone.
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * Verify a code against a secret.
     *
     * A one-step window either side absorbs ordinary clock drift between a phone and the server.
     * Wider than that and a captured code stays usable for long enough to be worth capturing.
     */
    public function verify(#[SensitiveParameter] string $secret, string $code): bool
    {
        return $this->verifyAndStep($secret, $code) !== false;
    }

    /**
     * Verify a code for an account, and refuse one that has already been used.
     *
     * A code is valid for its own 30-second step and for one step either side, so any given code is
     * accepted for roughly ninety seconds. Nothing recorded that a code had been spent, so within
     * that window the same six digits worked again - shoulder-surfed, read off a lock-screen
     * notification, or captured by anything sitting in front of the login form. The second factor
     * stopped being something you have and became something briefly observable.
     *
     * The last accepted step is recorded and every later attempt must be strictly newer. That is the
     * property {@see self::verify()} cannot have on its own: it takes a secret and a code and has
     * nowhere to remember anything.
     *
     * Written before the caller acts on the result, so a code cannot be spent twice by two requests
     * arriving together.
     */
    public function verifyOnce(User $user, string $code): bool
    {
        $step = $this->verifyAndStep((string) $user->totp_secret, $code, $user->totp_last_used_step);

        if ($step === false) {
            return false;
        }

        $user->forceFill(['totp_last_used_step' => $step])->save();

        return true;
    }

    /**
     * The step a code is valid for, or false.
     *
     * A one-step window either side absorbs ordinary clock drift between a phone and the server.
     * Wider than that and a captured code stays usable for long enough to be worth capturing.
     *
     * `$lastUsedStep` is what makes a code single-use, and the library does the work rather than
     * this class comparing afterwards: given an old step it starts its search at `$lastUsedStep + 1`
     * instead of at `now - window`, so a code from a step already spent is not merely rejected on
     * comparison - it is never a candidate.
     *
     * Zero rather than null when nothing has been used yet, and the distinction is not cosmetic.
     * `verifyKey()` returns a **bool** when the old step is null and the **step** when it is not, so
     * passing null would lose the very thing this method exists to return. Zero is far enough in the
     * past that `max(now - window, 1)` leaves the ordinary window search exactly as it was.
     */
    public function verifyAndStep(#[SensitiveParameter] string $secret, string $code, ?int $lastUsedStep = null): int|false
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code) || $secret === '') {
            return false;
        }

        $step = $this->google2fa->verifyKeyNewer($secret, $code, $lastUsedStep ?? 0, 1);

        return is_int($step) ? $step : false;
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     */
    public function provisioningUri(User $user, #[SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * An inline SVG QR code for the provisioning URI.
     *
     * Returned as markup rather than a URL so nothing about enrolling a second factor leaves this
     * server.
     */
    public function qrCodeSvg(User $user, #[SensitiveParameter] string $secret): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(200, 0),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($this->provisioningUri($user, $secret));
    }

    /**
     * Break a secret into groups, for people typing it in by hand.
     */
    public function formatForManualEntry(#[SensitiveParameter] string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
