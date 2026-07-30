<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\NotificationDestination;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers a notification to an HTTPS endpoint.
 *
 * Written on the assumption that the destination is hostile, because the specification says to assume
 * exactly that. Everything below follows from it:
 *
 *  - The URL is re-validated on **every** send, not only when it was added. A hostname that was
 *    harmless last week can point at the metadata service today.
 *  - The connection is pinned to the address the guard validated, so DNS cannot change underneath.
 *  - Redirects are not followed. A 302 to `http://169.254.169.254/` is the simplest way around an
 *    allowlist that only checks the URL it was given.
 *  - The timeout is short and the body is bounded. A destination that accepts a connection and never
 *    responds must not be able to hold a worker.
 *  - The response body is discarded. Nothing a destination returns is read, parsed or logged, because
 *    there is nothing it could usefully say and plenty it could try.
 *
 * The body is signed so a receiver can distinguish a genuine notification from anything else that
 * finds the URL.
 */
final class WebhookTransport
{
    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @return array{outcome: string, status_code: int|null, failure_reason: string|null, duration_ms: int}
     */
    public function send(NotificationDestination $destination, NotificationEvent $event): array
    {
        $startedAt = microtime(true);

        try {
            // Re-validated every time. Checking only at creation would mean a destination stays
            // trusted after its DNS changes, which is the entire attack.
            $resolved = $this->guard->resolve($destination->target);
        } catch (UnsafeDestinationException $e) {
            return $this->failure($e->getMessage(), $startedAt);
        }

        $body = json_encode($event->toPayload(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->timestamp;
        $signature = $this->sign($destination, $timestamp, $body);

        try {
            $response = $this->client($resolved)->post($resolved->url, [
                'body' => $body,
                'headers' => array_filter([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Manager/1.0 (+notifications)',

                    // Everything a receiver needs to verify this came from us and is not a replay.
                    'Manager-Event' => $event->type,
                    'Manager-Timestamp' => $timestamp,
                    'Manager-Delivery' => (string) Str::ulid(),
                    'Manager-Signature' => $signature === null ? null : 'v1='.$signature,
                ]),
            ]);
        } catch (Throwable $e) {
            // The exception message can carry the resolved address, so it is not passed through.
            return $this->failure($this->describe($e), $startedAt);
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return [
                'outcome' => 'sent',
                'status_code' => $status,
                'failure_reason' => null,
                'duration_ms' => $this->elapsed($startedAt),
            ];
        }

        return [
            'outcome' => 'failed',
            'status_code' => $status,
            // The status, never the body. A hostile destination's response is not something to store.
            'failure_reason' => "The destination answered HTTP {$status}.",
            'duration_ms' => $this->elapsed($startedAt),
        ];
    }

    /**
     * HMAC over the timestamp and body, so a receiver can verify both origin and integrity.
     *
     * The timestamp is inside the signed material, which lets a receiver reject a replayed delivery
     * rather than only an altered one.
     */
    public function sign(NotificationDestination $destination, string $timestamp, string $body): ?string
    {
        $secret = $destination->signing_secret;

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return hash_hmac('sha256', $timestamp."\n".$body, $secret);
    }

    private function client(ResolvedDestination $resolved): Client
    {
        return new Client([
            // Short. A destination that accepts a connection and stalls must not hold a worker.
            'connect_timeout' => 5,
            'timeout' => 10,

            // A 302 to a private address is the simplest way around a check on the original URL.
            'allow_redirects' => false,

            // A non-2xx is information, not an exception.
            'http_errors' => false,

            // Nothing the destination sends back is read. Discarding it means a hostile endpoint
            // cannot stream a large body at us either.
            'sink' => '/dev/null',

            'curl' => [
                // Pins the connection to the address the guard validated, so the hostname is not
                // resolved a second time.
                CURLOPT_RESOLVE => [$resolved->curlResolveEntry()],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ],
        ]);
    }

    /**
     * @return array{outcome: string, status_code: null, failure_reason: string, duration_ms: int}
     */
    private function failure(string $reason, float $startedAt): array
    {
        return [
            'outcome' => 'failed',
            'status_code' => null,
            'failure_reason' => Str::limit($reason, 200),
            'duration_ms' => $this->elapsed($startedAt),
        ];
    }

    /**
     * A failure description safe to store.
     *
     * A transport exception can carry the resolved address and the full URL. The class name and a
     * short category are enough to tell an operator what went wrong.
     */
    private function describe(Throwable $e): string
    {
        $short = (new \ReflectionClass($e))->getShortName();

        return match (true) {
            str_contains($short, 'ConnectException') => 'Could not connect to the destination.',
            str_contains($short, 'TooManyRedirects') => 'The destination redirected, which is not followed.',
            default => "Delivery failed ({$short}).",
        };
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
