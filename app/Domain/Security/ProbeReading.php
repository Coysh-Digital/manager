<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * What a site served to an ordinary request from outside it, or why it served nothing.
 *
 * Everything here is a fact about the response, never about its contents. No body is read, stored or
 * looked at anywhere in this check - what a site publishes is its own business, and a monitoring
 * tool that kept a copy of it would be holding something nobody asked it to hold.
 *
 * The one field that needs explaining before the rest make sense is {@see $answersEverything}. Some
 * sites are configured to return 200 for any path at all - a catch-all route, a single-page front
 * end, a proxy with a permissive fallback - and on one of those, asking "does `/.env` return 200?"
 * gets a yes that means nothing. So the probe also asks for a path that certainly does not exist,
 * and if *that* answers 200 the exposure results are not evidence of anything and are reported as
 * inconclusive rather than as a critical finding. Without that control, the sites with the loosest
 * routing would be the ones told their environment file is public.
 */
final class ProbeReading
{
    /**
     * @param  array<string, string>  $headers  response headers, lowercased names, allowlisted
     * @param  list<string>  $duplicated  header names the site sent more than once
     * @param  list<array{path: string, status: int, bytes: int|null}>  $exposed
     */
    public function __construct(
        public readonly ?int $status = null,
        public readonly array $headers = [],
        public readonly array $duplicated = [],
        public readonly ?bool $redirectsToHttps = null,
        public readonly array $exposed = [],
        public readonly ?bool $answersEverything = null,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $reason): self
    {
        return new self(error: $reason);
    }

    public function succeeded(): bool
    {
        return $this->status !== null && $this->error === null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The shape stored on the report row.
     *
     * Empty sections are dropped rather than stored as empty, so a probe made by a build that could
     * not answer something is distinguishable from one that answered "none" - the same reason every
     * section of the connector's own reports is optional.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
            'status' => $this->status,
            'headers' => $this->headers,
            'duplicated' => $this->duplicated,
            'redirects_to_https' => $this->redirectsToHttps,
            'exposed' => $this->exposed,
            'answers_everything' => $this->answersEverything,
            'error' => $this->error,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
