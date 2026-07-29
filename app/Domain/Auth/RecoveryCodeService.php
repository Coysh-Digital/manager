<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Single-use fallbacks for when an authenticator is lost.
 *
 * Codes are hashed, so a database copy does not yield working second factors. They are shown once
 * at generation and cannot be shown again: losing them means generating a fresh set, which
 * invalidates the old.
 */
final class RecoveryCodeService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Replace a user's codes and return the new plaintext set.
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        $count = (int) config('manager.auth.recovery_code_count');
        $codes = [];

        DB::transaction(function () use ($user, $count, &$codes): void {
            // Replaced wholesale rather than topped up, so "you have ten codes" is always true
            // straight after generating them.
            $user->recoveryCodes()->delete();

            for ($i = 0; $i < $count; $i++) {
                $code = $this->generateCode();
                $codes[] = $code;

                RecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => Hash::make($code),
                ]);
            }

            $this->audit->record(
                action: 'user.recovery_codes.regenerated',
                actor: $user,
                targetType: 'user',
                targetId: $user->external_id,
                // The count, never the codes.
                after: ['count' => $count],
            );
        });

        return $codes;
    }

    /**
     * Consume a recovery code if it matches an unused one.
     *
     * Every unused code has to be hashed against, because they are stored hashed and there is
     * nothing to look up by. That is a handful of hash comparisons on an interactive login path,
     * which is a fair price for not storing them in a form that could be looked up.
     */
    public function consume(User $user, string $submitted): bool
    {
        $submitted = trim($submitted);

        if ($submitted === '') {
            return false;
        }

        foreach ($user->recoveryCodes()->whereNull('used_at')->get() as $candidate) {
            if (! Hash::check($submitted, $candidate->code_hash)) {
                continue;
            }

            // Marked rather than deleted, so "three left, one used on Tuesday" is answerable.
            $candidate->forceFill(['used_at' => now()])->save();

            $this->audit->record(
                action: 'user.recovery_code.used',
                actor: $user,
                targetType: 'user',
                targetId: $user->external_id,
                after: ['remaining' => $user->unusedRecoveryCodeCount()],
            );

            return true;
        }

        return false;
    }

    /**
     * Readable, unambiguous, and long enough that guessing is not a strategy.
     */
    private function generateCode(): string
    {
        // Crockford-style alphabet: no I, L, O, U, so a code read aloud or copied off a printout
        // cannot be mistyped into a different valid-looking one.
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $halves = [];

        foreach ([0, 1] as $ignored) {
            $half = '';

            for ($i = 0; $i < 5; $i++) {
                $half .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $halves[] = $half;
        }

        unset($ignored);

        return implode('-', $halves);
    }

    /**
     * Whether a string looks like one of our codes, before any hashing happens.
     */
    public static function looksLikeRecoveryCode(string $value): bool
    {
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/i', trim($value));
    }
}
