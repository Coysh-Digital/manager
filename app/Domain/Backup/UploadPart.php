<?php

declare(strict_types=1);

namespace App\Domain\Backup;

/**
 * Permission to write one part of one object.
 *
 * An artifact past five gigabytes cannot be sent in a single request, so a grant may describe a
 * sequence of these instead. Each is a presigned request in its own right, and each carries exactly
 * what {@see UploadGrant} carries: a path, a query string and headers.
 *
 * **There is no host here either, and there must never be one.** That is the point of stating this as
 * its own class rather than as a loose array. A multipart upload is the first time an artifact travels
 * as more than one request, which makes it precisely the change during which a host, a bucket or a
 * fully-formed URL would arrive "for convenience" — and the connector's build check looks for those
 * names on the classes it already knows about. It now has one more to look at.
 *
 * The query string is a bearer credential, exactly as on a whole grant. Anybody holding it can write
 * that part until it expires, so it must never reach an audit row, a log line or a job payload.
 */
final class UploadPart
{
    /**
     * @param  int  $number  1-based, consecutive; object stores require both
     * @param  string  $path  absolute object path, beginning with a slash
     * @param  string  $query  presigned query string, without the leading question mark
     * @param  array<string, string>  $headers  headers this part must send for the signature to hold
     */
    public function __construct(
        public readonly int $number,
        public readonly string $path,
        public readonly string $query,
        public readonly array $headers = [],
    ) {}

    /**
     * The form a connector receives.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'number' => $this->number,
            'path' => $this->path,
            'query' => $this->query,
            'headers' => $this->headers,
        ];
    }
}
