<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * A failure reason, as somebody reading an email should see it.
 *
 * What arrives is whatever the connector reported, and a connector reports the message of the
 * exception it caught. That is the right thing for it to send - it is exact, and it is what the
 * support conversation needs - but it is not a sentence:
 *
 *     RuntimeException: The platform rejected the request (HTTP 422). This organisation has no room
 *     left for another backup. Correlation ID: 01KZK4EVWHSX9MYD7R5SHTAACN
 *
 * Four things in one line, three of which mean nothing to the person whose backup did not run. The
 * class name is an implementation detail. The status code says the platform refused, which the
 * reader already knows because they are reading an email about a refusal. The correlation identifier
 * is for whoever they forward this to. Only the sentence in the middle says what happened, and it is
 * the one part with no label of its own.
 *
 * So this takes it apart. The sentence becomes the reason, the identifier becomes a row beside it,
 * and the parts that were only ever framing are dropped.
 *
 * **The wire is not touched.** `context.reason` still carries the string exactly as reported - that
 * is what the 1.5.1 notes promised webhook consumers, and a payload is not the place to be helpful.
 * This is a rendering decision and lives on the rendering path.
 *
 * Every step fails safe. Anything that does not match is passed through whole, so an unfamiliar
 * message reaches the reader intact rather than being trimmed into something that misleads them.
 */
final class FailureReason
{
    private function __construct(
        /** The reason as a sentence, with the framing removed. */
        public readonly string $sentence,

        /** The identifier support will ask for, if the reporter included one. */
        public readonly ?string $correlationId,

        /** What to do about it, where this is a failure with a known remedy. */
        public readonly ?string $advice,
    ) {}

    public static function from(string $raw): self
    {
        $text = trim($raw);

        [$text, $correlationId] = self::takeCorrelationId($text);

        $text = self::withoutExceptionClass($text);
        $text = self::withoutRejectionPreamble($text);

        return new self(
            sentence: $text === '' ? trim($raw) : $text,
            correlationId: $correlationId,
            advice: self::adviceFor($text),
        );
    }

    /**
     * Lift the correlation identifier out of the sentence.
     *
     * It goes in a row of its own. A reader forwarding this to support needs it to be findable, and
     * a sentence is where a reference number is least findable - which is also why the connector
     * appends it rather than weaving it in.
     *
     * "unknown" is what the connector sends when the platform's response carried no identifier -
     * usually because something in front of the application answered instead. That is worth nothing
     * to anybody, so it is dropped rather than shown as a row reading "unknown".
     *
     * @return array{0: string, 1: string|null}
     */
    private static function takeCorrelationId(string $text): array
    {
        if (preg_match('~\s*Correlation ID:\s*([A-Za-z0-9]+)\.?\s*$~', $text, $matches) !== 1) {
            return [$text, null];
        }

        $identifier = $matches[1];
        $withoutId = trim(substr($text, 0, -strlen($matches[0])));

        return [
            $withoutId,
            strtolower($identifier) === 'unknown' ? null : $identifier,
        ];
    }

    /**
     * Drop a leading exception class.
     *
     * `RuntimeException: ` and its relatives are how PHP names the thing that went wrong, not how a
     * person does. Matched on the trailing `Exception` or `Error` rather than on any word before a
     * colon, so a genuine sentence that happens to contain one - "Restore failed: the archive is
     * truncated" - keeps it.
     */
    private static function withoutExceptionClass(string $text): string
    {
        return (string) preg_replace('~^\\\\?[A-Za-z0-9_\\\\]*(?:Exception|Error):\s*~', '', $text);
    }

    /**
     * Drop the connector's "the platform said no" framing.
     *
     * The connector wraps every refusal in it, so an email about a refused backup opens by saying it
     * was refused, in a sentence that also carries a status code. What follows is the platform's own
     * explanation, which is the part worth reading.
     *
     * Only when something follows. If the preamble is the whole message - a refusal with no
     * explanation, which is what a proxy answering instead of the application looks like - it is
     * kept, because "" is not a better reason than a vague one.
     */
    private static function withoutRejectionPreamble(string $text): string
    {
        $stripped = preg_replace(
            '~^The platform rejected the (?:request|artifact) \(HTTP \d+\)\.\s*~',
            '',
            $text,
        );

        return $stripped === null || trim($stripped) === '' ? $text : trim($stripped);
    }

    /**
     * The way out, for failures that have one.
     *
     * Only where the remedy is certain from the reason alone. A guess here is worse than silence:
     * somebody who follows advice that does not apply concludes the email is noise, and stops
     * reading the ones that are not.
     *
     * Worded to be true of both editions. Where the storage allowance is changed differs - a
     * subscription on the hosted service, configuration on a self-hosted installation - so this says
     * what to change rather than which screen to change it on.
     */
    private static function adviceFor(string $sentence): ?string
    {
        $reason = strtolower($sentence);

        return match (true) {
            str_contains($reason, 'no room left') => 'This is a storage limit rather than a fault: '
                .'the backup itself was fine, there was nowhere to put it. Shorten the retention on '
                .'this site so older backups are removed sooner, or increase the storage available '
                .'to this organisation. Until one of those changes, every backup for this '
                .'organisation will be refused.',

            str_contains($reason, 'larger than this connector is configured') => 'Raise '
                ."maxBackupMegabytes in the site's config/manager-connector.php, or point that site "
                .'at its own S3 bucket.',

            str_contains($reason, 'no active recovery key') => 'Create a recovery key in Settings. '
                .'Backups are encrypted to it, so there is nothing to encrypt to until one exists.',

            str_contains($reason, 'declared but never uploaded') => 'The site started the upload and '
                .'it did not arrive. Check the site can reach this installation, then run the backup '
                .'again.',

            str_contains($reason, 'did not match the declared checksum') => 'The bytes that arrived '
                .'were not the bytes the site hashed. Run the backup again; if it repeats, the path '
                .'between the two is altering data.',

            default => null,
        };
    }

    /**
     * The reason as a sentence, for a caller that wants only that.
     *
     * A convenience for the screens, which render this in half a dozen places and want the string
     * rather than the object.
     */
    public static function sentence(?string $raw): string
    {
        return $raw === null || trim($raw) === '' ? '' : self::from($raw)->sentence;
    }
}
