<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use RuntimeException;

/**
 * A part that would leave a hole in the staging file.
 *
 * Separate from {@see BackupRejectedException} because it is not the same kind of answer. A rejection
 * says the artifact is wrong and the upload is over; this says only that the *sequence* is wrong, and
 * carries where to continue from - so a connector that lost track of its position resumes rather than
 * sending a twenty-gigabyte database again from the beginning.
 *
 * That is why it is worth a class of its own rather than a status code. The resume point is the whole
 * content of the message, and a refusal without it would turn a dropped connection into a repeat of
 * everything before it.
 */
final class BackupPartOutOfOrderException extends RuntimeException
{
    public function __construct(
        public readonly int $resumeFromPart,
        public readonly int $receivedBytes,
    ) {
        parent::__construct('that part does not continue from what has arrived');
    }
}
