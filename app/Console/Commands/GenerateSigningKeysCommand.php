<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Connector\PlatformKeypair;
use coyshdigital\managerprotocol\Keys;
use Illuminate\Console\Command;

/**
 * Mints the platform's Ed25519 identity.
 *
 * Run once during installation. Rotating it invalidates every connector's stored copy of the
 * platform public key, so connectors have to be told the new one — which is why this refuses to
 * overwrite an existing key without being asked twice.
 */
final class GenerateSigningKeysCommand extends Command
{
    protected $signature = 'manager:keys:generate
                            {--force : Replace an existing keypair}
                            {--show : Print the keypair instead of writing it to .env}';

    protected $description = 'Generate the platform signing keypair';

    public function handle(PlatformKeypair $keypair): int
    {
        if ($keypair->isConfigured() && ! $this->option('force')) {
            $this->components->error('A signing keypair is already configured.');
            $this->line('  Rotating it will stop every connector trusting this platform until each has the new public key.');
            $this->line('  Re-run with --force only after reading the key-rotation runbook in docs/security.md.');

            return self::FAILURE;
        }

        $generated = Keys::generateKeypair();

        if ($this->option('show')) {
            $this->line('MANAGER_SIGNING_PUBLIC_KEY='.$generated['public']);
            $this->line('MANAGER_SIGNING_SECRET_KEY='.$generated['secret']);

            return self::SUCCESS;
        }

        $this->writeToEnvironmentFile($generated);

        $this->components->info('Platform signing keypair generated.');
        $this->line('  Public key: '.$generated['public']);
        $this->newLine();
        $this->components->warn('The secret key was written to .env and is not shown here. Back it up with your other application secrets: losing it means re-pairing every site.');

        return self::SUCCESS;
    }

    /**
     * @param  array{public: string, secret: string}  $keypair
     */
    private function writeToEnvironmentFile(array $keypair): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->components->warn('No .env file found; printing instead.');
            $this->line('MANAGER_SIGNING_PUBLIC_KEY='.$keypair['public']);
            $this->line('MANAGER_SIGNING_SECRET_KEY='.$keypair['secret']);

            return;
        }

        $contents = (string) file_get_contents($path);

        foreach (['MANAGER_SIGNING_PUBLIC_KEY' => $keypair['public'], 'MANAGER_SIGNING_SECRET_KEY' => $keypair['secret']] as $name => $value) {
            $line = $name.'="'.$value.'"';

            $contents = preg_match('~^'.$name.'=.*$~m', $contents) === 1
                ? (string) preg_replace('~^'.$name.'=.*$~m', $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }
}
