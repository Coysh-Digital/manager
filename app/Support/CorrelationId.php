<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * One identifier per request, threaded through logs, audit events and connector responses.
 *
 * When a connector reports that something failed, this is what lets the failure be traced through
 * the platform without the connector having to be told anything about internal structure. It is
 * returned on every connector response, including rejections.
 *
 * Bound as a singleton, so everything within a request agrees on the value.
 */
final class CorrelationId
{
    private ?string $value = null;

    public function get(): string
    {
        return $this->value ??= (string) Str::ulid();
    }

    /**
     * Adopt an identifier chosen elsewhere, such as by a queued job continuing earlier work.
     */
    public function set(string $value): void
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->get();
    }
}
