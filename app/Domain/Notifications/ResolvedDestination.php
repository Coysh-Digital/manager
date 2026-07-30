<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * A destination that passed the guard, with the address it was validated against.
 *
 * The address matters as much as the URL. Connecting by hostname would resolve DNS a second time, and
 * an answer that changed in between is the whole DNS-rebinding attack — so the caller connects to
 * this address and sets the Host header from the hostname.
 */
final class ResolvedDestination
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly string $address,
    ) {}

    /**
     * The curl resolve directive that pins the connection to the validated address.
     */
    public function curlResolveEntry(): string
    {
        return sprintf('%s:%d:%s', $this->host, $this->port, $this->address);
    }
}
