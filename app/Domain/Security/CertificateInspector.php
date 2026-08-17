<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Notifications\OutboundUrlGuard;
use App\Domain\Notifications\UnsafeDestinationException;
use Illuminate\Support\Carbon;
use OpenSSLCertificate;
use Throwable;

/**
 * Reading the TLS certificate a site's visitors actually see, and judging it.
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
 * ## Why there are two handshakes, and why the verifying one goes first
 *
 * This used to make exactly one connection, with verification off, and the comment explaining that
 * was right about half of the problem. Verification off is genuinely necessary: the job is to report
 * on a certificate *including* when it is expired, self-signed or misissued, and a verifying
 * connection refuses those instead of describing them.
 *
 * What that reasoning missed is that refusing is itself the answer to a different question. A
 * permissive handshake can read an expiry and an issuer, and it cannot tell you whether a browser
 * would accept the chain - so a site serving a certificate for somebody else's domain, or missing its
 * intermediate, read as perfectly healthy right up until a visitor saw the interstitial.
 *
 * So: the verifying handshake runs **first**, and on a site with nothing wrong it is the only one that
 * runs. It answers trust, hostname and self-signature by succeeding. Only when it fails does the
 * permissive handshake run, to describe what the verifying one refused. That ordering matters for the
 * fleet sweep - the healthy majority costs one connection, and the second is spent only on sites that
 * have something to explain.
 *
 * A failure of *both* is not a certificate problem. It is a host that did not answer, and it is
 * reported as one: the judgements stay null rather than becoming false, because "unreachable" and
 * "untrusted" send somebody to look at completely different things.
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

        // Whether this server can judge trust at all. A container with no CA bundle would otherwise
        // report every site in the fleet as untrusted on the same morning, which is the single most
        // expensive way for this check to be wrong.
        $canVerify = $this->hasCertificateAuthorities();

        if ($canVerify) {
            $verified = $this->handshake($host, $port, verifying: true);

            if ($verified !== null) {
                // It verified. Trust, hostname and self-signature are all answered by that, and there
                // is nothing left for a second connection to find out.
                return $this->describe($host, $verified, trusted: true);
            }
        }

        $permissive = $this->handshake($host, $port, verifying: false);

        if ($permissive === null) {
            // Neither handshake completed. That is a host that did not answer - DNS, a firewall, a
            // site that has moved - and calling it a certificate problem would send somebody to look
            // at the wrong thing.
            return CertificateReading::failed('The site did not complete a TLS handshake.');
        }

        // The permissive handshake worked where the verifying one did not, so the refusal was about
        // the certificate rather than about reaching the host. Unless this server had no authorities
        // to judge against, in which case nothing was refused and trust is simply unknown.
        return $this->describe($host, $permissive, trusted: $canVerify ? false : null);
    }

    /**
     * Complete one handshake and keep what it presented.
     *
     * Null on any failure, with no distinction between the kinds - the caller derives meaning from
     * *which* of the two handshakes failed, and a reason string here would be a system message that
     * can name an IP, a path or a resolver.
     *
     * @return array{certificate: OpenSSLCertificate, chain: int}|null
     */
    private function handshake(string $host, int $port, bool $verifying): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,

                // Only ever the count is kept from this. The chain is read to answer "did the server
                // send its intermediate", which is a number, not a list of certificates to store.
                'capture_peer_cert_chain' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,

                'verify_peer' => $verifying,
                'verify_peer_name' => $verifying,
                'allow_self_signed' => ! $verifying,
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
            return null;
        }

        try {
            $params = stream_context_get_params($client);
            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

            if (! $certificate instanceof OpenSSLCertificate) {
                return null;
            }

            $chain = $params['options']['ssl']['peer_certificate_chain'] ?? null;

            return [
                'certificate' => $certificate,
                'chain' => is_array($chain) ? count($chain) : 0,
            ];
        } catch (Throwable) {
            return null;
        } finally {
            fclose($client);
        }
    }

    /**
     * Turn one handshake's certificate into a reading.
     *
     * @param  array{certificate: OpenSSLCertificate, chain: int}  $result
     */
    private function describe(string $host, array $result, ?bool $trusted): CertificateReading
    {
        try {
            $parsed = openssl_x509_parse($result['certificate']);
        } catch (Throwable) {
            return CertificateReading::failed('The certificate could not be read.');
        }

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return CertificateReading::failed('The certificate could not be read.');
        }

        return new CertificateReading(
            expiresAt: Carbon::createFromTimestamp((int) $parsed['validTo_time_t']),
            issuer: $this->name($parsed['issuer'] ?? []),
            subject: $this->name($parsed['subject'] ?? []),
            error: null,
            hostnameMatches: CertificateNames::matches($parsed, $host),
            trusted: $trusted,
            selfSigned: $this->isSelfSigned($parsed),

            // Zero would read on a screen as "this server sent no certificates", which cannot be true
            // of a handshake that produced one. It means the chain was not captured.
            chainLength: $result['chain'] > 0 ? $result['chain'] : null,
        );
    }

    /**
     * Whether the certificate signed itself.
     *
     * Compared as the parsed name arrays rather than as the readable strings this class renders
     * elsewhere: {@see name()} returns the first of CN, O or OU that is set, so two genuinely
     * different names sharing an organisation would compare equal and a real certificate would be
     * reported as self-signed.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function isSelfSigned(array $parsed): ?bool
    {
        $issuer = $parsed['issuer'] ?? null;
        $subject = $parsed['subject'] ?? null;

        if (! is_array($issuer) || ! is_array($subject) || $issuer === [] || $subject === []) {
            return null;
        }

        return $issuer == $subject;
    }

    /**
     * Whether this server has anything to verify a chain against.
     *
     * Worth checking rather than assuming. A minimal container with no `ca-certificates` package
     * verifies nothing, and without this the first sweep after deploying to one would open a
     * high-severity finding against every site in the fleet on the same morning - all of them wrong,
     * and all of them about this server rather than about the sites named in them.
     */
    private function hasCertificateAuthorities(): bool
    {
        $locations = openssl_get_cert_locations();

        $file = $locations['default_cert_file'] ?? null;
        $directory = $locations['default_cert_dir'] ?? null;

        if (is_string($file) && $file !== '' && is_readable($file)) {
            return true;
        }

        return is_string($directory) && $directory !== '' && is_dir($directory);
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
