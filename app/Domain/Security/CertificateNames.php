<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Whether a certificate covers the name it was served on.
 *
 * Its own class rather than a private method on {@see CertificateInspector}, because this is the
 * subtlest logic in the certificate check and the only part of it that can be wrong quietly. The
 * inspector's other judgements come back from OpenSSL as a yes or a no; this one is a rule that has
 * to be implemented, and implementing it slightly loosely produces a check that passes certificates
 * a browser refuses - which is worse than not checking at all, because somebody is now relying on it.
 *
 * Two rules do the work, and both are narrower than people expect.
 *
 * **Subject alternative names win outright.** A certificate carrying SANs is judged on them alone and
 * its common name is decorative - every browser has worked this way since 2017. Falling back to the
 * CN when SANs are present would pass certificates that no visitor's browser accepts.
 *
 * **A wildcard matches exactly one label, and only the leftmost one.** `*.example.org` covers
 * `www.example.org`. It does not cover `example.org` itself, and it does not cover
 * `a.b.example.org`. Both of those are live misconfigurations rather than edge cases - a site on the
 * apex holding only a wildcard certificate is an outage - so matching them loosely here would hide
 * exactly the thing this exists to find.
 */
final class CertificateNames
{
    /**
     * Whether a parsed certificate covers a host.
     *
     * Null when the certificate names nothing at all to compare against, which is not the same as a
     * mismatch: one is a certificate that fails, the other is a check with nothing to go on.
     *
     * @param  array<string, mixed>  $parsed  the output of openssl_x509_parse()
     */
    public static function matches(array $parsed, string $host): ?bool
    {
        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '') {
            return null;
        }

        $names = self::subjectAltNames($parsed);

        if ($names === []) {
            $subject = $parsed['subject'] ?? null;
            $common = is_array($subject) ? ($subject['CN'] ?? null) : null;

            if (! is_string($common) || trim($common) === '') {
                return null;
            }

            $names = [$common];
        }

        foreach ($names as $name) {
            if (self::covers($name, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The DNS entries from the subject alternative name extension.
     *
     * OpenSSL hands this over as a single string - `DNS:example.org, DNS:*.example.org, IP:203.0.113.4`
     * - so it is split rather than iterated.
     *
     * Anything that is not a DNS entry is dropped rather than compared. An `IP:` entry cannot cover a
     * hostname, and an `email:` or `URI:` entry is not about names at all; treating any of them as a
     * candidate would be a way to pass a certificate a browser refuses.
     *
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private static function subjectAltNames(array $parsed): array
    {
        $extensions = $parsed['extensions'] ?? null;
        $raw = is_array($extensions) ? ($extensions['subjectAltName'] ?? null) : null;

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $names = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if (stripos($entry, 'DNS:') !== 0) {
                continue;
            }

            $name = trim(substr($entry, 4));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Whether one name from a certificate covers the host that was asked for.
     */
    private static function covers(string $name, string $host): bool
    {
        $name = strtolower(rtrim(trim($name), '.'));

        if ($name === '') {
            return false;
        }

        if (! str_starts_with($name, '*.')) {
            return $name === $host;
        }

        // Everything from the dot onwards, so `*.example.org` becomes `.example.org` - which keeps the
        // separator in the comparison. Without it, `*.example.org` would cover `notexample.org`.
        $suffix = substr($name, 1);

        if (! str_ends_with($host, $suffix)) {
            return false;
        }

        $label = substr($host, 0, -strlen($suffix));

        // One label, and a real one. An empty label is the apex, which a wildcard does not cover.
        return $label !== '' && ! str_contains($label, '.');
    }
}
