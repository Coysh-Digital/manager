<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Decides whether an outbound webhook URL is safe to request.
 *
 * The specification names a malicious webhook destination as one of the things to assume. The concrete
 * risk is server-side request forgery: a destination pointed at `http://169.254.169.254/` or
 * `http://localhost:5432/` turns "send me a notification" into "make the platform probe its own
 * infrastructure and tell me what it found".
 *
 * Three defences, and all three are needed:
 *
 *  1. **HTTPS only.** A notification says which site has an unpatched security release. That is not
 *     something to broadcast in the clear.
 *  2. **Address ranges blocked.** Loopback, link-local, private, and the cloud metadata address.
 *  3. **The resolved address is pinned.** Checking DNS and then letting the HTTP client resolve it
 *     again leaves a window in which the answer changes — DNS rebinding. So this returns the address
 *     it validated, and the caller connects to that.
 *
 * A hostname resolving to several addresses must have *all* of them acceptable. One good answer
 * alongside one pointing at the metadata service is not a pass.
 */
final class OutboundUrlGuard
{
    /**
     * CIDR ranges no notification may be sent to.
     *
     * @var list<string>
     */
    private const BLOCKED_RANGES = [
        // Loopback.
        '127.0.0.0/8',
        '::1/128',

        // Private, RFC 1918.
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',

        // Carrier-grade NAT, RFC 6598.
        '100.64.0.0/10',

        // Link-local. 169.254.169.254 lives here: the metadata endpoint on AWS, GCP, Azure and
        // DigitalOcean, and the single most valuable target for an SSRF.
        '169.254.0.0/16',
        'fe80::/10',

        // Unique local addresses, the IPv6 equivalent of RFC 1918.
        'fc00::/7',

        // This host, and unspecified.
        '0.0.0.0/8',
        '::/128',

        // Reserved and documentation ranges — no legitimate webhook lives here, and they are the
        // sort of thing used to probe for parsing bugs.
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /**
     * Validate a destination and return the address to connect to.
     *
     * @throws UnsafeDestinationException
     */
    public function resolve(string $url): ResolvedDestination
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeDestinationException('That is not a usable URL.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new UnsafeDestinationException(
                'Webhook destinations must use HTTPS. A notification names which site has an '
                .'outstanding security release, which is not something to send in the clear.'
            );
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 443;

        // Credentials in the URL are refused rather than stripped: a destination written that way was
        // probably not written by the person who will receive it.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeDestinationException('Remove the credentials from the URL.');
        }

        $addresses = $this->addressesFor($host);

        if ($addresses === []) {
            throw new UnsafeDestinationException("Could not resolve {$host}.");
        }

        foreach ($addresses as $address) {
            if ($this->isBlocked($address)) {
                // Neither the host nor the resolved address is echoed. Somebody probing internal
                // ranges through this form should not get resolution results back as a reward for
                // trying.
                throw new UnsafeDestinationException(
                    'That destination is on a private or reserved network, so it cannot receive '
                    .'notifications.'
                );
            }
        }

        return new ResolvedDestination(
            url: $url,
            host: $host,
            port: (int) $port,
            // The first validated address, pinned. The client connects to this rather than resolving
            // again, so the answer cannot change between the check and the request.
            address: $addresses[0],
        );
    }

    /**
     * Whether a destination is acceptable, without throwing.
     */
    public function isSafe(string $url): bool
    {
        try {
            $this->resolve($url);
        } catch (UnsafeDestinationException) {
            return false;
        }

        return true;
    }

    /**
     * Every address a host resolves to.
     *
     * A literal address is returned as-is; anything else is resolved for both families, because a
     * host with a harmless A record and an AAAA record pointing at link-local would otherwise pass.
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        foreach (['A', 'AAAA'] as $type) {
            $records = @dns_get_record($host, $type === 'A' ? DNS_A : DNS_AAAA);

            foreach ($records ?: [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        // gethostbyname as a fallback, for resolvers that answer A queries but not through
        // dns_get_record. It returns the host unchanged on failure, which is why that is filtered out.
        if ($addresses === []) {
            $resolved = gethostbyname($host);

            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $resolved;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isBlocked(string $address): bool
    {
        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an address falls inside a CIDR range.
     *
     * Compared as packed bytes so the same code handles IPv4 and IPv6 without two implementations to
     * keep in step.
     */
    private function inRange(string $address, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range, 2);

        $addressBytes = @inet_pton($address);
        $subnetBytes = @inet_pton($subnet);

        if ($addressBytes === false || $subnetBytes === false) {
            return false;
        }

        // Different families never overlap.
        if (strlen($addressBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $bits = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($addressBytes, $subnetBytes, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($subnetBytes[$wholeBytes]) & $mask);
    }
}
