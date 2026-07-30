<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Backup\BackupRejectedException;
use App\Domain\Backup\BackupService;
use App\Models\BackupArtifact;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Decrypts a stored artifact to a local file.
 *
 * A console command rather than a download button, and the reasoning is worth stating because the
 * button is what people expect.
 *
 * Decrypting a multi-gigabyte artifact through a web request means a PHP-FPM worker held for minutes
 * against a timeout it will probably lose, and a half-written file at the other end with no way to tell
 * it apart from a complete one. A command has no timeout, streams in bounded chunks, verifies the
 * checksum recorded when the backup was taken, and can be run on the machine that needs the file. The
 * interface shows the checksum and the command to run instead of offering something that works until
 * the database is big enough to matter.
 *
 * Restore is deliberately not implemented — see docs/backup.md. This hands over a verified plaintext
 * dump; what happens next is a decision with its own threat model, and a `--restore` flag would be a
 * way of pretending otherwise.
 */
final class FetchBackupCommand extends Command
{
    protected $signature = 'manager:backups:fetch
                            {artifact : The artifact identifier}
                            {destination : Where to write the decrypted backup}
                            {--force : Overwrite the destination if it exists}';

    protected $description = 'Decrypt a stored backup artifact to a local file';

    public function handle(BackupService $backups, AuditRecorder $audit): int
    {
        $artifact = BackupArtifact::query()
            ->with(['site', 'organisation'])
            ->where('external_id', $this->argument('artifact'))
            ->first();

        if ($artifact === null) {
            $this->components->error('No artifact with that identifier.');

            return self::FAILURE;
        }

        if (! $artifact->isRetrievable()) {
            $this->components->error('That artifact is not available.');
            $this->line('  State: '.$artifact->state.($artifact->deleted_reason !== null ? ' ('.$artifact->deleted_reason.')' : ''));

            return self::FAILURE;
        }

        $destination = (string) $this->argument('destination');

        if (is_file($destination) && ! $this->option('force')) {
            $this->components->error('That file already exists. Pass --force to overwrite it.');

            return self::FAILURE;
        }

        $this->components->info("Decrypting {$artifact->external_id}");
        $this->line('  Site:     '.$artifact->site->name);
        $this->line('  Taken:    '.$artifact->taken_at->toDateTimeString());
        $this->line('  Size:     '.$artifact->humanSize());
        $this->line('  Engine:   '.($artifact->engine ?? 'unknown').' '.($artifact->engine_version ?? ''));
        $this->newLine();

        $output = fopen($destination, 'wb');

        if ($output === false) {
            throw new RuntimeException('Could not open the destination for writing.');
        }

        try {
            $result = $backups->readArtifactTo($artifact, $output);
        } catch (BackupRejectedException $e) {
            fclose($output);

            // Removed, because a partially written file that looks like a backup is worse than no file.
            // Somebody restoring in a hurry at 2am will not check.
            @unlink($destination);

            $this->components->error($e->getMessage());
            $this->line('  Nothing was written.');

            return self::FAILURE;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
        }

        // Reading a backup is reading every user record on a site, so it belongs in the audit log as
        // much as taking one does.
        $audit->record(
            action: 'backup.retrieved',
            site: $artifact->site,
            actorType: 'system',
            actorLabel: 'Console',
            targetType: 'backup_artifact',
            targetId: $artifact->external_id,
            after: [
                'plaintext_bytes' => $result['plaintext_bytes'],
                'plaintext_sha256' => $result['plaintext_sha256'],
            ],
        );

        $this->components->info('Decrypted and verified.');
        $this->line('  Written to: '.$destination);
        $this->line('  Checksum:   '.$result['plaintext_sha256']);
        $this->newLine();
        $this->components->warn(
            'This file is the site\'s entire database in plain text, including user accounts and '
            .'password hashes. Delete it when you are done with it.'
        );

        return self::SUCCESS;
    }
}
