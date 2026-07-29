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
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, window: 1);
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
