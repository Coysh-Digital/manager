<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupKeypair;
use App\Models\BackupArtifact;
use coyshdigital\managerprotocol\Sealing;
use Illuminate\Console\Command;

/**
 * Mints the platform's X25519 encryption identity.
 *
 * Run once during installation, before the first backup. Separate from the signing keypair because
 * using one keypair for both signing and encryption weakens both.
 *
 * Rotating this is destructive in a way rotating the signing key is not: every artifact already stored
 * was encrypted to a key wrapped for the old pair, and no new pair will open it. So this refuses when
 * artifacts exist, and says what would be lost.
 */
final class GenerateBackupKeysCommand extends Command
{
    protected $signature = 'manager:backups:keygen
                            {--force : Replace an existing keypair}
                            {--show : Print the keypair instead of writing it to .env}';

    protected $description = 'Generate the platform backup encryption keypair';

    public function handle(BackupKeypair $keypair): int
    {
        if ($keypair->isConfigured() && ! $this->option('force')) {
            $stored = BackupArtifact::query()->stored()->count();

            $this->components->error('A backup encryption keypair is already configured.');

            if ($stored > 0) {
                // Said in terms of what is lost, not in terms of the flag being refused. An operator
                // reaching for --force deserves to know the number before they type it.
                $this->line("  {$stored} stored ".($stored === 1 ? 'artifact was' : 'artifacts were')
                    .' encrypted to it. Replacing the key makes '
                    .($stored === 1 ? 'it' : 'them').' permanently unreadable.');
            }

            $this->line('  Read the key-rotation section of docs/backup.md before using --force.');

            return self::FAILURE;
        }

        $generated = Sealing::generateBoxKeypair();

        if ($this->option('show')) {
            $this->line('MANAGER_BACKUP_PUBLIC_KEY='.$generated['public']);
            $this->line('MANAGER_BACKUP_SECRET_KEY='.$generated['secret']);

            return self::SUCCESS;
        }

        $persisted = $this->writeToEnvironmentFile($generated);

        $this->components->info('Backup encryption keypair generated.');

        if ($persisted) {
            $this->line('  Public key: '.$generated['public']);
            $this->newLine();
            $this->components->warn(
                'The secret key was written to .env and is not shown here. Back it up somewhere other '
                .'than alongside the backups themselves: losing it makes every stored artifact '
                .'unreadable, and keeping it next to them would defeat the point of encrypting them.'
            );

            return self::SUCCESS;
        }

        // As above: saying nothing was saved matters more here than anywhere. A lost backup key does
        // not announce itself — every backup still appears to succeed, and none of them can be opened.
        $this->line('  Nothing was written: there is no writable .env here, which is normal in a');
        $this->line('  container. Add both lines to the environment Manager starts with, then restart it.');
        $this->newLine();
        $this->line('MANAGER_BACKUP_PUBLIC_KEY='.$generated['public']);
        $this->line('MANAGER_BACKUP_SECRET_KEY='.$generated['secret']);
        $this->newLine();
        $this->components->warn(
            'This is the only time the secret key is shown. Store it somewhere other than alongside the '
            .'backups: losing it makes every stored artifact permanently unreadable, and there is no '
            .'recovery path.'
        );

        return self::SUCCESS;
    }

    /**
     * @param  array{public: string, secret: string}  $keypair
     */
    private function writeToEnvironmentFile(array $keypair): bool
    {
        $path = base_path('.env');

        // No .env is the normal case in a container: the environment arrives from the compose
        // env_file and the root filesystem is read-only. The keys are printed so the operator can put
        // them where they belong, and the caller is told not to claim they were saved.
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        foreach (['MANAGER_BACKUP_PUBLIC_KEY' => $keypair['public'], 'MANAGER_BACKUP_SECRET_KEY' => $keypair['secret']] as $name => $value) {
            $line = $name.'="'.$value.'"';

            $contents = preg_match('~^'.$name.'=.*$~m', $contents) === 1
                ? (string) preg_replace('~^'.$name.'=.*$~m', $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        // Checked, because a failed write that reports success is how a signing key gets lost and
        // every site ends up needing to be paired again.
        return file_put_contents($path, $contents) !== false;
    }
}
