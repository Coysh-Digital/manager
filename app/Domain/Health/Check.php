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
    /**
     * Who a check is for.
     *
     * Almost every one of these is about the machine: APP_KEY, the database role, migrations, the
     * queue, the session cookie, the disk. On an installation somebody runs themselves that is the
     * reader; on a hosted one it is us, and a red row a customer cannot act on is alarming rather
     * than informative — it invites a support ticket whose answer is "yes, we know, that is ours".
     *
     * Operator is the default deliberately. A new check is about the platform until somebody
     * decides otherwise, so forgetting to think about this hides a row rather than leaking one.
     */
    public const OPERATOR = 'operator';

    /** For anybody, including a customer of a hosted edition. */
    public const EVERYONE = 'everyone';

    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    private function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $detail,
        public readonly ?string $remedy = null,
        public readonly string $audience = self::OPERATOR,
    ) {}

    /**
     * Mark this check as one a customer should see.
     *
     * Two things have to be true, and both of them: it describes the customer's own data or their
     * own security posture rather than our infrastructure, *and* it is either something they can
     * act on or a promise they were sold and are entitled to see kept. "The queue is running" is
     * neither. "Your audit log cannot be edited" is the second.
     */
    public function forEveryone(): self
    {
        return new self($this->name, $this->status, $this->detail, $this->remedy, self::EVERYONE);
    }

    public function isForOperator(): bool
    {
        return $this->audience === self::OPERATOR;
    }

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
