<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * The result of one diagnostic check.
 *
 * Three outcomes rather than two. A warning is for something an operator should know about but
 * which is not stopping the platform working — mail not yet verified, say. Collapsing warnings into
 * failures gets the whole report ignored; collapsing them into passes hides the thing that bites
 * later.
 */
final class Check
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    private function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $detail,
        public readonly ?string $remedy = null,
    ) {}

    public static function pass(string $name, string $detail): self
    {
        return new self($name, self::PASS, $detail);
    }

    public static function warn(string $name, string $detail, ?string $remedy = null): self
    {
        return new self($name, self::WARN, $detail, $remedy);
    }

    public static function fail(string $name, string $detail, ?string $remedy = null): self
    {
        return new self($name, self::FAIL, $detail, $remedy);
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }

    public function warned(): bool
    {
        return $this->status === self::WARN;
    }
}
