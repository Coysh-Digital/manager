<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Illuminate\Support\Carbon;

/**
 * Permission to write one object, to one place, for a short while.
 *
 * Note what this carries and - much more importantly - what it does not. There is a path, a query
 * string, a set of headers the request must send, an expiry and a size ceiling. **There is no host and
 * no scheme.**
 *
 * That absence is the whole design. A grant is an instruction from the platform to a site about where
 * to put a copy of a customer's database, which makes it exactly the kind of value the connector has
 * always refused to accept. The connector builds the URL as `https://` plus a host from its own
 * `config/manager-connector.php` plus what arrived here, so a compromised platform can vary the path
 * within a bucket the site operator already approved and can do nothing else at all.
 *
 * That is stronger than sending a host and checking it against an allow-list, because there is no
 * comparison to get wrong: no platform-supplied host is used even as an input to one. A field named
 * `host` on this class would quietly undo the property, which is why one of the connector's build
 * checks looks for exactly that.
 *
 * The query string is a bearer credential. Anybody holding it can write that object until it expires.
 * It must never reach an audit row, a log line or a job payload, and there is an invariant test that
 * looks for it in all three.
 */
final class UploadGrant
{
    /**
     * @param  string  $path  absolute object path, beginning with a slash
     * @param  string  $query  presigned query string, without the leading question mark
     * @param  array<string, string>  $headers  headers the upload must send for the signature to hold
     * @param  list<UploadPart>  $parts  when the artifact is too large for one request; empty otherwise
     * @param  string|null  $reference  the store's own handle for a multipart upload, never sent out
     */
    public function __construct(
        public readonly string $path,
        public readonly string $query,
        public readonly array $headers,
        public readonly Carbon $expiresAt,
        public readonly int $maxBytes,
        public readonly string $storageKey,
        public readonly array $parts = [],
        public readonly int $partBytes = 0,
        public readonly ?string $reference = null,
    ) {}

    /**
     * The form a connector receives, on the signed claim response.
     *
     * `parts` and `part_bytes` appear only when there are parts, so a grant for an ordinary artifact
     * is byte-identical to what this produced before multipart existed - which matters because a
     * connector too old to understand them is the common case and must see nothing new.
     *
     * `reference` is never here. It is the store's handle for the upload in progress, this platform
     * completes the upload itself, and a connector has no use for one.
     *
     * @return array<string, mixed>
     */
    public function toPayload(string $jobExternalId): array
    {
        $payload = [
            'job_id' => $jobExternalId,
            'path' => $this->path,
            'query' => $this->query,
            'headers' => $this->headers,
            'expires_at' => $this->expiresAt->getTimestamp(),
            'max_bytes' => $this->maxBytes,
        ];

        if ($this->parts !== []) {
            $payload['part_bytes'] = $this->partBytes;
            $payload['parts'] = array_map(static fn (UploadPart $part): array => $part->toPayload(), $this->parts);
        }

        return $payload;
    }
}
