<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Notifications\OutboundUrlGuard;
use App\Domain\Notifications\UnsafeDestinationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reading the TLS certificate a site's visitors actually see.
 *
 * This is the one thing in Manager the platform goes and looks at itself, and the departure is worth
 * justifying rather than sliding past. Everything else is reported by the connector, deliberately: a
 * platform that reaches into the sites it manages is a platform worth attacking, and a connector that
 * only ever speaks outbound needs no inbound firewall rule.
 *
 * A certificate is the exception because the connector cannot see it. TLS is terminated at the edge —
 * a CDN, a load balancer, a reverse proxy - and PHP on the origin sees whatever that proxy chose to
 * put in `$_SERVER`. Asking the site would produce a number that is confidently wrong on exactly the
 * sites where it matters most. The only way to know what a visitor's browser validates is to be one.
 *
 * So the constraints are the ones any outbound request in this application has:
 *
 *  - **The hostname is one an operator typed**, not one that arrived in a payload. It is the site's
 *    `expected_domain`, which is also what pairing is bound to.
 *  - **Guarded against loopback, private and metadata addresses** by the same {@see OutboundUrlGuard}
 *    that guards notification destinations. A site whose domain resolves to `169.254.169.254` would
 *    otherwise turn a monitoring check into a request for cloud credentials.
 *  - **Read only.** It opens a socket, completes a handshake, reads the peer certificate and closes.
 *    Nothing is sent, no HTTP request is made, and no response body is ever read.
 *  - **Bounded.** A short timeout, because a fleet check that hangs on one unreachable host is a fleet
 *    check that never finishes.
 *
 * Verification is deliberately **off** at the socket level. That looks wrong and is not: the job is to
 * report on a certificate including when it is expired, self-signed or misissued, and a verifying
 * connection refuses those instead of describing them. Nothing is trusted as a result - no data
 * crosses this connection in either direction, and the certificate is evidence rather than
 * authentication.
 */
final class CertificateInspector
{
    /**
     * Seconds to wait for a handshake.
     *
     * Short. A site that cannot complete a handshake in five seconds has a problem this check will
     * report either way, and a fleet sweep must not be held up by one of them.
     */
    private const TIMEOUT = 5;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * Look at a host's certificate.
     *
     * Never throws. A certificate check that could fail a scheduled command would mean one unreachable
     * site stopping the sweep for every other one.
     */
    public function inspect(string $host, int $port = 443): CertificateReading
    {
        $host = strtolower(trim($host));

        if ($host === '' || ! preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $host)) {
            return CertificateReading::failed('That is not a hostname this check can look up.');
        }

        try {
            // Reuses the guard rather than reimplementing it. It resolves every address the host has,
            // for both families, so a domain with a harmless A record and an AAAA record pointing at
            // link-local does not slip through.
            $this->guard->resolve('https://'.$host);
        } catch (UnsafeDestinationException) {
            return CertificateReading::failed(
                'That domain resolves to a private, loopback or metadata address.'
            );
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,

                // Off on purpose. The point is to report on a certificate that may be expired,
                // self-signed or for the wrong name, and a verifying connection refuses those rather
                // than describing them. Nothing is trusted as a result: no data crosses this
                // connection, and the certificate is evidence rather than authentication.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            // The system's message can name an IP, a path or a resolver. Reduced to a fixed phrase
            // rather than passed through, because this string is stored and shown.
            return CertificateReading::failed('The site did not complete a TLS handshake.');
        }

        try {
            $params = stream_context_get_params($client);
            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($certificate === null) {
                return CertificateReading::failed('The site presented no certificate.');
            }

            $parsed = openssl_x509_parse($certificate);

            if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
                return CertificateReading::failed('The certificate could not be read.');
            }

            return new CertificateReading(
                expiresAt: Carbon::createFromTimestamp((int) $parsed['validTo_time_t']),
                issuer: $this->name($parsed['issuer'] ?? []),
                subject: $this->name($parsed['subject'] ?? []),
                error: null,
            );
        } catch (Throwable) {
            return CertificateReading::failed('The certificate could not be read.');
        } finally {
            fclose($client);
        }
    }

    /**
     * A readable name from an X.509 name array.
     *
     * @param  array<string, mixed>  $name
     */
    private function name(array $name): ?string
    {
        foreach (['CN', 'O', 'OU'] as $field) {
            $value = $name[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return null;
    }
}
